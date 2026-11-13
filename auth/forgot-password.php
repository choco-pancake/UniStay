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
    if ($email === '' && !empty($_POST['sent_email'])) {
        $email = trim((string)$_POST['sent_email']);
    }

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

            require_once __DIR__ . '/../config/account_ids.php';
            $targetUser = null;
            $userRole = 'tenant';
            foreach (['tenant' => 'tenants', 'landlord' => 'landlords', 'admin' => 'admins'] as $r => $table) {
                $pk = account_pk($r);
                $statement = $pdo->prepare("SELECT `{$pk}` AS id, `{$pk}`, first_name FROM `{$table}` WHERE email = ? LIMIT 1");
                $statement->execute([$email]);
                $account = $statement->fetch();
                if ($account) {
                    $targetUser = $account;
                    $userRole = $r;
                    break;
                }
            }

            if ($targetUser) {
                $userId = (string)$targetUser['id'];

                // Do not send another mail if one was requested in the last minute.
                $recent = $pdo->prepare(
                    'SELECT 1 FROM password_resets WHERE role = ? AND user_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1'
                );
                $recent->execute([$userRole, $userId]);

                if (!$recent->fetchColumn()) {
                    // Only the newest link works. Expired links are cleaned up too.
                    $pdo->prepare('DELETE FROM password_resets WHERE (role = ? AND user_id = ?) OR expires_at < NOW()')
                        ->execute([$userRole, $userId]);

                    $token = bin2hex(random_bytes(32));

                    $pdo->prepare(
                        'INSERT INTO password_resets (role, user_id, token_hash, expires_at)
                         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . RESET_LINK_MINUTES . ' MINUTE))'
                    )->execute([$userRole, $userId, hash('sha256', $token)]);

                    $link = app_url('auth/reset-password.php?token=' . $token);
                    $name = trim((string)($targetUser['first_name'] ?? ''));
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
    <title>Forgot Password · UniStay</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="stylesheet" href="../assets/css/topbar.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/css/landing-dark.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    <link rel="stylesheet" href="../assets/css/notification-toast.css">
    <link rel="stylesheet" href="../assets/css/page-transition.css">
    <link rel="stylesheet" href="../assets/css/autofill.css">
    <script src="../assets/js/theme.js"></script>
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

                    <?php if ($error !== ''): ?>
                        <p class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>

                    <form action="forgot-password.php" method="POST" id="forgotForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                        <div class="field">
                            <label for="email">Email</label>
                            <div class="control">
                                <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="m3 7 9 6 9-6"></path>
                                </svg>
                                <input type="email" id="email" name="email" placeholder="example@email.com"
                                       value="<?= $sent ? '' : htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                       autocomplete="email" <?= $sent ? '' : 'required' ?>>
                            </div>
                        </div>

                        <?php if ($sent): ?>
                            <input type="hidden" name="sent_email" id="sentEmail" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <div class="actions--stack">
                            <?php if ($sent): ?>
                                <button type="submit" class="btn-landing-primary" id="btnForgotSubmit">Send again</button>
                                <button type="button" class="btn-landing-outline" id="btnTryAnother">Try another email</button>
                            <?php else: ?>
                                <button type="submit" class="btn-login" id="btnForgotSubmit">Send reset link</button>
                            <?php endif; ?>
                            <a class="btn-landing-outline" href="login.php">Back to sign in</a>
                        </div>
                    </form>
                </div>
            </section>

        </div>
    </main>

    <?php if ($sent): ?>
        <div class="notification-toast is-visible" id="forgotToast" role="alert">
            <div class="toast-header">
                <strong>Reset link sent!</strong>
                <button type="button" class="toast-close-btn" onclick="this.closest('.notification-toast').remove()" aria-label="Close notification">&times;</button>
            </div>
            <span>We emailed a link to <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>. Check your inbox to continue.</span>
        </div>
        <script>
            setTimeout(function() {
                var t = document.getElementById('forgotToast');
                if (t) {
                    t.classList.add('is-hiding');
                    setTimeout(function() { t.remove(); }, 350);
                }
            }, 6000);
        </script>
    <?php endif; ?>

    <script>
        (function () {
            var forgotForm = document.getElementById('forgotForm');
            if (forgotForm) {
                forgotForm.addEventListener('submit', function () {
                    var btn = document.getElementById('btnForgotSubmit');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = 'Sending...';
                    }
                });
            }

            var btnTryAnother = document.getElementById('btnTryAnother');
            if (btnTryAnother) {
                btnTryAnother.addEventListener('click', function () {
                    var emailInput = document.getElementById('email');
                    if (emailInput) {
                        emailInput.value = '';
                        emailInput.placeholder = 'example@email.com';
                        emailInput.required = true;
                        emailInput.focus();
                    }
                    var sentEmail = document.getElementById('sentEmail');
                    if (sentEmail) {
                        sentEmail.value = '';
                    }
                    var submitBtn = document.getElementById('btnForgotSubmit');
                    if (submitBtn) {
                        submitBtn.textContent = 'Send reset link';
                        submitBtn.className = 'btn-login';
                    }
                    btnTryAnother.style.display = 'none';
                });
            }
        })();
    </script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>