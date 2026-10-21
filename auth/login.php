<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

start_app_session();

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php', true, 303);
    exit;
}

$login_error = '';
$login_email = '';
if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_login') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $email = trim((string)($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $login_email = $email;

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
        || !is_string($password)
        || $email === ''
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || $password === ''
    ) {
        $login_error = 'Enter a valid email and password, then try again.';
    } else {
        try {
            $statement = database()->prepare(
                'SELECT id, first_name, last_name, personal_email, password_hash, role, photo FROM users WHERE personal_email = ? LIMIT 1'
            );
            $statement->execute([$email]);
            $account = $statement->fetch();

            if (!$account || !is_string($account['password_hash']) || !password_verify($password, $account['password_hash'])) {
                $login_error = 'Email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$account['id'];
                $_SESSION['role'] = $account['role'];
                $_SESSION['user'] = [
                    'name' => trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')),
                    'email' => $account['personal_email'],
                    // The Google profile picture of this account, stored when it was linked to Google.
                    'photo' => (string)($account['photo'] ?? ''),
                ];
                header('Location: ../' . rawurlencode($account['role']) . '/dashboard.php', true, 303);
                exit;
            }
        } catch (PDOException $error) {
            error_log('UniStay email sign-in failed: ' . $error->getMessage());
            $login_error = 'Sign-in is temporarily unavailable. Check the database setup and try again.';
        }
    }
}

/* ---------- Forgot / reset password, rendered inside this same page ---------- */
$forgot_error = '';
$forgot_email = '';
$forgot_sent = false;
$forgot_link = '';
$reset_error = '';
$reset_token = '';
$reset_user_id = null;
$reset_valid = false;
$reset_done = false;

$view = 'signin';
$requested_view = (string)($_GET['view'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_password') {
    $view = 'forgot';
    $submittedToken = $_POST['csrf_token'] ?? '';
    $email = trim((string)($_POST['email'] ?? ''));
    $forgot_email = $email;

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
        || $email === ''
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        $forgot_error = 'Enter a valid email address, then try again.';
    } else {
        try {
            $statement = database()->prepare(
                'SELECT id FROM users WHERE personal_email = ? AND password_hash IS NOT NULL LIMIT 1'
            );
            $statement->execute([$email]);
            $account = $statement->fetch();

            if ($account) {
                $userId = (int)$account['id'];

                database()->prepare('DELETE FROM password_resets WHERE user_id = ?')
                    ->execute([$userId]);

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                database()->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
                )->execute([$userId, $tokenHash, $expiresAt]);

                $resetUrl = 'login.php?view=reset&token=' . rawurlencode($rawToken);
                $absoluteResetUrl = app_url('/auth/login.php?view=reset&token=' . rawurlencode($rawToken));
                $subject = 'UniStay password reset';
                $body = "Hello,\n\n"
                    . "We received a request to reset your UniStay password.\n"
                    . "Press the button below to choose a new password, the link works for 1 hour:\n\n"
                    . $absoluteResetUrl . "\n\n"
                    . "If you did not request this, you can safely ignore this email.";
                $htmlBody = mail_html(
                    'Reset your password',
                    [
                        'We received a request to reset your UniStay password.',
                        'Press the button below to choose a new password. The link works for 1 hour.',
                    ],
                    'Reset password',
                    $absoluteResetUrl
                );

                if (!send_mail($email, $subject, $body, $htmlBody)) {
                    // Without a working mail server the link is shown on the page so the flow stays usable.
                    error_log('UniStay password reset link for ' . $email . ': ' . $absoluteResetUrl);
                    $forgot_link = $resetUrl;
                }

                $forgot_sent = true;
            } else {
                $forgot_error = 'Account doesn\'t exist for that email.';
            }
        } catch (Throwable $error) {
            error_log('UniStay password reset request failed: ' . $error->getMessage());
            $forgot_error = 'Something went wrong. Please try again later.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $view = 'reset';
}

if ($view === 'signin' && ($requested_view === 'forgot' || $requested_view === 'reset')) {
    $view = $requested_view;
}

if ($view === 'reset') {
    $reset_token = trim((string)($_GET['token'] ?? ''));

    if ($reset_token !== '' && preg_match('/^[a-f0-9]{64}$/', $reset_token) === 1) {
        try {
            $statement = database()->prepare(
                'SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1'
            );
            $statement->execute([hash('sha256', $reset_token)]);
            $record = $statement->fetch();

            if ($record !== false && strtotime((string)$record['expires_at']) > time()) {
                $reset_user_id = (int)$record['user_id'];
                $reset_valid = true;
            }
        } catch (Throwable $error) {
            error_log('UniStay password reset lookup failed: ' . $error->getMessage());
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password' && $reset_valid) {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            !is_string($submittedToken)
            || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
        ) {
            $reset_error = 'Your session expired. Refresh the page and try again.';
        } elseif (
            !is_string($password)
            || !preg_match(
                '/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/',
                $password
            )
        ) {
            $reset_error = 'Your password must be 8-16 characters and include uppercase and lowercase letters, a number, and a symbol, with no spaces.';
        } elseif (
            !is_string($confirmPassword)
            || !hash_equals($password, $confirmPassword)
        ) {
            $reset_error = 'The passwords do not match.';
        } else {
            try {
                database()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $reset_user_id]);

                database()->prepare('DELETE FROM password_resets WHERE user_id = ?')
                    ->execute([$reset_user_id]);

                $reset_valid = false;
                $reset_done = true;
            } catch (Throwable $error) {
                error_log('UniStay password reset failed: ' . $error->getMessage());
                $reset_error = 'Something went wrong. Please try again later.';
            }
        }
    }
}

