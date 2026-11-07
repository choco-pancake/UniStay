<?php

/**
 * Sends the queued subscriber mail.
 *
 * Command line (recommended):
 *   C:\xampp\php\php.exe tools\send-mail-queue.php [limit]
 *
 * HTTP (for shared hosting without cron), set MAIL_WORKER_TOKEN first:
 *   https://your-site/tools/send-mail-queue.php?token=SECRET&limit=25
 *
 * A scheduled task that runs this every few minutes is enough.
 */

require_once __DIR__ . '/../config/subscribers.php';

$isCli = PHP_SAPI === 'cli' || isset($_SERVER['argv']);

if (!$isCli) {
    $settings = mail_settings();
    $expected = trim((string) ($settings['worker_token'] ?? ''));
    $given = trim((string) ($_GET['token'] ?? ''));

    header('Content-Type: application/json; charset=utf-8');

    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden. Set MAIL_WORKER_TOKEN and pass ?token=...']);
        exit;
    }
}

set_time_limit(0);

$limit = $isCli ? (int) ($argv[1] ?? 25) : (int) ($_GET['limit'] ?? 25);

try {
    $pdo = database();
    $stats = subscriber_queue_work($pdo, $limit);

    $payload = [
        'ok' => true,
        'transport' => mail_is_configured() ? 'smtp' : 'file (storage/mail)',
        'stats' => $stats,
        'after' => subscriber_stats($pdo),
    ];
} catch (Throwable $error) {
    error_log('UniStay mail queue worker failed: ' . $error->getMessage());
    http_response_code(500);

    $payload = [
        'ok' => false,
        'error' => 'The queue worker failed: ' . $error->getMessage(),
    ];
}

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}
