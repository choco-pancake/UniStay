<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/notification_preferences.php';
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];

$sessionAccount = api_user();
$userId = (int)$sessionAccount['id'];

$pdo = db();

$role = (string)$sessionAccount['role'];
if (empty($_SESSION['notification_csrf'])) $_SESSION['notification_csrf'] = bin2hex(random_bytes(32));

if ($method === 'GET' && ($_GET['action'] ?? '') === 'preferences') {
    try {
        ok(['ok' => true, 'preferences' => get_notification_preferences($pdo, $userId, $role),
            'user_id' => $userId, 'role' => $role, 'csrf_token' => $_SESSION['notification_csrf']]);
    } catch (Throwable $error) {
        error_log('Notification preference read failed: ' . $error->getMessage());
        fail('Notification preferences are temporarily unavailable.', 503);
    }
}

if ($method === 'GET') {
    $list = get_user_in_app_notifications($pdo, $userId, 50);
    $unreadCount = get_user_unread_notification_count($pdo, $userId);
    $pendingRequests = 0;
    if ($role === 'landlord') {
        $pendingRequests = get_landlord_pending_requests_count($pdo, $userId);
    }
    ok([
        'ok' => true,
        'notifications' => $list,
        'unread_count' => $unreadCount,
        'pending_requests_count' => $pendingRequests
    ]);
}

if ($method === 'POST') {
    $data = body();
    if (empty($data)) {
        $data = $_POST;
    }

    $action = trim((string)($data['action'] ?? ''));

    if ($action === 'save_preferences') {
        if (!hash_equals($_SESSION['notification_csrf'], (string)($data['csrf_token'] ?? ''))) fail('Please refresh and try again.', 403);
        if (!is_array($data['preferences'] ?? null)) fail('Preferences must be an object.');
        try {
            ok(['ok' => true, 'preferences' => save_notification_preferences($pdo, $userId, $role, $data['preferences'])]);
        } catch (InvalidArgumentException $error) {
            fail($error->getMessage());
        } catch (Throwable $error) {
            error_log('Notification preference save failed: ' . $error->getMessage());
            fail('Preferences could not be saved.', 503);
        }
    }
    if ($action === 'delete' || $action === 'mark_unread') {
        $notifId = (int)($data['id'] ?? 0);
        if ($notifId <= 0) fail('Invalid notification.');
        $sql = $action === 'delete' ? 'DELETE FROM notifications WHERE id = ? AND user_id = ?'
            : 'UPDATE notifications SET is_read = 0 WHERE id = ? AND user_id = ?';
        $pdo->prepare($sql)->execute([$notifId, $userId]);
        ok(['ok' => true]);
    }
    if ($action === 'mark_read') {
        $notifId = (int)($data['id'] ?? 0);
        if ($notifId > 0) {
            mark_in_app_notification_read($pdo, $userId, $notifId);
        }
        $unreadCount = get_user_unread_notification_count($pdo, $userId);
        ok(['ok' => true, 'unread_count' => $unreadCount]);
    }

    if ($action === 'mark_all_read') {
        mark_all_in_app_notifications_read($pdo, $userId);
        ok(['ok' => true, 'unread_count' => 0]);
    }

    if ($action === 'clear_all') {
        clear_user_in_app_notifications($pdo, $userId);
        ok(['ok' => true, 'unread_count' => 0, 'notifications' => []]);
    }

    fail('Invalid action.', 400);
}

fail('Method not allowed.', 405);
