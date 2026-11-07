<?php

require_once __DIR__ . '/../config/subscribers.php';
require_once __DIR__ . '/_page.php';

$token = trim((string) ($_GET['token'] ?? ''));
$result = [
    'ok' => false,
    'message' => 'This confirmation link is not valid or has already been used.',
];

if ($token !== '') {
    try {
        $pdo = database();
        subscribers_ensure_schema($pdo);
        $result = subscriber_confirm_by_token($pdo, $token);
    } catch (Throwable $error) {
        error_log('UniStay subscribe confirm failed: ' . $error->getMessage());
        $result = [
            'ok' => false,
            'message' => 'We could not confirm your subscription right now. Please try the link again in a moment.',
        ];
    }
}

$action = '<div class="actions">'
    . '<a class="btn" href="../index.php">Go to UniStay</a>';

if (!$result['ok']) {
    $action .= ' <a class="btn secondary" href="../index.php#sub">Subscribe again</a>';
}

$action .= '</div>';

subscriber_page(
    'Confirm subscription',
    $result['ok'] ? 'Subscription confirmed' : 'Confirmation link problem',
    (string) $result['message'],
    (bool) $result['ok'],
    $action
);
