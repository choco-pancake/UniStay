<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();

// Keep the token out of the Referer header when the page loads anything external.
header('Referrer-Policy: no-referrer');

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
$error = '';

try {
    if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
        $statement = database()->prepare(
            'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $statement->execute([hash('sha256', $token)]);
        $reset = $statement->fetch() ?: null;
    }
} catch (PDOException $exception) {
    error_log('UniStay password reset lookup failed: ' . $exception->getMessage());
    $error = 'Password reset is temporarily unavailable. Check the database setup and try again.';
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (
        !is_string($password)
        || !preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/', $password)
    ) {
        $error = 'Your password must be 8–16 characters and include uppercase and lowercase letters, a number, and a symbol, with no spaces.';
    } elseif (!is_string($confirm) || !hash_equals($password, $confirm)) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $pdo = database();
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);

            // The link is single-use, and any other pending links for this account die with it.
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')
                ->execute([(int)$reset['user_id']]);

            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['auth_notice'] = 'Your password has been updated. Log in with your new password.';

            header('Location: login.php', true, 303);
            exit;
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('UniStay password reset failed: ' . $exception->getMessage());
            $error = 'We could not update your password. Please try again.';
        }
    }
}

$link_is_valid = $reset !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>UniStay - Create new password</title>
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
                <h1>Create a new<br>password.</h1>
                <p class="panel-copy">
                    Pick something you have not used before. Once it is saved, you can log in right away.
                </p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <section class="form-side">
                <div class="form-main">
                    <?php if (!$link_is_valid): ?>
                        <h2>Link expired</h2>
                        <p class="alert" role="alert">
                            <?= $error !== '' ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'This reset link is invalid or has expired.' ?>
                        </p>
                        <p class="hint">Reset links work once and expire after 60 minutes. Request a new one to continue.</p>
                        <div class="actions">
                            <a class="btn-login" href="forgot-password.php" style="display:inline-grid;place-items:center;padding:0 28px;">Request a new link</a>
                        </div>
                    <?php else: ?>
                        <h2>Create new password</h2>

                        <form action="reset-password.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                            <?php if ($error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <div class="field">
                                <label for="password">New password</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                    <input type="password" id="password" name="password" placeholder="8–16 characters with a symbol"
                                           autocomplete="new-password" minlength="8" maxlength="16" required>
                                    <button type="button" class="eye" data-target="password" aria-label="Show password" aria-pressed="false">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path class="slash" d="m4 4 16 16"></path>
                                        </svg>
                                    </button>
                                </div>
                                <p class="rules">Use uppercase and lowercase letters, a number and a symbol. No spaces.</p>
                            </div>

                            <div class="field">
                                <label for="confirm_password">Confirm new password</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password"
                                           autocomplete="new-password" minlength="8" maxlength="16" required>
                                    <button type="button" class="eye" data-target="confirm_password" aria-label="Show password" aria-pressed="false">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path class="slash" d="m4 4 16 16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn-login">Save password</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="paths">
                    <div class="paths-row">
                        <span class="paths-left">Changed your mind? <strong>Head back and sign in.</strong></span>
                        <div class="path-links">
                            <a href="login.php">Log in</a>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <script>
        document.querySelectorAll('.eye[data-target]').forEach(function (button) {
            var input = document.getElementById(button.dataset.target);
            if (!input) return;
            button.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        });
    </script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>