$user = $_SESSION['user'] ?? [];
$is_logged_in = is_array($user) && isset($_SESSION['role']);
$user_name = $is_logged_in ? (string)($user['name'] ?? '') : '';
$user_email = $is_logged_in ? (string)($user['email'] ?? '') : '';
$user_photo = $is_logged_in ? (string)($user['photo'] ?? '') : '';

// A message left behind by the Google sign-in flow.
$auth_error = '';
if (isset($_SESSION['auth_error']) && is_string($_SESSION['auth_error']) && $_SESSION['auth_error'] !== '') {
    $auth_error = $_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}

if ($auth_error !== '' && $login_error === '') {
    $login_error = $auth_error;
}

$auth_notice = '';
if (isset($_SESSION['auth_notice']) && is_string($_SESSION['auth_notice']) && $_SESSION['auth_notice'] !== '') {
    $auth_notice = $_SESSION['auth_notice'];
    unset($_SESSION['auth_notice']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Sign in</title>
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

            <!-- Brown panel -->
            <section class="panel">
                <h1>Your room is<br>waiting.</h1>
                <p class="panel-copy">
                    Find verified rooms near your university, book with confidence, and settle in without the guesswork.
                </p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <!-- Form side -->
            <section class="form-side">
                <div class="form-main">
                    <?php if ($view === 'forgot'): ?>
                        <h2>Forgot your password?</h2>
                        <p class="hint">Enter the email address linked to your UniStay account and we will email you a link to choose a new password.</p>

                        <?php if ($forgot_sent): ?>
                            <p class="notice">Reset link sent. Check your inbox to continue.</p>

                            <?php if ($forgot_link !== ''): ?>
                                <p class="hint">No mail server is configured on this machine, so here is your reset link:</p>

                                <div class="actions">
                                    <a class="btn-login" href="<?= htmlspecialchars($forgot_link, ENT_QUOTES, 'UTF-8') ?>">Open reset link</a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($forgot_error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($forgot_error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <form action="login.php?view=forgot" method="POST" novalidate>
                                <input type="hidden" name="action" value="forgot_password">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                                <div class="field">
                                    <label for="forgot-email">Enter your email</label>
                                    <div class="control">
                                        <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                            <path d="m3 7 9 6 9-6"></path>
                                        </svg>
                                        <input type="email" id="forgot-email" name="email" placeholder="Email address"
                                               value="<?= htmlspecialchars($forgot_email, ENT_QUOTES, 'UTF-8') ?>"
                                               autocomplete="email" required>
                                    </div>
                                </div>

                                <div class="actions">
                                    <button type="submit" class="btn-login">Send reset link</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="actions">
                            <a class="back-link" href="login.php">&larr; Back to sign in</a>
                        </div>
                    <?php elseif ($view === 'reset'): ?>
                        <h2>Set a new password</h2>

                        <?php if ($reset_done): ?>
                            <p class="notice">Password updated successfully!</p>

                            <div class="actions">
                                <a class="btn-login" href="login.php">Back to sign in</a>
                            </div>
                        <?php elseif (!$reset_valid): ?>
                            <p class="alert" role="alert">This reset link is invalid or has expired. Please request a new one.</p>

                            <div class="actions">
                                <a class="btn-login" href="login.php?view=forgot">Request a new link</a>
                            </div>
                        <?php else: ?>
                            <p class="hint">Your new password must be 8-16 characters and include uppercase and lowercase letters, a number, and a symbol.</p>

                            <?php if ($reset_error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($reset_error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <form action="login.php?view=reset&amp;token=<?= htmlspecialchars($reset_token, ENT_QUOTES, 'UTF-8') ?>" method="POST">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                                <div class="field">
                                    <label for="new-password">New password</label>
                                    <div class="control">
                                        <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                            <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                        </svg>
                                        <input type="password" id="new-password" name="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                                               autocomplete="new-password" required minlength="8" maxlength="16">
                                    </div>
                                </div>

                                <div class="field">
                                    <label for="confirm-password">Confirm new password</label>
                                    <div class="control">
                                        <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                            <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                        </svg>
                                        <input type="password" id="confirm-password" name="confirm_password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                                               autocomplete="new-password" required minlength="8" maxlength="16">
                                    </div>
                                </div>

                                <div class="actions">
                                    <button type="submit" class="btn-login">Update password</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if (!$reset_done): ?>
                        <div class="actions">
                            <a class="back-link" href="login.php">&larr; Back to sign in</a>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($is_logged_in): ?>
                        <div class="signed-in">
                            <?php if ($user_photo !== ''): ?>
                                <img class="avatar" src="<?= htmlspecialchars($user_photo, ENT_QUOTES, 'UTF-8') ?>" alt="">
                            <?php endif; ?>
                            <h2>Welcome back!</h2>
                            <p><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>)</p>
                            <a href="login.php?action=logout" class="btn-login">Sign out</a>
                        </div>
                    <?php else: ?>
                        <h2>Sign in to UniStay</h2>

                        <form action="login.php" method="POST" novalidate>
                            <input type="hidden" name="action" value="email_login">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                            <?php if ($auth_notice !== ''): ?>
                                <p class="alert alert--success" role="status"><?= htmlspecialchars($auth_notice, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <?php if ($login_error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <div class="field">
                                <label for="email">Enter your email</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input type="email" id="email" name="email" placeholder="Email address"
                                           value="<?= htmlspecialchars($login_email, ENT_QUOTES, 'UTF-8') ?>"
                                           autocomplete="email" required>
                                </div>
                            </div>

                            <div class="field">
                                <label for="password">Password</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                    <input type="password" id="password" name="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                                           autocomplete="current-password" required>
                                    <button type="button" class="eye" id="togglePassword" aria-label="Show password" aria-pressed="false">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path class="slash" d="m4 4 16 16"></path>
                                        </svg>
                                    </button>
                                </div>
                                <a class="forgot" href="login.php?view=forgot">Forgot Password?</a>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn-login">Log in</button>
                                <span class="or">OR</span>
                                <a class="btn-google" id="googleSignUp" href="google_oauth.php?role=tenant">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path fill="#4285F4" d="M23 12.27c0-.8-.07-1.56-.2-2.3H12v4.36h6.17a5.28 5.28 0 0 1-2.29 3.46v2.87h3.7C21.74 18.7 23 15.7 23 12.27z"/>
                                        <path fill="#34A853" d="M12 23.5c3.1 0 5.7-1.03 7.58-2.79l-3.7-2.87c-1.03.69-2.35 1.1-3.88 1.1-2.98 0-5.5-2.01-6.4-4.72H1.78v2.96A11.5 11.5 0 0 0 12 23.5z"/>
                                        <path fill="#FBBC05" d="M5.6 14.22a6.9 6.9 0 0 1 0-4.44V6.82H1.78a11.5 11.5 0 0 0 0 10.36l3.82-2.96z"/>
                                        <path fill="#EA4335" d="M12 5.06c1.69 0 3.2.58 4.39 1.72l3.29-3.29C17.7 1.64 15.1.5 12 .5 7.52.5 3.65 3.07 1.78 6.82l3.82 2.96C6.5 7.07 9.02 5.06 12 5.06z"/>
                                    </svg>
                                    Sign in with Google
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($view === 'signin'): ?>
                <div class="paths">
                    <div class="paths-row">
                        <span class="paths-left">New to UniStay? <strong>Choose your path!</strong></span>
                        <div class="path-links">
                            <a href="register.php?type=tenant">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="m2 9 10-5 10 5-10 5z"></path>
                                    <path d="M6 11.5V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.5"></path>
                                </svg>
                                Find a Home
                            </a>
                            <a href="register.php?type=landlord">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M3 21V10l6-4 6 4v11"></path>
                                    <path d="M7 21v-5h4v5"></path>
                                    <path d="M15 14h3.5L22 12.5V21h-7"></path>
                                </svg>
                                List a Property
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </section>


        </div>
    </main>

    <script>
        (function () {
            var input = document.getElementById('password');
            var toggle = document.getElementById('togglePassword');
            if (input && toggle) {
                toggle.addEventListener('click', function () {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                });
            }
        })();
    </script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>
