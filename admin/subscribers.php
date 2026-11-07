<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/subscribers.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');
subscribers_ensure_schema($pdo);

$successMessage = '';
$errorMessage = '';

$defaultCta = app_url('index.php');

$defaultTestEmail = trim((string) (mail_settings()['admin_recipient'] ?? ''));
if ($defaultTestEmail === '') {
    $defaultTestEmail = (string) ($adminProfile['email'] ?? '');
}

$form = [
    'name' => '',
    'subject' => '',
    'heading' => '',
    'body' => '',
    'cta_text' => 'Explore UniStay',
    'cta_url' => $defaultCta,
    'test_email' => $defaultTestEmail,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    foreach (array_keys($form) as $field) {
        if (isset($_POST[$field])) {
            $form[$field] = trim((string) $_POST[$field]);
        }
    }

    if (!subscriber_csrf_valid($_POST['csrf'] ?? null)) {
        $errorMessage = 'Your session expired. Please refresh the page and try again.';
    } elseif ($action === 'send_test') {
        $testRecipient = subscriber_norm_email((string) $form['test_email']);

        if ($form['subject'] === '' || $form['heading'] === '' || $form['body'] === '') {
            $errorMessage = 'Add a subject, a heading, and a body before sending a test.';
        } elseif (!subscriber_is_valid_email($testRecipient)) {
            $errorMessage = 'Enter a valid address in "Send test to" so the test message actually reaches you.';
        } else {
            $campaign = $form + ['id' => 0];
            $testSubscriber = [
                'id' => 0,
                'email' => $testRecipient,
                'unsubscribe_token' => '',
            ];

            $payload = subscriber_campaign_payload($pdo, $testSubscriber, $campaign);
            $payload['footer_note'] = 'This is a test message sent from the UniStay admin.';
            $payload['unsubscribe_url'] = '';

            $rendered = subscriber_render($payload);
            $error = '';
            $result = mail_deliver_or_store(
                $testRecipient,
                '[TEST] ' . $rendered['subject'],
                $rendered['plain'],
                $rendered['html'],
                [],
                $error
            );

            if ($result['ok']) {
                $successMessage = $result['transport'] === 'file'
                    ? 'SMTP is not configured, so the test for ' . $testRecipient . ' was saved to storage/mail as an .eml file.'
                    : 'Test message sent to ' . $testRecipient . '. Check that inbox (and the spam folder).';
            } else {
                $errorMessage = 'Test message to ' . $testRecipient . ' failed: ' . ($error !== '' ? $error : 'unknown error');

                if (stripos($error, 'authenticate') !== false || stripos($error, '535') !== false) {
                    $errorMessage .= ' Gmail rejected the sender login, so paste a Google App Password (16 characters) in Mail Settings instead of the normal Gmail password.';
                }
            }
        }
    } elseif ($action === 'send_campaign') {
        if ($form['name'] === '' || $form['subject'] === '' || $form['heading'] === '' || $form['body'] === '') {
            $errorMessage = 'Campaign name, subject, heading, and body are required.';
        } else {
            $campaignId = subscriber_create_campaign($pdo, $form, (int) $adminProfile['id']);
            $queued = subscriber_enqueue_campaign($pdo, $campaignId);

            header('Location: subscribers.php?campaign=' . $campaignId . '&queued=' . $queued, true, 303);
            exit;
        }
    } elseif ($action === 'process_queue') {
        $stats = subscriber_queue_work($pdo, 50);
        $successMessage = sprintf(
            'Queue run: %d claimed, %d sent, %d failed, %d skipped.',
            $stats['claimed'],
            $stats['sent'],
            $stats['failed'],
            $stats['skipped']
        );
    } elseif ($action === 'retry_failed') {
        $retried = $pdo->exec(
            "UPDATE email_queue
                SET status = 'queued', attempts = 0, scheduled_at = NOW(), last_error = NULL
              WHERE status = 'failed'"
        );
        $successMessage = (int) $retried . ' failed message(s) queued for another try.';
    }
}

if ($successMessage === '' && isset($_GET['queued'])) {
    $successMessage = (int) $_GET['queued'] . ' message(s) queued for active subscribers. Run "Process queue now" to send them.';
}

$stats = subscriber_stats($pdo);

$recentSubscribers = $pdo->query(
    'SELECT id, email, status, source, created_at, confirmed_at, last_sent_at
     FROM subscribers
     ORDER BY id DESC
     LIMIT 25'
)->fetchAll();

