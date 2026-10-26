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
 * Returns the signed-in user from the session, or null when nobody is signed in.
 */
function session_user(): ?array
{
    start_app_session();

    $user = $_SESSION['user'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (!is_array($user) || !is_numeric($userId)) {
        return null;
    }

    $user['id'] = (int) $userId;

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

    return $user;
}
