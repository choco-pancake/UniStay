<?php
if (PHP_SAPI !== 'cli') exit(1);
$userId = (string)($argv[1] ?? '');
$status = (string)($argv[2] ?? '');
$role = (string)($argv[3] ?? '');
if ($userId === '' || !in_array($status, ['verified', 'rejected'], true) || !in_array($role, ['tenant', 'landlord'], true)) exit(1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';
try {
    $pdo = database();
    $stmt = $pdo->prepare("SELECT status, feedback FROM landlords WHERE landlord_id = ? LIMIT 1");
    if ($role === 'tenant') $stmt = $pdo->prepare("SELECT verification_status AS status, feedback FROM tenants WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    $expected = $status === 'verified' ? ($role === 'landlord' ? 'approved' : 'verified') : 'declined';
    if (!$record || $record['status'] !== $expected) exit(0);
    send_verification_decision_notification($pdo, $userId, $status, $record['feedback'] ?? null, $role);
} catch (Throwable $e) {
    error_log('Async verification email failed: ' . $e->getMessage());
}
