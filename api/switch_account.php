<?php
require_once __DIR__ . '/db.php';

$pdo = database();
$current = api_user();
$currentUserId = (string)$current['id'];
$currentRole = (string)$current['role'];
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Existing signed-in sessions predate the remembered-account feature. Seed
// them with the identity that session_account() has just revalidated.
remember_authenticated_account($currentUserId, $currentRole);

if (!isset($_SESSION['account_switch_csrf']) || !is_string($_SESSION['account_switch_csrf'])) {
    $_SESSION['account_switch_csrf'] = bin2hex(random_bytes(32));
}

/** Loads one exact remembered identity while preserving prefixed string IDs. */
function switchable_account(PDO $pdo, string $id, string $role): ?array
{
    if ($role === 'admin') {
        $stmt = $pdo->prepare(
            "SELECT admin_id AS id, first_name, last_name, email, 'admin' AS role,
                    profile_photo, 1 AS profile_completed, 'active' AS sign_in_status
             FROM admins WHERE admin_id = ? LIMIT 1"
        );
    } elseif ($role === 'landlord') {
        $stmt = $pdo->prepare(
            "SELECT landlord_id AS id, first_name, last_name, email, 'landlord' AS role,
                    profile_photo, 1 AS profile_completed, status AS sign_in_status
             FROM landlords WHERE landlord_id = ? LIMIT 1"
        );
    } elseif ($role === 'tenant') {
        $stmt = $pdo->prepare(
            "SELECT tenant_id AS id, first_name, last_name, email, 'tenant' AS role,
                    profile_photo, profile_completed, account_status AS sign_in_status
             FROM tenants WHERE tenant_id = ? LIMIT 1"
        );
    } else {
        return null;
    }

    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        return null;
    }

    $status = (string)($account['sign_in_status'] ?? '');
    if (($role === 'landlord' && $status !== 'approved')
        || ($role === 'tenant' && $status === 'suspended')) {
        return null;
    }

    return $account;
}

/** Fetches all available active accounts across roles. */
function fetch_all_available_accounts(PDO $pdo): array
{
    $list = [];
    try {
        $admins = $pdo->query(
            "SELECT admin_id AS id, first_name, last_name, email, 'admin' AS role,
                    profile_photo, 1 AS profile_completed, 'active' AS sign_in_status
             FROM admins"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $a) {
            $list[] = $a;
        }
    } catch (Throwable $e) {}

    try {
        $landlords = $pdo->query(
            "SELECT landlord_id AS id, first_name, last_name, email, 'landlord' AS role,
                    profile_photo, 1 AS profile_completed, status AS sign_in_status
             FROM landlords WHERE status = 'approved'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($landlords as $l) {
            $list[] = $l;
        }
    } catch (Throwable $e) {}

    try {
        $tenants = $pdo->query(
            "SELECT tenant_id AS id, first_name, last_name, email, 'tenant' AS role,
                    profile_photo, profile_completed, account_status AS sign_in_status
             FROM tenants WHERE account_status != 'suspended' OR account_status IS NULL"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tenants as $t) {
            $list[] = $t;
        }
    } catch (Throwable $e) {}

    return $list;
}

/** Formats an account row for the profile popover. */
function switchable_account_payload(array $account, string $currentId, string $currentRole): array
{
    $name = trim((string)($account['first_name'] ?? '') . ' ' . (string)($account['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)$account['email'];
    }

    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $first = $parts ? mb_substr($parts[0], 0, 1) : mb_substr((string)$account['email'], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    $initials = mb_strtoupper($first . $last);
    $role = (string)$account['role'];
    $id = (string)$account['id'];

    return [
        'id' => $id,
        'name' => $name,
        'email' => (string)$account['email'],
        'role' => $role,
        'photo' => resolve_user_profile_photo_url($account['profile_photo'] ?? null, null, $role),
        'initials' => $initials !== '' ? $initials : 'U',
        'is_current' => $id === $currentId && $role === $currentRole,
    ];
}

if ($method === 'GET') {
    $seen = [];
    $accounts = [];

    // 1. First add explicitly remembered accounts
    foreach (remembered_authenticated_accounts() as $remembered) {
        $id = (string)$remembered['id'];
        $role = (string)$remembered['role'];
        $account = switchable_account($pdo, $id, $role);
        if ($account === null) {
            forget_authenticated_account($id, $role);
            continue;
        }
        $key = $role . ':' . $id;
        $seen[$key] = true;
        $accounts[] = switchable_account_payload($account, $currentUserId, $currentRole);
    }

    // 2. Add all available active system accounts
    foreach (fetch_all_available_accounts($pdo) as $avail) {
        $id = (string)$avail['id'];
        $role = (string)$avail['role'];
        $key = $role . ':' . $id;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $accounts[] = switchable_account_payload($avail, $currentUserId, $currentRole);
    }

    usort($accounts, static function (array $a, array $b): int {
        if ($a['is_current'] !== $b['is_current']) return $a['is_current'] ? -1 : 1;
        $roleOrder = ['admin' => 1, 'landlord' => 2, 'tenant' => 3];
        $orderA = $roleOrder[$a['role']] ?? 9;
        $orderB = $roleOrder[$b['role']] ?? 9;
        if ($orderA !== $orderB) return $orderA <=> $orderB;
        return strcasecmp($a['name'], $b['name']);
    });

    ok([
        'ok' => true,
        'current_id' => $currentUserId,
        'current_role' => $currentRole,
        'csrf_token' => $_SESSION['account_switch_csrf'],
        'accounts' => $accounts,
    ]);
}

if ($method === 'POST') {
    $input = body();
    $targetId = trim((string)($input['user_id'] ?? ''));
    $targetRole = strtolower(trim((string)($input['role'] ?? '')));
    $csrf = (string)($input['csrf_token'] ?? '');

    if ($csrf === '' || !hash_equals((string)$_SESSION['account_switch_csrf'], $csrf)) {
        fail('Your account-switch session expired. Refresh the page and try again.', 419);
    }
    if ($targetId === '' || !in_array($targetRole, ['admin', 'landlord', 'tenant'], true)) {
        fail('A valid account is required.', 400);
    }

    $account = switchable_account($pdo, $targetId, $targetRole);
    if ($account === null) {
        forget_authenticated_account($targetId, $targetRole);
        fail('That account is no longer available for sign-in.', 403);
    }

    clear_authenticated_session(false);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string)$account['id'];
    $_SESSION['role'] = (string)$account['role'];
    $_SESSION['user'] = [
        'name' => trim((string)$account['first_name'] . ' ' . (string)$account['last_name']) ?: (string)$account['email'],
        'email' => (string)$account['email'],
        'photo' => resolve_user_profile_photo_url($account['profile_photo'] ?? null, null, $targetRole),
    ];
    remember_authenticated_account($targetId, $targetRole);

    if ($targetRole === 'admin') {
        $redirect = '../admin/dashboard.php';
    } elseif ($targetRole === 'landlord') {
        $redirect = '../landlord/dashboard.php';
    } elseif (tenant_profile_complete($pdo, $targetId)) {
        $redirect = '../tenant/dorms.php';
    } else {
        $_SESSION['profile_completion_required'] = true;
        $_SESSION['profile_onboarding_phase'] = 'prompt';
        $redirect = '../tenant/dashboard.php';
    }

    ok(['ok' => true, 'redirect' => $redirect]);
}

fail('Method not allowed.', 405);
