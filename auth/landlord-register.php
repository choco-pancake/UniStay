<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

const INVITE_DAYS = 7;

/** Approved, unused, unexpired application for this invitation token, or null. */
function invited_landlord_application(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $statement = database()->prepare(
        "SELECT * FROM landlord_applications
         WHERE invitation_token_hash = ? AND status = 'approved' AND registered_at IS NULL
         LIMIT 1"
    );
    $statement->execute([hash('sha256', $token)]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;

    $decidedAt = strtotime((string)($application['decided_at'] ?? '')) ?: 0;
    if ($decidedAt === 0 || $decidedAt + INVITE_DAYS * 86400 < time()) return null;
    return $application;
}

function lr_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Keep the token out of the address bar: store it in the session, then reload clean.
if (isset($_GET['invite'])) {
    $incoming = trim((string)$_GET['invite']);
    if (preg_match('/^[a-f0-9]{64}$/', $incoming)) {
        $_SESSION['landlord_invite_token'] = $incoming;
    }
    header('Location: landlord-register.php', true, 303);
    exit;
}

// "Change" link in the Google badge
if (($_GET['action'] ?? '') === 'clear_google') {
    unset($_SESSION['google_pending']);
    header('Location: google_oauth.php?role=landlord', true, 303);
    exit;
}

$state = 'form';   // form | blocked | invalid
$current = session_user();
if ($current !== null) {
    if (($current['role'] ?? '') === 'landlord') {
        header('Location: ../landlord/dashboard.php', true, 303);
        exit;
    }
    $state = 'blocked'; // tenants and admins never see the form
}

$application = null;
if ($state === 'form') {
    $application = invited_landlord_application((string)($_SESSION['landlord_invite_token'] ?? ''));
    if (!$application) $state = 'invalid';
}

if (empty($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

$pending = $_SESSION['google_pending'] ?? null;
$isGoogle = is_array($pending) && !empty($pending['email']);
$errorMessage = '';

if ($state === 'form' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower((string)$application['email']);   // always the invited email
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['auth_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif (!isset($_POST['terms'])) {
        $errorMessage = 'Please agree to the Terms of Service, Code of Conduct, and Community Guidelines.';
    } elseif ($isGoogle && strtolower((string)$pending['email']) !== $email) {
        $errorMessage = 'That Google account does not match the email on your approved application.';
    } elseif (!$isGoogle && !preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/', $password)) {
        $errorMessage = 'Password must be 8–16 characters with uppercase, lowercase, a number, and a symbol (no spaces).';
    } elseif (!$isGoogle && !hash_equals($password, $confirm)) {
        $errorMessage = 'The passwords do not match.';
    } else {
        try {
            $db = database();
            $taken = $db->prepare('SELECT 1 FROM users WHERE personal_email = ? LIMIT 1');
            $taken->execute([$application['email']]);
            if ($taken->fetchColumn() !== false) {
                $errorMessage = 'An account with this email address already exists. Try signing in instead.';
            } else {
                $db->beginTransaction();
                $photo = $isGoogle && !empty($pending['picture']) ? (string)$pending['picture'] : null;
                $insert = $db->prepare(
                    "INSERT INTO users (first_name, middle_name, last_name, personal_email, password_hash, role, photo,
                                        phone_number, gov_id_file, verification_status, verified_at, profile_completed)
                     VALUES (?, ?, ?, ?, ?, 'landlord', ?, ?, ?, 'verified', NOW(), 0)"
                );
                $insert->execute([
                    (string)$application['first_name'],
                    $application['middle_name'] ?: null,
                    (string)$application['last_name'],
                    (string)$application['email'],
                    $isGoogle ? null : password_hash($password, PASSWORD_DEFAULT),
                    $photo,
                    (string)$application['phone'],
                    (string)$application['government_id_file'],
                ]);
                $userId = (int)$db->lastInsertId();

                $consume = $db->prepare(
                    'UPDATE landlord_applications SET user_id = ?, registered_at = NOW(), invitation_token_hash = NULL
                     WHERE id = ? AND registered_at IS NULL'
                );
                $consume->execute([$userId, (int)$application['id']]);
                if ($consume->rowCount() !== 1) throw new RuntimeException('The landlord invitation has already been used.');
                $db->commit();

                unset($_SESSION['landlord_invite_token'], $_SESSION['google_pending']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = 'landlord';
                $_SESSION['user'] = [
                    'id' => $userId,
                    'name' => trim($application['first_name'] . ' ' . $application['last_name']),
                    'email' => (string)$application['email'],
                    'photo' => $photo ?? '',
                ];
                $_SESSION['profile_completion_required'] = true;
                $_SESSION['profile_onboarding_phase'] = 'prompt';
                header('Location: ../landlord/dashboard.php', true, 303);
                exit;
            }
        } catch (Throwable $error) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') {
                $errorMessage = 'An account with this email address already exists. Try signing in instead.';
            } else {
                error_log('UniStay landlord registration failed: ' . $error->getMessage());
                $errorMessage = 'Registration is temporarily unavailable. Please try again.';
            }
        }
    }
}

$fullName = $application
    ? trim($application['first_name'] . ' ' . ($application['middle_name'] ?? '') . ' ' . $application['last_name'])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Landlord Sign Up · UniStay</title>
  <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
  <link rel="stylesheet" href="../assets/css/topbar.css">
  <link rel="stylesheet" href="../assets/css/register.css?v=30">
  <link rel="stylesheet" href="../assets/css/page-transition.css">
  <style>
    .control input[readonly]{background:#fbf6f2;color:#78665c;cursor:not-allowed}
    .lr-note{margin:0 0 16px;color:#78665c;font-size:.9rem;line-height:1.5}
    .lr-state{text-align:center;padding:30px 10px}
    .lr-state h2{margin:0 0 10px;font-family:'Fraunces',Georgia,serif;color:#3b2820}
    .lr-state p{margin:0 auto 22px;max-width:360px;color:#78665c;line-height:1.55}
    .terms-text a{color:#7a4b3a;text-decoration:underline}
    .control.lr-plain input{padding-left:14px !important}

    .lr-prefix-wrapper{
    display:flex;align-items:stretch;width:100%;height:48px;
    border:1px solid #d9bea6;border-radius:12px;
    background:#fbf6f2;overflow:hidden}
    .lr-prefix-addon{
    display:flex;align-items:center;padding:0 12px;
    background:#f3e8df;color:#78665c;
    font-size:.9rem;font-weight:600;
    border-right:1px solid #ebd9cb;
    user-select:none;white-space:nowrap}
    .lr-prefix-wrapper input{
    flex:1;min-width:0;height:100%;
    padding:0 14px !important;
    border:0 !important;border-radius:0 !important;
    background:transparent !important;box-shadow:none !important;outline:none;
    color:#78665c;cursor:not-allowed}

    /* Policy links: bold, no underline, darker on hover/click */
    .terms-text a{color:#7a4b3a;font-weight:700;text-decoration:none !important;transition:color .15s ease}
    .terms-text a:hover,.terms-text a:focus-visible,.terms-text a:active{color:#3b2820;text-decoration:none !important}

    /* More room below "Already have an account?" and the submit button */
    .form-main form{padding-bottom:32px}
    .form-main .actions{margin-bottom:24px}
  </style>
</head>
<body>
  <nav class="topbar">
    <a class="logo" href="../index.php" aria-label="UniStay home">
      <img src="../assets/images/logo.png" alt="UniStay logo"><span>UniStay</span>
    </a>
  </nav>

  <main class="stage">
    <div class="card">
      <section class="panel">
        <h1><span>List your place</span><br><span>with confidence.</span></h1>
        <p class="panel-copy">Publish verified rooms, connect directly with reliable students, and manage your tenancies with ease.</p>
        <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
      </section>

      <section class="form-side">
        <div class="form-main">
        <?php if ($state === 'blocked'): ?>
          <div class="lr-state">
            <h2>This invitation is for landlord applicants</h2>
            <p>You are signed in with a different kind of account, so this page isn’t available to you.</p>
            <a class="btn-register" href="../index.php">Back to home</a>
          </div>

        <?php elseif ($state === 'invalid'): ?>
          <div class="lr-state">
            <h2>Invitation unavailable</h2>
            <p>This invitation link is invalid, expired, or has already been used. Landlord accounts can only be created from the invitation email sent after your application is approved.</p>
            <a class="btn-register" href="../index.php">Back to home</a>
          </div>

        <?php else: ?>
          <div class="form-header-row"><h2 class="form-title">Landlord Sign Up</h2></div>
          <p class="lr-note">Your application was approved. Your details are filled in from it and can’t be changed here. Just set a password to finish.</p>

          <?php if ($errorMessage !== ''): ?><p class="alert" role="alert"><?= lr_h($errorMessage) ?></p><?php endif; ?>

          <form method="post" action="landlord-register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= lr_h($_SESSION['auth_csrf_token']) ?>">

            <div class="field">
                <label for="lr-name">Full Name</label>
            <div class="control lr-plain"><input type="text" id="lr-name" value="<?= lr_h($fullName) ?>" readonly tabindex="-1"></div>
            </div>
            <div class="field">
                <label for="lr-email">Email Address</label>
            <div class="control">
                <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg>
                <input type="email" id="lr-email" value="<?= lr_h((string)$application['email']) ?>" readonly tabindex="-1">
            </div>
            </div>
            <div class="field">
                <label for="lr-phone">Phone Number</label>
                <div class="lr-prefix-wrapper">
                    <span class="lr-prefix-addon">+63</span>
                    <input type="tel" id="lr-phone" value="<?= lr_h(preg_replace('/^(?:\+?63|0)/', '', preg_replace('/[^\d+]/', '', (string)$application['phone']))) ?>" readonly tabindex="-1">
                </div>
            </div>

            <?php if ($isGoogle): ?>
              <div class="google-badge-notice">
                <div class="google-badge-info">
                  <span class="badge-title">Signing up with Google</span>
                  <span class="badge-email"><?= lr_h((string)$pending['email']) ?></span>
                </div>
                <a href="landlord-register.php?action=clear_google" class="google-badge-switch">Change</a>
              </div>
            <?php else: ?>
              <div class="field">
                <label for="lr-password">Password</label>
                <div class="control lr-plain">
                  <input type="password" id="lr-password" name="password" autocomplete="new-password" placeholder="8–16 characters with a symbol" required minlength="8" maxlength="16">
                  <button class="eye" type="button" data-toggle="lr-password" aria-label="Show password" aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle><path class="slash" d="m4 4 16 16"></path></svg>
                  </button>
                </div>
              </div>
              <div class="field">
                <label for="lr-confirm">Confirm Password</label>
                <div class="control lr-plain">
                  <input type="password" id="lr-confirm" name="confirm_password" autocomplete="new-password" placeholder="Confirm password" required minlength="8" maxlength="16">
                  <button class="eye" type="button" data-toggle="lr-confirm" aria-label="Show password" aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle><path class="slash" d="m4 4 16 16"></path></svg>
                  </button>
                </div>
              </div>
              <div class="google-line">
                <span class="or-separator">or</span>
                <a href="google_oauth.php?role=landlord" class="btn-google-line">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M23 12.27c0-.8-.07-1.56-.2-2.3H12v4.36h6.17a5.28 5.28 0 0 1-2.29 3.46v2.87h3.7C21.74 18.7 23 15.7 23 12.27z"/>
                    <path fill="#34A853" d="M12 23.5c3.1 0 5.7-1.03 7.58-2.79l-3.7-2.87c-1.03.69-2.35 1.1-3.88 1.1-2.98 0-5.5-2.01-6.4-4.72H1.78v2.96A11.5 11.5 0 0 0 12 23.5z"/>
                    <path fill="#FBBC05" d="M5.6 14.22a6.9 6.9 0 0 1 0-4.44V6.82H1.78a11.5 11.5 0 0 0 0 10.36l3.82-2.96z"/>
                    <path fill="#EA4335" d="M12 5.06c1.69 0 3.2.58 4.39 1.72l3.29-3.29C17.7 1.64 15.1.5 12 .5 7.52.5 3.65 3.07 1.78 6.82l3.82 2.96C6.5 7.07 9.02 5.06 12 5.06z"/>
                  </svg>
                  <span>Sign up with Google</span>
                </a>
              </div>
            <?php endif; ?>

            <div class="terms-group">
              <label class="terms-label" for="lr-terms">
                <input type="checkbox" id="lr-terms" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                <span class="terms-text">I agree to the
                  <a href="../pages/terms-of-service.php" target="_blank" rel="noopener">Terms of Service</a>,
                  <a href="../pages/code-of-conduct.php" target="_blank" rel="noopener">Code of Conduct</a>, and
                  <a href="../pages/community-guidelines.php" target="_blank" rel="noopener">Community Guidelines</a>.</span>
              </label>
            </div>

            <div class="actions">
              <p class="signin-switch">Already have an account? <a href="login.php">Log in</a></p>
              <button type="submit" class="btn-register"><span>Create landlord account</span></button>
            </div>
          </form>
        <?php endif; ?>
        </div>
      </section>
    </div>
  </main>

  <script>
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.toggle);
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        btn.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' password');
      });
    });
  </script>
  <script src="../assets/js/page-transition.js"></script>
</body>
</html>
