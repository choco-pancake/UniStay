<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

start_app_session();

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

const RESET_LINK_MINUTES = 60;

$error = '';
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $email = trim((string)($_POST['email'] ?? ''));

    if (!is_string($submittedToken) || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } elseif (!mail_is_configured()) {
        error_log('UniStay password reset: mail server is not configured.');
        $error = 'Password reset emails are not set up yet. Please contact support.';
    } else {
        try {
            $pdo = database();

            $statement = $pdo->prepare('SELECT id, first_name FROM users WHERE personal_email = ? LIMIT 1');
            $statement->execute([$email]);
            $account = $statement->fetch();

            if ($account) {
                $userId = (int)$account['id'];

                // Do not send another mail if one was requested in the last minute.
                $recent = $pdo->prepare(
                    'SELECT 1 FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1'
                );
                $recent->execute([$userId]);

                if (!$recent->fetchColumn()) {
                    // Only the newest link works. Expired links are cleaned up too.
                    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()')
                        ->execute([$userId]);

                    $token = bin2hex(random_bytes(32));

                    $pdo->prepare(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . RESET_LINK_MINUTES . ' MINUTE))'
                    )->execute([$userId, hash('sha256', $token)]);

                    $link = app_url('auth/reset-password.php?token=' . $token);
                    $name = trim((string)($account['first_name'] ?? ''));
                    $greeting = $name !== '' ? 'Hi ' . $name . ',' : 'Hi,';

                    $paragraphs = [
                        $greeting,
                        'We received a request to reset your UniStay password. Select the button below to create a new one. The link works for ' . RESET_LINK_MINUTES . ' minutes.',
                        'If you did not ask for this, you can ignore this email. Your password will stay the same.',
                    ];

                    $plain = implode("\n\n", $paragraphs) . "\n\nReset your password: " . $link . "\n";
                    $html = mail_html('Reset your password', $paragraphs, 'Reset password', $link);

                    if (!send_mail($email, 'Reset your UniStay password', $plain, $html)) {
                        error_log('UniStay password reset: mail to user ' . $userId . ' failed.');
                    }
                }
            }

            // Same answer whether or not the email has an account, so nobody can
            // use this page to find out who is registered.
            $sent = true;
        } catch (PDOException $exception) {
            error_log('UniStay password reset request failed: ' . $exception->getMessage());
            $error = 'Password reset is temporarily unavailable. Check the database setup and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Forgot password</title>
    <link rel="stylesheet" href="../assets/css/topbar.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    <link rel="stylesheet" href="../assets/css/page-transition.css">
</head>
<body>

    <nav class="topbar">
        <a class="logo" href="../index.php" aria-label="UniStay home">
            <img src="../assets/images/logo.png" alt="UniStay logo">
            <span>UniStay</span>
        </a>
    </nav>

    <main class="stage">
        <div class="card">

            <section class="panel">
                <h1>Forgot your<br>password?</h1>
                <p class="panel-copy">
                    Enter the email you signed up with and we will send you a link to create a new one.
                </p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <section class="form-side">
                <div class="form-main">
                    <h2>Reset your password</h2>

                    <?php if ($sent): ?>
                        <p class="alert alert--success" role="status">
                            If an account exists for <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>,
                            a reset link is on its way. It expires in <?= RESET_LINK_MINUTES ?> minutes.
                        </p>
                        <p class="hint">Nothing in your inbox? Check your spam folder, or try again in a minute.</p>
                        <div class="actions">
                            <a class="btn-login" href="login.php" style="display:inline-grid;place-items:center;padding:0 28px;">Back to log in</a>
                        </div>
                    <?php else: ?>
                        <form action="forgot-password.php" method="POST" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                            <?php if ($error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <div class="field">
                                <label for="email">Enter your email</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input type="email" id="email" name="email" placeholder="Email address"
                                           value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                           autocomplete="email" required>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn-login">Send reset link</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="paths">
                    <div class="paths-row">
                        <span class="paths-left">Remembered it? <strong>Head back and sign in.</strong></span>
                        <div class="path-links">
                            <a href="login.php">Log in</a>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <script src="../assets/js/page-transition.js"></script>
</body>
</html>