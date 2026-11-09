<?php
// tools/send-booking-email.php
// Asynchronously dispatches booking notification emails without blocking the web UI.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$bookingId = (int)($argv[1] ?? 0);
$status = (string)($argv[2] ?? '');

if ($bookingId <= 0 || !in_array($status, ['approved', 'declined', 'active', 'rejected'], true)) {
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';

try {
    $pdo = database();
    send_booking_decision_notification($pdo, $bookingId, $status);
    send_landlord_booking_decision_confirmation($pdo, $bookingId, $status);
} catch (Throwable $e) {
    error_log('Async booking email failed: ' . $e->getMessage());
}

