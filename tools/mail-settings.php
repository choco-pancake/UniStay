<?php

/**
 * Local page to set up the mail server of the app.
 *
 * Open it on this computer: http://localhost/unistay/tools/mail-settings.php
 * The page only works from localhost, it writes the SMTP credentials to
 * config/mail.local.php, which is kept out of the repository.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

$remoteAddress = strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$isLocal = in_array($remoteAddress, ['127.0.0.1', '::1', 'localhost'], true);

if (!$isLocal) {
    http_response_code(403);
    exit('This page can only be opened on this computer.');
}

$notice = '';
$noticeClass = 'notice';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $saved = mail_save_settings([
            'host' => (string) ($_POST['host'] ?? ''),
            'port' => (int) ($_POST['port'] ?? 587),
            'encryption' => (string) ($_POST['encryption'] ?? 'tls'),
            'username' => (string) ($_POST['username'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'from' => (string) ($_POST['from'] ?? ''),
            'from_name' => (string) ($_POST['from_name'] ?? 'UniStay'),
            'admin_recipient' => (string) ($_POST['admin_recipient'] ?? 'unistaysads@gmail.com'),
        ]);

        if ($saved) {
            $notice = 'The mail settings were saved. The app can send mail from now on.';
        } else {
            $noticeClass = 'alert';
            $notice = 'The settings were not saved. The address, the app password and the server are needed.';
        }
    }

    if ($action === 'test') {
        $recipient = trim((string) ($_POST['email'] ?? ''));

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $noticeClass = 'alert';
            $notice = 'Enter the address the test mail should go to.';
        } elseif (!mail_is_configured()) {
            $noticeClass = 'alert';
            $notice = 'There is no mail server configured yet, fill in the form above first.';
        } else {
            $promoHeading = 'Exclusive Campus Living & Special Student Dorm Deals';
            $promoParagraphs = [
                'Hello from UniStay!',
                "Thank you for connecting with UniStay. Whether you're attending Universidad de Dagupan, University of Pangasinan, University of Luzon, or Lyceum Northwestern University, we've got you covered with premium student dormitories at exclusive discounted semester rates.",
                "Find your ideal home away from home within easy walking distance to your campus. Browse verified dorm rooms, compare rates, and reserve safely with zero hidden broker fees.",
            ];
            $promoBullets = [
                '🔥 Exclusive student discounts and flexible semester rental terms',
                '📍 Walking distance radius map to your university and study hubs',
                '⚡ 100% verified dorm landlords and accredited student facilities',
                '🛡️ High-speed Wi-Fi, air-conditioned options, and 24/7 CCTV security',
            ];
            $promoButtonText = 'Explore Promotional Dorms';
            $promoButtonUrl = app_url('tenant/dorms.php');

            $plainBody = "UniStay Promo: " . $promoHeading . "\n\n"
                . implode("\n\n", $promoParagraphs) . "\n\n"
                . "• " . implode("\n• ", $promoBullets) . "\n\n"
                . $promoButtonText . ": " . $promoButtonUrl . "\n\n"
                . "UniStay · Student Accommodation & Dormitory Management Platform\n";

            $htmlBody = mail_brand_email([
                'heading' => $promoHeading,
                'preheader' => 'Special promo: Discover premium dorms near your campus with verified student discounts.',
                'paragraphs' => $promoParagraphs,
                'bullets' => $promoBullets,
                'button_text' => $promoButtonText,
                'button_url' => $promoButtonUrl,
                'footer_note' => "You're receiving this promotional update because you connected with UniStay.",
                'unsubscribe_url' => app_url('index.php'),
                'privacy_url' => app_url('pages/privacy-policy.php'),
                'logo_url' => app_url('assets/images/logo.png'),
            ]);

            if (send_mail($recipient, '🔥 Exclusive Student Promo: Campus Dorm Deals & Discounts · UniStay', $plainBody, $htmlBody)) {
                $notice = 'The promotional ad was sent to ' . $recipient . ', check that inbox.';
            } else {
                $noticeClass = 'alert';
                $notice = 'The promotional email could not be sent, the reason is in the PHP error log.';
            }
        }
    }
}

$settings = mail_settings();
$gmailPasswordLooksWrong = mail_is_configured()
    && stripos((string) $settings['host'], 'gmail') !== false
    && strlen((string) $settings['password']) !== 16;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UniStay mail settings</title>
    <style>
        :root {
            --cream: #FDFAF5;
            --brown: #7a4b3a;
            --brown-dark: #5f3425;
            --ink: #1f1a17;
            --text: #3b312c;
            --field-bg: #f5f1ec;
            --field-line: #d9c9bd;
            --muted: #9a8c84;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, sans-serif;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border: 1px solid var(--field-line);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(31, 26, 23, 0.08);
        }

        h1 { margin: 0 0 6px; font-size: 22px; color: var(--ink); }
        p.hint { margin: 0 0 18px; font-size: 13px; color: var(--muted); line-height: 1.5; }
        p.hint a { color: var(--brown-dark); }

        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
        .field input {
            width: 100%;
            padding: 11px 13px;
            font-size: 14px;
            border: 1px solid var(--field-line);
            border-radius: 10px;
            background: var(--field-bg);
            color: var(--text);
        }

        .row { display: flex; gap: 12px; }
        .row .field { flex: 1; }

        button {
            width: 100%;
            padding: 12px 16px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: var(--brown);
            border: 1px solid var(--brown);
            border-radius: 10px;
            cursor: pointer;
            transition: 0.2s;
        }

        button:hover { background: var(--brown-dark); border-color: var(--brown-dark); }
        button.secondary { background: #fff; color: var(--brown-dark); }
        button.secondary:hover { background: var(--field-bg); }

        .notice, .alert {
            padding: 11px 13px;
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 16px;
        }

        .notice { background: #eef6ee; border: 1px solid #cfe3cf; color: #2f5a35; }
        .alert { background: #fdeeee; border: 1px solid #f2cfcf; color: #8a2f2f; }

        .state { font-size: 13px; margin-bottom: 16px; }
        .state strong { color: var(--ink); }
        .divider { height: 1px; background: var(--field-line); margin: 22px 0; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Mail settings</h1>
        <p class="hint">
            Password resets and landlord application alerts are sent through this mail server.
            For Gmail: turn on 2-Step Verification, then create an app password at
            <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer">myaccount.google.com/apppasswords</a>
            and paste it below. The spaces in the app password are removed automatically.
        </p>

        <p class="state">
            <strong>Status:</strong>
            <?php if (mail_is_configured()): ?>
                ready, mail is sent through <?= htmlspecialchars($settings['host'], ENT_QUOTES, 'UTF-8') ?>
                as <?= htmlspecialchars($settings['username'], ENT_QUOTES, 'UTF-8') ?>.
            <?php else: ?>
                not set up yet, the reset link is shown on the page instead.
            <?php endif; ?>
        </p>
        <p class="state"><strong>Landlord application recipient:</strong> <?= htmlspecialchars($settings['admin_recipient'], ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($gmailPasswordLooksWrong): ?>
            <p class="alert">
                The saved password is <?= strlen((string) $settings['password']) ?> characters long.
                Gmail App Passwords are exactly 16 characters, and a normal Gmail password is always rejected by SMTP.
                Create one at
                <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer">myaccount.google.com/apppasswords</a>
                and save it above.
            </p>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <p class="<?= $noticeClass === 'alert' ? 'alert' : 'notice' ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="POST" action="mail-settings.php">
            <input type="hidden" name="action" value="save">

            <div class="field">
                <label for="username">Sending address</label>
                <input type="email" id="username" name="username" placeholder="youraddress@gmail.com"
                       value="<?= htmlspecialchars($settings['username'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="field">
                <label for="password">App password</label>
                <input type="password" id="password" name="password" placeholder="xxxx xxxx xxxx xxxx" required>
            </div>

            <div class="field">
                <label for="admin_recipient">Admin notification email</label>
                <input type="email" id="admin_recipient" name="admin_recipient" value="<?= htmlspecialchars($settings['admin_recipient'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="row">
                <div class="field">
                    <label for="host">Server</label>
                    <input type="text" id="host" name="host" value="<?= htmlspecialchars($settings['host'] !== '' ? $settings['host'] : 'smtp.gmail.com', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="field">
                    <label for="port">Port</label>
                    <input type="number" id="port" name="port" value="<?= (int) ($settings['port'] !== 0 ? $settings['port'] : 587) ?>" required>
                </div>

                <div class="field">
                    <label for="encryption">Encryption</label>
                    <input type="text" id="encryption" name="encryption" value="<?= htmlspecialchars($settings['encryption'] !== '' ? $settings['encryption'] : 'tls', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>

            <button type="submit">Save the mail settings</button>
        </form>

        <div class="divider"></div>

        <form method="POST" action="mail-settings.php">
            <input type="hidden" name="action" value="test">

            <div class="field">
                <label for="email">Send a test mail to</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($settings['admin_recipient'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit" class="secondary">Send the test mail</button>
        </form>
    </main>
</body>
</html>
