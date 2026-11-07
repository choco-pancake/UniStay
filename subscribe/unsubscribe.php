<?php

require_once __DIR__ . '/../config/subscribers.php';
require_once __DIR__ . '/_page.php';

$token = trim((string) ($_REQUEST['token'] ?? ''));
$result = null;
$subscriber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    try {
        $pdo = database();
        subscribers_ensure_schema($pdo);
        $result = subscriber_unsubscribe_by_token($pdo, $token);
    } catch (Throwable $error) {
        error_log('UniStay unsubscribe failed: ' . $error->getMessage());
        $result = [
            'ok' => false,
            'message' => 'We could not process the unsubscribe request right now. Please try the link again in a moment.',
            'email' => '',
        ];
    }
} elseif ($token !== '') {
    try {
        $pdo = database();
        subscribers_ensure_schema($pdo);
        $subscriber = subscriber_find_by_token($pdo, 'unsubscribe_token', $token);
    } catch (Throwable $error) {
        error_log('UniStay unsubscribe lookup failed: ' . $error->getMessage());
    }
}

if ($result !== null) {
    subscriber_page(
        'Unsubscribe',
        $result['ok'] ? 'You are unsubscribed' : 'Unsubscribe link problem',
        (string) $result['message'],
        (bool) $result['ok'],
        '<div class="actions"><a class="btn" href="../index.php">Back to UniStay</a></div>'
    );
    return;
}

if ($subscriber === null) {
    subscriber_page(
        'Unsubscribe',
        'Unsubscribe link problem',
        'This unsubscribe link is not valid. If you still receive updates you can use the unsubscribe link at the bottom of any UniStay email.',
        false,
        '<div class="actions"><a class="btn" href="../index.php">Back to UniStay</a></div>'
    );
    return;
}

if ((string) $subscriber['status'] === 'unsubscribed') {
    subscriber_page(
        'Unsubscribe',
        'Already unsubscribed',
        'This address is already unsubscribed and will not receive further updates.',
        true,
        '<div class="actions"><a class="btn" href="../index.php">Back to UniStay</a></div>'
    );
    return;
}

$email = (string) $subscriber['email'];
$at = strpos($email, '@');
$local = $at === false ? $email : substr($email, 0, $at);
$domain = $at === false ? '' : substr($email, $at);
$masked = ($local !== '' ? substr($local, 0, 1) : '') . str_repeat('•', max(2, strlen($local) - 1)) . $domain;

$form = '<p>Stop updates for <strong>' . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . '</strong>?</p>'
    . '<form method="post" action="">'
    . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<div class="actions">'
    . '<button class="btn" type="submit">Unsubscribe me</button>'
    . '<a class="btn secondary" href="../index.php">Keep my subscription</a>'
    . '</div>'
    . '</form>';

subscriber_page(
    'Unsubscribe',
    'Unsubscribe from UniStay updates',
    'You will stop receiving promotional emails about UniStay. Account emails (such as password resets) are not affected.',
    true,
    $form
);
