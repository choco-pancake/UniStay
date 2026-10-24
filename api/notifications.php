<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/notifications.php';

$method = $_SERVER['REQUEST_METHOD'];

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    fail('Please log in to view or manage notifications.', 401);
}

$pdo = db();

if ($method === 'GET') {
    $list = get_user_in_app_notifications($pdo, $userId, 50);
    $unreadCount = get_user_unread_notification_count($pdo, $userId);
    ok([
        'ok' => true,
        'notifications' => $list,
        'unread_count' => $unreadCount
    ]);
}

if ($method === 'POST') {
    $data = body();
    if (empty($data)) {
        $data = $_POST;
    }

    $action = trim((string)($data['action'] ?? ''));

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
