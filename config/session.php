<?php

/**
 * Session helpers shared by the auth pages and the dashboards.
 */

/**
 * Starts the application session if it is not running yet.
 */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

/**
 * Removes every piece of authenticated state from the current session.
 * The session itself is kept alive when requested so public pages can still
 * use CSRF tokens and flash state after an invalid account is detected.
 */
function clear_authenticated_session(bool $destroySession = false): void
{
    start_app_session();
    foreach ([
        'user', 'user_id', 'role', 'profile_modal_pending',
        'is_new_tenant_registration', 'show_profile_completion_toast',
        'is_new_landlord_registration', 'profile_completion_required',
        'profile_onboarding_phase', 'start_tenant_tour', 'start_landlord_tour',
        'notification_csrf'
    ] as $key) {
        unset($_SESSION[$key]);
    }

    if (!$destroySession) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Returns the database row backing the current session, or null. */
function session_account(): ?array
{
    start_app_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_numeric($userId) || (int)$userId <= 0) {
        return null;
    }

    if (!function_exists('database')) {
        require_once __DIR__ . '/database.php';
    }

    try {
        $statement = database()->prepare(
            'SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo, profile_completed, created_at
             FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([(int)$userId]);
        $account = $statement->fetch();
    } catch (Throwable $error) {
        // A temporary database failure must not silently impersonate or replace a user.
        error_log('UniStay session validation failed: ' . $error->getMessage());
        return null;
    }

    $sessionRole = (string)($_SESSION['role'] ?? '');
    if (!$account || $sessionRole === '' || !hash_equals((string)$account['role'], $sessionRole)) {
        clear_authenticated_session();
        return null;
    }

    return $account;
}

/**
 * Returns the signed-in user from the session, or null when nobody is signed in.
 */
function session_user(): ?array
{
    $account = session_account();
    if ($account === null) {
        return null;
    }

    $name = trim((string)($account['first_name'] ?? '') . ' ' . (string)($account['last_name'] ?? ''));
    $user = [
        'id' => (int)$account['id'],
        'name' => $name !== '' ? $name : (string)$account['personal_email'],
        'email' => (string)$account['personal_email'],
        'photo' => profile_photo_url((string)($account['profile_photo'] ?: ($account['photo'] ?? ''))),
        'role' => (string)$account['role'],
        'profile_completed' => (int)($account['profile_completed'] ?? 0),
        'created_at' => (string)($account['created_at'] ?? ''),
    ];
    $_SESSION['user'] = $user;

    return $user;
}

/**
 * Returns the signed-in user only when their role matches, otherwise null.
 */
function session_user_with_role(string $role): ?array
{
    $user = session_user();

    if ($user === null || ($_SESSION['role'] ?? null) !== $role) {
        return null;
    }

    return $user;
}

/** Sends incomplete tenant/landlord accounts through the required setup flow. */
function enforce_profile_completion(array $user): void
{
    $role = (string)($user['role'] ?? $_SESSION['role'] ?? '');
    if (!in_array($role, ['tenant', 'landlord'], true)) {
        return;
    }

    if (!function_exists('tenant_profile_complete')) {
        require_once __DIR__ . '/database.php';
    }
    $userId = (int)($user['id'] ?? 0);
    $complete = $role === 'tenant'
        ? tenant_profile_complete(database(), $userId)
        : landlord_profile_complete(database(), $userId);
    if ($complete) {
        unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
        return;
    }

    $_SESSION['profile_completion_required'] = true;
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dashboardSuffix = '/' . $role . '/dashboard.php';
    $settingsSuffix = '/' . $role . '/settings.php';

    if (str_ends_with($script, '/auth/logout.php')) return;

    if (str_ends_with($script, $settingsSuffix)) {
        $_SESSION['profile_onboarding_phase'] = 'editing';
        return;
    }

    $phase = (string)($_SESSION['profile_onboarding_phase'] ?? 'prompt');
    if ($phase === 'editing') {
        $settingsQuery = $role === 'tenant' ? '?edit=1&setup=1' : '?mode=edit&setup=1';
        header('Location: ../' . $role . '/settings.php' . $settingsQuery, true, 303);
        exit;
    }

    if (str_ends_with($script, $dashboardSuffix)) return;

    $_SESSION['profile_onboarding_phase'] = 'prompt';
    header('Location: ../' . $role . '/dashboard.php', true, 303);
    exit;
}

/**
 * Sends the visitor to the sign-in page and stops the request.
 */
function require_signed_in(string $role): array
{
    $user = session_user_with_role($role);

    if ($user === null) {
        header('Location: ../auth/login.php', true, 303);
        exit;
    }

    enforce_profile_completion($user);

    return $user;
}

function profile_photo_url(?string $stored): string
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (preg_match('#^(https?:)?//|^data:#i', $stored)) return $stored; // Google / external
    if ($stored[0] === '/') return $stored;
    if (!function_exists('app_url')) require_once __DIR__ . '/app.php';
    // Tenant uploads are saved relative to /tenant/
    return app_url('/tenant/' . ltrim($stored, '/'));
}