$campaigns = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM email_queue q WHERE q.campaign_id = c.id) AS total_count,
            (SELECT COUNT(*) FROM email_queue q WHERE q.campaign_id = c.id AND q.status = 'sent') AS sent_count
     FROM campaigns c
     ORDER BY c.id DESC
     LIMIT 10"
)->fetchAll();

$failedRows = [];
if ($stats['failed'] > 0) {
    $failedRows = $pdo->query(
        "SELECT q.id, q.subject, q.attempts, q.last_error, COALESCE(s.email, '(deleted)') AS email
         FROM email_queue q
         LEFT JOIN subscribers s ON s.id = q.subscriber_id
         WHERE q.status = 'failed'
         ORDER BY q.id DESC
         LIMIT 5"
    )->fetchAll();
}

$csrf = subscriber_csrf_token();

$statusColors = [
    'active' => ['#ecfdf5', '#065f46', '#a7f3d0'],
    'pending' => ['#fffbeb', '#92400e', '#fde68a'],
    'unsubscribed' => ['#f8fafc', '#475569', '#e2e8f0'],
    'bounced' => ['#fef2f2', '#991b1b', '#fecaca'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscribers &amp; Mailer · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <script src="../assets/js/tenant/sidebar.js"></script>
    <style>
        body {
            background-color: #f7f3ef;
            color: #292524;
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }
        .admin-shell {
            flex: 1;
            padding: 30px 48px 56px;
            overflow-y: auto;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
        }
        .admin-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ebd9cb;
        }
        .admin-title-wrap h1 { font-family: 'Fraunces', serif; font-size: 2.05rem; margin: 0; color: #3b2820; letter-spacing: -0.5px; }
        .admin-title-wrap p { margin: 4px 0 0; color: #78665c; font-size: 0.95rem; }
        .admin-user-badge {
            display: flex; align-items: center; gap: 12px;
            background: #ffffff; border: 1px solid #ebd9cb;
            padding: 8px 16px; border-radius: 999px;
            box-shadow: 0 2px 8px rgba(60, 30, 20, 0.04);
        }
        .admin-avatar {
            width: 34px; height: 34px; border-radius: 50%; background: #7a4b3a; color: #fff;
            display: grid; place-items: center; font-weight: 700; font-size: 13px;
        }
        .admin-alert {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; border-radius: 12px; margin-bottom: 22px;
            font-size: 0.92rem; font-weight: 500;
        }
        .admin-alert.is-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .admin-alert.is-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .stat-card {
            background: #fff; border: 1px solid #ebd9cb; border-radius: 14px;
            padding: 16px 18px; box-shadow: 0 4px 16px rgba(60, 30, 20, 0.03);
        }
        .stat-card span { display: block; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: #8c7569; font-weight: 700; }
        .stat-card strong { display: block; font-size: 1.7rem; line-height: 1.2; color: #3b2820; margin-top: 4px; }
        .layout { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr); gap: 22px; align-items: start; }
        @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
        .card {
            background: #fff; border: 1px solid #ebd9cb; border-radius: 18px;
            padding: 24px 26px; box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            margin-bottom: 22px;
        }
        .card h2 { font-size: 1.15rem; margin: 0 0 4px; color: #3b2820; }
        .card .card-hint { margin: 0 0 18px; color: #78665c; font-size: 0.88rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 0.82rem; font-weight: 600; color: #3b2820; margin-bottom: 5px; }
        .form-input, .form-textarea {
            width: 100%; padding: 10px 13px; border: 1px solid #ebd9cb; border-radius: 10px;
            font-family: inherit; font-size: 0.9rem; color: #292524; outline: none; background: #fff;
        }
        .form-textarea { min-height: 150px; resize: vertical; line-height: 1.55; }
        .form-input:focus, .form-textarea:focus { border-color: #7a4b3a; box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.12); }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
        .btn {
            display: inline-block; padding: 10px 18px; border: 1px solid transparent; border-radius: 10px;
            font: inherit; font-size: 0.88rem; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        .btn.primary { background: #7a4b3a; color: #fff; }
        .btn.primary:hover { background: #633b2c; }
        .btn.secondary { background: #f5f1ec; color: #5f3425; border-color: #e3d6cc; }
        .btn.secondary:hover { background: #ebdfd5; }
        .btn.danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .btn.small { padding: 7px 12px; font-size: 0.78rem; }
        .queue-row { display: flex; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid #f1e7df; font-size: 0.88rem; }
        .queue-row:last-child { border-bottom: 0; }
        .queue-row span { color: #78665c; }
        .queue-row strong { color: #3b2820; }
        code.inline {
            background: #f5f1ec; border: 1px solid #e3d6cc; border-radius: 6px;
            padding: 2px 6px; font-size: 0.78rem; color: #5f3425; word-break: break-all;
        }
        .table-wrap { overflow-x: auto; }
        table.data { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        table.data th {
            text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em;
            color: #8c7569; padding: 0 10px 10px; border-bottom: 1px solid #ebd9cb; white-space: nowrap;
        }
        table.data td { padding: 10px; border-bottom: 1px solid #f4ece6; vertical-align: top; }
        table.data tr:last-child td { border-bottom: 0; }
        .badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 0.72rem; font-weight: 700; border: 1px solid transparent;
        }
        .muted { color: #8c7569; }
        .failed-list { margin: 12px 0 0; padding: 0; list-style: none; font-size: 0.8rem; }
        .failed-list li { padding: 8px 0; border-top: 1px solid #f1e7df; color: #7f1d1d; }
        .failed-list li span { display: block; color: #8c7569; margin-top: 2px; }
    </style>
</head>
<body>
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="mail-icon" viewBox="0 0 24 24"><path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="2"/><path d="m3 6 9 7 9-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
    </defs></svg>

    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <div class="brand">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </div>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="subscribers.php" title="Subscribers" aria-current="page"><svg aria-hidden="true"><use href="#mail-icon"/></svg><span class="nav-label">Subscribers</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-title-wrap">
                <h1>Subscribers &amp; Mailer</h1>
                <p>Newsletter list from the landing page footer, plus promotional email campaigns.</p>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="admin-user-badge">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong style="font-size:0.9rem; display:block;"><?= htmlspecialchars($adminProfile['name']) ?></strong>
                        <span style="font-size:0.75rem; color:#8c7569;">System Administrator</span>
                    </div>
                </div>
                <a href="../auth/logout.php" class="admin-logout-btn" title="Sign Out">Log Out</a>
            </div>
        </header>

        <?php if ($successMessage !== ''): ?>
            <div class="admin-alert is-success">✓ <?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="admin-alert is-error">⚠ <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php
        $mailSettings = mail_settings();
        $mailReady = mail_is_configured();
        $gmailNeedsAppPassword = $mailReady
            && stripos((string) $mailSettings['host'], 'gmail') !== false
            && strlen((string) $mailSettings['password']) !== 16;
        ?>
        <?php if (!$mailReady): ?>
            <div class="admin-alert is-error">
                ✉ Mail is not configured. Messages are only saved to <code>storage/mail</code>.
                <a href="../tools/mail-settings.php" style="color:inherit; font-weight:700; margin-left:6px;">Open Mail Settings</a>
            </div>
        <?php elseif ($gmailNeedsAppPassword): ?>
            <div class="admin-alert is-error">
                ✉ The Gmail sender password looks too short. Gmail requires a 16-character
                <strong>App Password</strong>, not the normal account password, so delivery will fail until it is updated.
                <a href="../tools/mail-settings.php" style="color:inherit; font-weight:700; margin-left:6px;">Open Mail Settings</a>
            </div>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card"><span>Total</span><strong><?= (int) $stats['total'] ?></strong></div>
            <div class="stat-card"><span>Active</span><strong><?= (int) $stats['active'] ?></strong></div>
            <div class="stat-card"><span>Pending</span><strong><?= (int) $stats['pending'] ?></strong></div>
            <div class="stat-card"><span>Unsubscribed</span><strong><?= (int) $stats['unsubscribed'] ?></strong></div>
            <div class="stat-card"><span>Queued</span><strong><?= (int) $stats['queued'] ?></strong></div>
            <div class="stat-card"><span>Failed</span><strong><?= (int) $stats['failed'] ?></strong></div>
        </div>

        <div class="layout">
            <div>
                <div class="card">
                    <h2>New promotional email</h2>
                    <p class="card-hint">Only active subscribers receive campaigns. Leave a blank line between paragraphs. The unsubscribe link and privacy policy are added automatically.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="campaign-name">Campaign name (internal)</label>
                                <input class="form-input" id="campaign-name" name="name" value="<?= htmlspecialchars($form['name']) ?>" placeholder="November dorm update">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="campaign-subject">Subject line</label>
                                <input class="form-input" id="campaign-subject" name="subject" value="<?= htmlspecialchars($form['subject']) ?>" placeholder="New dorms near your campus" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="campaign-heading">Email heading</label>
                            <input class="form-input" id="campaign-heading" name="heading" value="<?= htmlspecialchars($form['heading']) ?>" placeholder="Fresh rooms for the new semester" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="campaign-body">Body</label>
                            <textarea class="form-textarea" id="campaign-body" name="body" placeholder="Hi there,&#10;&#10;New dormitories just opened near your campus..." required><?= htmlspecialchars($form['body']) ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="campaign-cta-text">Button text</label>
                                <input class="form-input" id="campaign-cta-text" name="cta_text" value="<?= htmlspecialchars($form['cta_text']) ?>" placeholder="Explore UniStay">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="campaign-cta-url">Button link</label>
                                <input class="form-input" id="campaign-cta-url" name="cta_url" value="<?= htmlspecialchars($form['cta_url']) ?>" placeholder="<?= htmlspecialchars($defaultCta) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="campaign-test-email">Send test to</label>
                            <input class="form-input" id="campaign-test-email" name="test_email" type="email" value="<?= htmlspecialchars($form['test_email']) ?>" placeholder="you@example.com">
                            <p class="card-hint" style="margin:6px 0 0;">The test copy goes to this exact address. It does not have to be a subscriber.</p>
                        </div>
                        <div class="actions">
                            <button class="btn secondary" type="submit" name="action" value="send_test">Send test</button>
                            <button class="btn primary" type="submit" name="action" value="send_campaign" onclick="return confirm('Queue this email for every active subscriber?');">Send to all active (<?= (int) $stats['active'] ?>)</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <h2>Recent subscribers</h2>
                    <p class="card-hint">Newest 25 addresses from the landing page footer.</p>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr><th>Email</th><th>Status</th><th>Source</th><th>Joined</th><th>Last sent</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($recentSubscribers === []): ?>
                                    <tr><td colspan="5" class="muted">No subscribers yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($recentSubscribers as $subscriber): ?>
                                    <?php
                                    $status = (string) $subscriber['status'];
                                    $colors = $statusColors[$status] ?? ['#f8fafc', '#475569', '#e2e8f0'];
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $subscriber['email']) ?></td>
                                        <td><span class="badge" style="background:<?= $colors[0] ?>;color:<?= $colors[1] ?>;border-color:<?= $colors[2] ?>;"><?= htmlspecialchars($status) ?></span></td>
                                        <td class="muted"><?= htmlspecialchars((string) $subscriber['source']) ?></td>
                                        <td class="muted"><?= htmlspecialchars((string) $subscriber['created_at']) ?></td>
                                        <td class="muted"><?= $subscriber['last_sent_at'] ? htmlspecialchars((string) $subscriber['last_sent_at']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <h2>Mail queue</h2>
                    <p class="card-hint">Mail is queued first, then sent by the worker. Safe to run repeatedly.</p>
                    <div class="queue-row"><span>Waiting</span><strong><?= (int) $stats['queued'] ?></strong></div>
                    <div class="queue-row"><span>Failed</span><strong><?= (int) $stats['failed'] ?></strong></div>
                    <div class="actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button class="btn primary small" type="submit" name="action" value="process_queue">Process queue now</button>
                        </form>
                        <?php if ($stats['failed'] > 0): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <button class="btn danger small" type="submit" name="action" value="retry_failed">Retry failed</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <p class="card-hint" style="margin-top:16px;">Scheduled (Windows Task Scheduler):<br>
                        <code class="inline">C:\xampp\php\php.exe tools\send-mail-queue.php 50</code>
                    </p>
                    <p class="card-hint" style="margin-top:10px;">No SMTP configured yet? Messages are written to <code class="inline">storage/mail</code> as .eml files so you can test the flow.</p>

                    <?php if ($failedRows !== []): ?>
                        <ul class="failed-list">
                            <?php foreach ($failedRows as $failed): ?>
                                <li>
                                    <?= htmlspecialchars((string) $failed['email']) ?> — attempt <?= (int) $failed['attempts'] ?>
                                    <span><?= htmlspecialchars((string) $failed['last_error']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2>Recent campaigns</h2>
                    <?php if ($campaigns === []): ?>
                        <p class="card-hint" style="margin:0;">No campaigns yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead><tr><th>Campaign</th><th>Status</th><th>Sent</th></tr></thead>
                                <tbody>
                                    <?php foreach ($campaigns as $campaign): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars((string) $campaign['name']) ?></strong><br>
                                                <span class="muted"><?= htmlspecialchars((string) $campaign['subject']) ?></span>
                                            </td>
                                            <td class="muted"><?= htmlspecialchars((string) $campaign['status']) ?></td>
                                            <td class="muted"><?= (int) $campaign['sent_count'] ?> / <?= (int) $campaign['total_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
