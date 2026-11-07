<?php

/**
 * Shared, dependency-free page for the public subscribe/confirm/unsubscribe
 * links. Mail clients open these links without a session, so nothing here
 * needs authentication.
 */
function subscriber_page(string $title, string $heading, string $message, bool $ok = true, string $actionHtml = ''): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $accent = $ok ? '#7a4b3a' : '#991b1b';
    $badge = $ok ? '&#10003;' : '!';

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>' . $safeTitle . ' · UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
        background: #fdfaf5;
        font-family: "DM Sans", -apple-system, BlinkMacSystemFont, sans-serif;
        color: #3b312c;
    }
    .card {
        width: 100%;
        max-width: 520px;
        background: #fff;
        border: 1px solid #d9c9bd;
        border-radius: 18px;
        box-shadow: 0 10px 30px rgba(80, 45, 30, 0.08);
        overflow: hidden;
    }
    .card-head {
        background: #7a4b3a;
        padding: 20px 28px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #fff;
    }
    .card-head img { width: 36px; height: 36px; border-radius: 8px; }
    .card-head span { font-family: Georgia, serif; font-size: 21px; font-weight: 700; letter-spacing: 0.4px; }
    .card-body { padding: 30px 28px 28px; }
    .badge {
        width: 44px; height: 44px; border-radius: 50%;
        display: grid; place-items: center;
        background: ' . ($ok ? '#f2e6de' : '#fee2e2') . ';
        color: ' . $accent . ';
        font-size: 22px; font-weight: 700;
        margin-bottom: 16px;
    }
    h1 { margin: 0 0 10px; font-family: Fraunces, Georgia, serif; font-size: 25px; line-height: 1.25; color: #1f1a17; }
    p { margin: 0 0 18px; font-size: 15px; line-height: 1.65; color: #5c4c43; }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
    .btn {
        display: inline-block; padding: 12px 24px; border: 0; border-radius: 10px;
        background: #7a4b3a; color: #fff; font: inherit; font-weight: 600;
        font-size: 14px; text-decoration: none; cursor: pointer;
    }
    .btn:hover { background: #633b2c; }
    .btn.secondary { background: #f5f1ec; color: #5f3425; border: 1px solid #e3d6cc; }
    .btn.secondary:hover { background: #ebdfd5; }
    .foot { padding: 16px 28px; background: #f5f1ec; border-top: 1px solid #ece1d8; font-size: 12px; color: #8c7b72; }
</style>
</head>
<body>
    <main class="card">
        <div class="card-head">
            <img src="../assets/images/logo.png" alt="UniStay logo">
            <span>UniStay</span>
        </div>
        <div class="card-body">
            <div class="badge">' . $badge . '</div>
            <h1>' . $safeHeading . '</h1>
            <p>' . $safeMessage . '</p>
            ' . $actionHtml . '
        </div>
        <div class="foot">UniStay · Student Accommodation &amp; Dormitory Management Platform</div>
    </main>
</body>
</html>';
}
