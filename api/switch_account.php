<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if ($method === 'GET') {
    $stmt = database()->query(
        "SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo, profile_completed 
         FROM users 
         ORDER BY FIELD(role, 'landlord', 'tenant', 'admin'), id ASC"
    );
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $accounts = [];
    foreach ($users as $u) {
        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        if ($name === '') {
            $name = (string)($u['personal_email'] ?? 'User');
        }
        
        $nameParts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        if (count($nameParts) > 0) {
            $firstInitial = mb_substr($nameParts[0], 0, 1);
            $lastInitial = count($nameParts) > 1 ? mb_substr($nameParts[count($nameParts) - 1], 0, 1) : '';
            $initials = mb_strtoupper($firstInitial . $lastInitial);
        }
        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr((string)($u['personal_email'] ?? 'U'), 0, 1));
        }

        $photo = resolve_user_profile_photo_url($u['profile_photo'] ?? null, $u['photo'] ?? null, (string)$u['role']);

        $role = (string)$u['role'];
        if ($role === 'admin') {
            $dest = '../admin/dashboard.php';
        } elseif ($role === 'landlord') {
            $dest = '../landlord/dashboard.php';
        } else {
            $dest = '../tenant/dorms.php';
        }

        $accounts[] = [
            'id' => (int)$u['id'],
            'name' => $name,
            'email' => (string)($u['personal_email'] ?? ''),
            'role' => $role,
            'photo' => $photo,
            'initials' => $initials,
            'is_current' => ((int)$u['id'] === $currentUserId),
            'destination' => $dest,
        ];
    }

    ok(['ok' => true, 'current_id' => $currentUserId, 'accounts' => $accounts]);
}

if ($method === 'POST') {
    $input = body();
    $targetId = (int)($input['user_id'] ?? $_POST['user_id'] ?? 0);

    if ($targetId <= 0) {
        fail('Valid target user ID is required.', 400);
    }

    $stmt = database()->prepare(
        "SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo, profile_completed 
         FROM users 
         WHERE id = ? 
         LIMIT 1"
    );
    $stmt->execute([$targetId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        fail('Target account not found.', 404);
    }

    // Switch session directly
    clear_authenticated_session(false);
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$account['id'];
    $_SESSION['role'] = (string)$account['role'];

    $fullName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = (string)($account['personal_email'] ?? 'User');
    }

    $photo = resolve_user_profile_photo_url($account['profile_photo'] ?? null, $account['photo'] ?? null, (string)$account['role']);

    $_SESSION['user'] = [
        'name' => $fullName,
        'email' => (string)$account['personal_email'],
        'photo' => $photo,
    ];

    $role = (string)$account['role'];
    if ($role === 'admin') {
        $dest = '../admin/dashboard.php';
    } elseif ($role === 'landlord') {
        $dest = '../landlord/dashboard.php';
    } else {
        $dest = '../tenant/dorms.php';
    }

    ok([
        'ok' => true,
        'message' => "Switched to {$fullName}",
        'redirect' => $dest,
        'user' => $_SESSION['user'],
    ]);
}

fail('Unsupported request method.', 405);

