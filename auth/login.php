<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/account_ids.php';

start_app_session();

// Validate a persisted session before deciding whether this page should show
// the signed-in state. Deleted accounts are reduced to a normal guest session.
$validatedSessionUser = session_user();

function login_destination(array $account): string
{
    $role = (string)($account['role'] ?? 'tenant');
    if ($role === 'admin') return '../admin/dashboard.php';
    $complete = $role === 'landlord'
        ? landlord_profile_complete(database(), (string)$account['id'])
        : tenant_profile_complete(database(), (string)$account['id']);
    if (!$complete) {
        $_SESSION['profile_completion_required'] = true;
        $_SESSION['profile_onboarding_phase'] = 'prompt';
        return $role === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dashboard.php';
    }
    unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
    return $role === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dorms.php';
}

if (in_array(($_GET['action'] ?? ''), ['logout', 'switch'], true)) {
    $isSwitch = ($_GET['action'] ?? '') === 'switch';
    if ($isSwitch) {
        // Keep the server-side remembered-account list while requiring the
        // next account to authenticate normally.
        clear_authenticated_session(false);
        session_regenerate_id(true);
    } else {
        clear_authenticated_session(true);
    }
    header('Location: login.php', true, 303);
    exit;
}

$login_error = '';
$suspendedLandlordNotice = '';
$suspendedTenantNotice = '';
$pendingLandlordNotice = '';
if (!empty($_SESSION['pending_landlord_toast'])) {
    $pendingLandlordNotice = 'This account is still under review by the administrator. Please await admin approval.';
    unset($_SESSION['pending_landlord_toast']);
}
$declinedLandlordNotice = '';
if (!empty($_SESSION['declined_landlord_toast'])) {
    $declinedLandlordNotice = 'Your email is still undergoing verification from the administrator. Please wait for a confirmation in your inbox.';
    unset($_SESSION['declined_landlord_toast']);
}
$unactivatedLandlordNotice = '';
if (!empty($_SESSION['unactivated_landlord_toast'])) {
    $unactivatedLandlordNotice = 'Please check your inbox for password creation and account activation.';
    unset($_SESSION['unactivated_landlord_toast']);
}
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
            $account = find_user_by_email(database(), $email);

            if (!$account) {
                $login_error = 'Email or password is incorrect.';
            } elseif ($account['role'] === 'landlord' && ($account['status'] ?? '') === 'suspended') {
                $reason = !empty($account['suspension_reason']) ? $account['suspension_reason'] : 'Please contact the Office of Student Affairs and Services (OSAS).';
                $suspendedLandlordNotice = 'Your account has been suspended by OSAS. Reason: ' . $reason;
            } elseif ($account['role'] === 'tenant' && ($account['account_status'] ?? '') === 'suspended') {
                $reason = !empty($account['suspension_reason']) ? $account['suspension_reason'] : 'Please contact the Office of Student Affairs and Services (OSAS).';
                $suspendedTenantNotice = 'Your account has been suspended. Reason: ' . $reason;
            } elseif ($account['role'] === 'landlord' && ($account['status'] ?? '') === 'pending') {
                $pendingLandlordNotice = 'This account is still under review by the administrator. Please await admin approval.';
            } elseif ($account['role'] === 'landlord' && ($account['status'] ?? '') === 'declined') {
                $declinedLandlordNotice = 'Your email is still undergoing verification from the administrator. Please wait for a confirmation in your inbox.';
            } elseif ($account['role'] === 'landlord' && (empty($account['password_hash']) || !empty($account['activation_token']))) {
                $unactivatedLandlordNotice = 'Please check your inbox for password creation and account activation.';
            } else {
                $resolvedPhoto = resolve_user_profile_photo_url($account['profile_photo'] ?? null, $account['photo'] ?? null, (string)$account['role']);

                if ($account['password_hash'] === null || $account['password_hash'] === '') {
                    $login_error = 'This account does not have a UniStay password yet. Sign in with Google to create one securely.';
                } elseif (!password_verify($password, $account['password_hash'])) {
                    $login_error = 'Email or password is incorrect.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (string)$account['id'];
                    $_SESSION['role'] = $account['role'];
                    $_SESSION['user'] = [
                        'name' => trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')),
                        'email' => $account['email'] ?? $account['personal_email'],
                        'photo' => $resolvedPhoto,
                    ];
                    remember_authenticated_account((string)$account['id'], (string)$account['role']);
                    header('Location: ' . login_destination($account), true, 303);
                    exit;
                }
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
    if ($email === '' && !empty($_POST['sent_email'])) {
        $email = trim((string)$_POST['sent_email']);
    }
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
            $account = find_user_by_email(database(), $email);

            if ($account) {
                $userId = (string)$account['id'];
                $accountRole = (string)($account['role'] ?? 'tenant');

                database()->prepare('DELETE FROM password_resets WHERE role = ? AND user_id = ?')
                    ->execute([$accountRole, $userId]);

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                database()->prepare(
                    'INSERT INTO password_resets (role, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)'
                )->execute([$accountRole, $userId, $tokenHash, $expiresAt]);

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

$reset_role = 'tenant';
if ($view === 'reset') {
    $reset_token = trim((string)($_GET['token'] ?? ''));

    if ($reset_token !== '' && preg_match('/^[a-f0-9]{64}$/', $reset_token) === 1) {
        try {
            $statement = database()->prepare(
                'SELECT id, role, user_id, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1'
            );
            $statement->execute([hash('sha256', $reset_token)]);
            $record = $statement->fetch();

            if ($record !== false && strtotime((string)$record['expires_at']) > time()) {
                $reset_user_id = (string)$record['user_id'];
                $reset_role = (string)($record['role'] ?? 'tenant');
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
                $resetTbl = account_table($reset_role);
                $resetPk  = account_pk($reset_role);
                database()->prepare("UPDATE `{$resetTbl}` SET password_hash = ? WHERE `{$resetPk}` = ?")
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $reset_user_id]);

                database()->prepare('DELETE FROM password_resets WHERE role = ? AND user_id = ?')
                    ->execute([$reset_role, $reset_user_id]);

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
$is_logged_in = !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
$user_name = $is_logged_in ? (string)($user['name'] ?? '') : '';
$user_email = $is_logged_in ? (string)($user['email'] ?? '') : '';
$user_photo = $is_logged_in ? (string)($user['photo'] ?? '') : '';

if ($is_logged_in) {
    $signedInAccount = session_account();
    if ($signedInAccount !== null) {
        $user_email = (string)($signedInAccount['email'] ?? ($signedInAccount['personal_email'] ?? ''));
        $resolvedUserPhoto = resolve_user_profile_photo_url(
            $signedInAccount['profile_photo'] ?? null,
            $signedInAccount['photo'] ?? null,
            (string)($signedInAccount['role'] ?? '')
        );
        $user_photo = $resolvedUserPhoto;

        $fullName = trim(
            (string)($signedInAccount['first_name'] ?? '') . ' '
            . (string)($signedInAccount['middle_name'] ?? '') . ' '
            . (string)($signedInAccount['last_name'] ?? '')
        );
        if ($fullName !== '') {
            $user_name = $fullName;
        }

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['photo'] = $user_photo;
            if ($user_name !== '') {
                $_SESSION['user']['name'] = $user_name;
            }
            if ($user_email !== '') {
                $_SESSION['user']['email'] = $user_email;
            }
        }
    }
}

// The initials and background color for the avatar, used when the account has no profile photo.
$user_initials = '';
$user_color = '#7a4b3a';

if ($is_logged_in) {
    if ($user_name !== '' && $user_name !== $user_email) {
        $nameParts = preg_split('/\s+/u', trim($user_name), -1, PREG_SPLIT_NO_EMPTY);
        if ($nameParts) {
            $firstInitial = mb_substr($nameParts[0], 0, 1);
            $lastInitial = count($nameParts) > 1
                ? mb_substr($nameParts[count($nameParts) - 1], 0, 1)
                : '';
            $user_initials = mb_strtoupper($firstInitial . $lastInitial);
        }
    }

    if ($user_initials === '' && $user_email !== '') {
        $user_initials = mb_strtoupper(mb_substr($user_email, 0, 1));
    }

    $avatarPalette = [
        '#7a4b3a', '#2f6f8f', '#3f7d4e', '#8a4f9e',
        '#b5532f', '#c2410c', '#0f766e', '#4f5fa8',
        '#a33d5b', '#6b6b2a',
    ];
    $avatarSeed = (string) ($_SESSION['user_id'] ?? $user_email);
    $user_color = $avatarPalette[crc32($avatarSeed) % count($avatarPalette)];

    $user_role = (string)($_SESSION['role'] ?? 'tenant');
    if ($user_role === 'admin') {
        $continue_url = '../admin/dashboard.php';
        $continue_label = 'Continue to Admin Dashboard';
    } elseif ($user_role === 'landlord') {
        $continue_url = '../landlord/dashboard.php';
        $continue_label = 'Continue to Landlord Dashboard';
    } else {
        $continue_url = '../tenant/dorms.php';
        $continue_label = 'Continue to UniStay';
    }
}

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
    if (stripos($auth_notice, 'suspended') !== false) {
        $suspendedTenantNotice = $auth_notice;
        $auth_notice = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In · UniStay</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Fraunces:ital,opsz,wght@0,9..144,100..900;1,9..144,100..900&display=swap" rel="stylesheet">
    <style id="auth-tailwind-login">
    /* auth-tailwind:login:start */
:root{--topbar-height:72px;--topbar-bg:#fdfaf5;--topbar-head:#6b3624}.logo{display:flex;align-items:center;gap:10px;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:19px;color:var(--topbar-head)}.logo:hover{scale:1}.topbar{position:fixed;top:0;left:0;right:0;z-index:10;height:var(--topbar-height);background:var(--topbar-bg);box-shadow:0 2px 4px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:space-between;padding:0 16px;font-family:DM Sans,sans-serif}.topbar a{transition:.2s}.topbar .logo img{width:54px;height:54px}.topbar .links{position:absolute;left:50%;transform:translateX(-50%);display:flex;gap:48px;font-size:13px;font-weight:500}.topbar .actions{display:flex;gap:12px}.topbar .btn-theme-toggle{border:0!important;color:inherit}.topbar .btn-theme-toggle:hover{transform:scale(1.08)}.topbar .logo{gap:10px}.topbar .logo-name{margin-right:0}@media (max-width:900px){.topbar{padding:0 20px}.topbar .links{display:none}}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body{background:var(--bg);color:var(--text)}[data-theme=dark] .shell{background:#382a22!important;border-color:#3d2d24!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .bar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar{background:rgba(30,22,18,.96);border-bottom-color:var(--line);box-shadow:0 4px 18px rgba(0,0,0,.35)}[data-theme=dark] .admin-topbar h1,[data-theme=dark] .bar .logo,[data-theme=dark] .topbar h1,[data-theme=dark] .topbar h2,[data-theme=dark] h1,[data-theme=dark] h2,[data-theme=dark] h3,[data-theme=dark] h4{color:var(--head)}[data-theme=dark] .btn-theme-toggle{background:#2b1f19;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-theme-toggle:hover{background:#382921;border-color:var(--brown);color:var(--brown)}[data-theme=dark] .admin-sidebar,[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#32221b,#241813);border-right-color:#3d2d24}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08);color:#fff7f2}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14);color:#d9c8bc;font-weight:700}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .current-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .room-card,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .settings-modal-card,[data-theme=dark] .stat-card,[data-theme=dark] dialog{background:#2b1f19;border-color:var(--line);color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,.4)}[data-theme=dark] .dorm-card,[data-theme=dark] .dorm-card-body{background:#382a22;border-color:#4b382d;color:#d9c8bc}[data-theme=dark] .current-booking-body,[data-theme=dark] .dorm-modal-body,[data-theme=dark] .dorm-modal-header,[data-theme=dark] .landlord-profile-card-rect{background:#2b1f19;color:var(--text)}[data-theme=dark] .dorm-card-title{color:#fff7f2}[data-theme=dark] .profile-name,[data-theme=dark] .profile-switch-header,[data-theme=dark] .settings-section-title,[data-theme=dark] .stat-card-value{color:var(--head)}[data-theme=dark] .dorm-card-amenity{background:#231a14;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .admin-user-badge,[data-theme=dark] .topbar-categories-bar{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea{background:#231a14!important;color:var(--ink)!important;border-color:var(--line)!important}[data-theme=dark] .um-select-menu li{color:var(--text)}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f;color:var(--ink)}[data-theme=dark] .settings-tabs{background:#241a15;border-color:var(--line)}[data-theme=dark] .settings-tab-btn{color:#bba89d}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .settings-tab-btn[aria-selected=true]{color:var(--ink)}[data-theme=dark] .settings-tabs-indicator{background:var(--brown)}[data-theme=dark] .switch-slider{background-color:#4b382d}[data-theme=dark] .switch-toggle input:checked+.switch-slider{background-color:var(--brown)}[data-theme=dark] .profile-account-item{border-color:var(--line)}[data-theme=dark] .profile-account-item:hover{background:#34261f}[data-theme=dark] .profile-account-item strong{color:var(--head)}[data-theme=dark] .skeleton-card,[data-theme=dark] .skeleton-shimmer{background:linear-gradient(90deg,#2b1f19 25%,#382921 50%,#2b1f19 75%);background-size:200% 100%}[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .settings-modal-overlay,[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}html[data-theme=dark] dialog:not(.onboarding-toast-modal):not(.guide-modal):not(.landlord-profile-modal):not(.us-confirm):not(.rm-modal){position:fixed;inset:0;margin:auto}html[data-theme=dark] #modalOverlay,html[data-theme=dark] .cropper-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal,html[data-theme=dark] .preview-modal,html[data-theme=dark] .settings-modal-overlay{align-items:center!important;justify-content:center!important}html[data-theme=dark] #modalOverlay>.modal-card,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .preview-modal-box,html[data-theme=dark] .settings-modal-card{margin:auto}[data-theme=dark] .notification-toast{background:#32241d;border:1px solid var(--line);color:var(--ink);box-shadow:0 16px 36px rgba(0,0,0,.55)}[data-theme=dark] .notification-toast strong{color:var(--head)}[data-theme=dark] .notification-toast span{color:var(--text)}[data-theme=dark] .wrap section{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .crumb{color:#ab978c}[data-theme=dark] .foot{background:#18110d;color:#e2d3c8;border-top:1px solid var(--line)}[data-theme=dark] .feat.feature-hero{background:radial-gradient(circle at 50% 20%,#2b1f19 0,#1e1612 100%)}[data-theme=dark] .sec{background:#1e1612}[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--line)}[data-theme=dark] .tab{color:#bba89d}[data-theme=dark] .tab.active{color:#fff7f2}[data-theme=dark] .tab-indicator{background:var(--brown)}[data-theme=dark] .panel{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .hero-wrap.photo-section{background:#1e1612}[data-theme=dark] .hero-copy{color:var(--text)}[data-theme=dark] .hero-copy h2{color:var(--head)}[data-theme=dark] .sq{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .sq-badge{background:rgba(30,22,18,.85);color:var(--ink);border-color:var(--line)}[data-theme=dark] .sq-num{color:var(--head)}[data-theme=dark] .apply-dialog{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right-color:var(--line)}[data-theme=dark] .apply-ws-right{background:#2b1f19}[data-theme=dark] .la-label{color:#d1b8ab}[data-theme=dark] .la-input{background:#231a14!important;border-color:var(--line)!important;color:var(--ink)!important}[data-theme=dark] body{background-color:#1e1612}[data-theme=dark] .stage{background:#1e1612}[data-theme=dark] .card{background:#2b1f19;border-color:#563e33;box-shadow:0 16px 40px rgba(0,0,0,.45)}[data-theme=dark] .form-side{background:#2b1f19;color:#fbf5f0}[data-theme=dark] .form-side h2,[data-theme=dark] .form-side h3{color:#fbf5f0}[data-theme=dark] .field label,[data-theme=dark] .form-side .hint,[data-theme=dark] .tagline{color:#c5a898}[data-theme=dark] .control input,[data-theme=dark] .control select{background:#231914;border-color:#563e33;color:#d9c8bc}[data-theme=dark] .control input:focus,[data-theme=dark] .control select:focus{background:#281d17;border-color:var(--brown,#b85d34);color:#d9c8bc}[data-theme=dark] .control input::-moz-placeholder{color:#8a6c5d}[data-theme=dark] .control input::placeholder{color:#8a6c5d}[data-theme=dark] .control .lead-icon{stroke:#c5a898}[data-theme=dark] .btn-google{background:#231914;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-google:hover{background:#2f211a;border-color:#7a4b3a}[data-theme=dark] .actions--full .or:after,[data-theme=dark] .actions--full .or:before{background:#563e33}[data-theme=dark] .actions--full .or{color:#c5a898}[data-theme=dark] .back-link,[data-theme=dark] .btn-resend-link,[data-theme=dark] .forgot,[data-theme=dark] .form-footer a,[data-theme=dark] .resend-alt-link{color:#e2a07f}[data-theme=dark] .readonly-email-box{background-color:#231914!important;color:#c5a898!important;border-color:#563e33!important}[data-theme=dark]{--ds-title:#fff7f2;--ds-body:#d9c8bc;--ds-muted:#a8948a;--ds-nav:#d9c8bc;--ds-nav-hover:#fff;--ds-accent:#e2ab94;--ds-accent-hover:#efc2ae;--ds-accent-press:#cf967f;--ds-on-accent:#1e1612;--ds-accent-soft:hsla(18,57%,73%,.16);--ds-accent-light:#f3d2c3;--ds-page:#1e1612;--ds-surface:#2b1f19;--ds-footer:#150f0c;--ds-field:#231a14;--ds-field-line:#5c4639;--ds-line:#3d2d24;--topbar-bg:rgba(30,22,18,.96);--topbar-head:#fff7f2}html[data-theme=dark]{scrollbar-color:var(--ds-accent) transparent}html[data-theme=dark] ::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] ::-webkit-scrollbar-thumb{background:var(--ds-accent);border-color:#251c16}html[data-theme=dark] ::-webkit-scrollbar-thumb:hover{background:var(--ds-accent-hover)}html[data-theme=dark],html[data-theme=dark] .page,html[data-theme=dark] body{background:var(--ds-page)}html[data-theme=dark] .blob{display:none!important}html[data-theme=dark] .hero-wrap.photo-section,html[data-theme=dark] .sec{background:transparent}html[data-theme=dark] .feat-orb.o1,html[data-theme=dark] .feat-orb.o2,html[data-theme=dark] .feat-orb.o3{background:#4a2c1f;opacity:.35}html[data-theme=dark] .feat:before{background-image:radial-gradient(hsla(16,40%,61%,.28) 1.2px,transparent 1.4px)}html[data-theme=dark] .feat-ring{border-color:hsla(16,40%,61%,.35)}html[data-theme=dark] .topbar{border-bottom:1px solid var(--ds-line)}html[data-theme=dark] .topbar .logo,html[data-theme=dark] .topbar .logo-name{color:var(--ds-title)}html[data-theme=dark] .topbar .links a{color:var(--ds-nav)}html[data-theme=dark] .topbar .links a:focus-visible,html[data-theme=dark] .topbar .links a:hover{color:var(--ds-nav-hover)}html[data-theme=dark] .feat-brand,html[data-theme=dark] .feature-hero .feat-copy h1,html[data-theme=dark] .photo-section .hero-copy h2,html[data-theme=dark] .sec h2{color:var(--ds-title)}html[data-theme=dark] .feature-hero .feat-copy p,html[data-theme=dark] .hero-copy p,html[data-theme=dark] .hero-progress,html[data-theme=dark] .sec .desc{color:var(--ds-body)}html[data-theme=dark] .btn.primary,html[data-theme=dark] .loginbtn{background:var(--ds-accent);border-color:var(--ds-accent);color:var(--ds-on-accent);box-shadow:0 2px 10px rgba(0,0,0,.35)}html[data-theme=dark] .btn.primary:hover,html[data-theme=dark] .loginbtn:hover{background:var(--ds-accent-hover);border-color:var(--ds-accent-hover);color:var(--ds-on-accent)}html[data-theme=dark] .btn.primary:active,html[data-theme=dark] .loginbtn:active{background:var(--ds-accent-press);border-color:var(--ds-accent-press);color:var(--ds-on-accent)}html[data-theme=dark] .btn:not(.primary){background:transparent;border-color:var(--ds-accent-light);color:var(--ds-accent-light);box-shadow:none;filter:none}html[data-theme=dark] .btn:not(.primary):hover{background:var(--ds-accent-soft);color:#fff}html[data-theme=dark] .news button{background:var(--ds-accent);color:var(--ds-on-accent)}html[data-theme=dark] #sub-btn:not(:disabled):hover{background:var(--ds-accent-hover);color:var(--ds-on-accent);border-color:var(--ds-accent-hover)}html[data-theme=dark] #sub-btn:not(:disabled):active{background:var(--ds-accent-press)}html[data-theme=dark] #sub-btn:disabled,html[data-theme=dark] .news button:disabled{background:#3a2c24!important;color:#8f7a6d!important}html[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--ds-line)}html[data-theme=dark] .tab-indicator{background:var(--ds-accent)}html[data-theme=dark] .tab{color:var(--ds-body)}html[data-theme=dark] .tab:hover:not(.active){color:#fff}html[data-theme=dark] .tab.active{color:var(--ds-on-accent)}html[data-theme=dark] .hero-count,html[data-theme=dark] .hero-kicker{color:var(--ds-accent-light)}html[data-theme=dark] .hero-bars button:before{background:#4b382d}html[data-theme=dark] .hero-bars button.on:before{background:var(--ds-accent)}html[data-theme=dark] .sq-badge{background:rgba(30,22,18,.88);color:#f0cfc2;border-color:hsla(16,40%,61%,.45)}html[data-theme=dark] .sq-num{color:#fff}html[data-theme=dark] .feat-icon-float{background:rgba(43,31,25,.92);border-color:hsla(16,40%,61%,.35);color:var(--ds-accent-light);box-shadow:0 14px 32px rgba(0,0,0,.45)}html[data-theme=dark] footer{background:var(--ds-footer);color:var(--ds-body);border-top:0}html[data-theme=dark] .f .logo{color:var(--ds-title)}html[data-theme=dark] .f p{color:#bfaea2}html[data-theme=dark] .f h4,html[data-theme=dark] .news b{color:#f3e3d8}html[data-theme=dark] .f li a{color:#bfaea2}html[data-theme=dark] .f li a:focus,html[data-theme=dark] .f li a:hover{color:var(--ds-accent-hover)}html[data-theme=dark] .f li a:active{color:var(--ds-accent-press)}html[data-theme=dark] .copy{color:var(--ds-muted);border-top:1px solid var(--ds-line)}html[data-theme=dark] .news-msg{color:var(--ds-body)}html[data-theme=dark] .news-msg.error{color:#ff9b8a}html[data-theme=dark] .news-msg.success{color:#8fdca0}html[data-theme=dark] input:not([type=checkbox]):not([type=radio]):not([type=file]),html[data-theme=dark] textarea{border-color:var(--ds-field-line)!important}html[data-theme=dark] input::-moz-placeholder,html[data-theme=dark] textarea::-moz-placeholder{color:#8a7569}html[data-theme=dark] input::placeholder,html[data-theme=dark] textarea::placeholder{color:#8a7569}html[data-theme=dark] input:not([type=checkbox]):not([type=radio]):not([type=file]):focus,html[data-theme=dark] textarea:focus{outline:none;border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)}html[data-theme=dark] :focus-visible{outline-color:var(--ds-accent-hover)}html[data-theme=dark] .apply-dialog{background:var(--dark-form-surface,#382a22);border-color:var(--ds-line);color:var(--ds-body);box-shadow:0 28px 70px rgba(0,0,0,.6)}html[data-theme=dark] .apply-dialog::backdrop{background:rgba(12,8,6,0)}html[data-theme=dark] .apply-dialog[open].is-open::backdrop{background:rgba(12,8,6,.72)}html[data-theme=dark] #landlord-form .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right:1px solid var(--ds-line);color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-subtitle,html[data-theme=dark] #landlord-form .apply-ws-title{color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-subtitle{color:#e6d3c7}html[data-theme=dark] #landlord-form .apply-ws-left .la-label{color:#e6d3c7!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-input,html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper{background:var(--ds-field)!important;border:1.5px solid var(--ds-field-line)!important;color:var(--ds-title)!important;box-shadow:none}html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper .la-input{border:none!important;background:transparent!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-input:focus,html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper:focus-within{border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-addon,html[data-theme=dark] #landlord-form .la-prefix-addon{background:#2d201a;color:#e6d3c7;border-right:1px solid var(--ds-field-line)}html[data-theme=dark] #landlord-form .apply-ws-right{background:var(--dark-form-surface,#382a22)}html[data-theme=dark] #landlord-form .apply-right-title{color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-right .la-label{color:#e6d3c7}html[data-theme=dark] #landlord-form .apply-ws-right .la-textarea,html[data-theme=dark] #landlord-form .la-input{background:var(--ds-field)!important;border:1px solid var(--ds-field-line)!important;color:var(--ds-title)!important}html[data-theme=dark] #landlord-form .la-input::-moz-placeholder{color:#8a7569}html[data-theme=dark] #landlord-form .la-input::placeholder{color:#8a7569}html[data-theme=dark] #landlord-form .la-input:focus{border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] #landlord-form .apply-ws-right .la-hint,html[data-theme=dark] #landlord-form .la-dz-hint,html[data-theme=dark] #landlord-form .la-hint{color:var(--ds-muted)}html[data-theme=dark] #landlord-form .apply-ws-right .la-dropzone,html[data-theme=dark] #landlord-form .la-dropzone{background:#231a14;border:2px dashed var(--ds-field-line)}html[data-theme=dark] #landlord-form .la-dropzone.dragover,html[data-theme=dark] #landlord-form .la-dropzone:focus-visible,html[data-theme=dark] #landlord-form .la-dropzone:hover{background:#2d201a;border-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-icon{color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-filename{color:var(--ds-title)}html[data-theme=dark] #landlord-form .la-dz-preview-wrap{background:#2b1f19;border-color:var(--ds-field-line)}html[data-theme=dark] #landlord-form .la-dz-btn{background:#34261f;border-color:var(--ds-field-line);color:#f0cfc2}html[data-theme=dark] #landlord-form .la-dz-btn:hover{background:#3f2f26;border-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-btn-danger{color:#ff9b8a;border-color:hsla(9,100%,77%,.4)}html[data-theme=dark] #landlord-form .la-dz-btn-danger:hover{background:hsla(9,100%,77%,.12);color:#ffb4a6}html[data-theme=dark] #landlord-form .apply-agreement{background:#231a14;border:1px solid var(--ds-field-line);color:var(--ds-body)}html[data-theme=dark] #landlord-form .apply-agreement input{accent-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .apply-modal-footer .btn-apply-cancel{background:transparent;border:1px solid var(--ds-field-line);color:var(--ds-body)}html[data-theme=dark] #landlord-form .apply-modal-footer .btn-apply-cancel:hover{background:#34261f;color:#fff}html[data-theme=dark] #landlord-form .apply-actions .btn:disabled,html[data-theme=dark] #landlord-form .apply-actions .btn:disabled:hover{background:var(--ds-accent);border-color:var(--ds-accent);color:var(--ds-on-accent);opacity:.6}html[data-theme=dark] #landlord-form .apply-close{color:var(--ds-accent-hover)}html[data-theme=dark] #landlord-form .apply-close:hover{color:#fff}html[data-theme=dark] .apply-msg.error{color:#ff9b8a}html[data-theme=dark] .apply-msg.success{color:#8fdca0}html[data-theme=dark] .apply-msg.info{color:var(--ds-body)}html[data-theme=dark] .apply-dialog{scrollbar-color:var(--ds-field-line) transparent}html[data-theme=dark] .apply-dialog::-webkit-scrollbar-thumb{background:var(--ds-field-line);border-color:var(--ds-surface)}.topbar .actions{align-items:center}.topbar .btn-theme-toggle{background:transparent!important;border:transparent!important;box-shadow:none!important;color:var(--ink,#3b2820);cursor:pointer;transition:transform .18s ease,color .18s ease;width:28px!important;height:34px!important;padding:0!important;margin-right:14px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}.topbar .btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}.topbar .btn-theme-toggle svg circle,.topbar .btn-theme-toggle svg path{transition:fill .18s ease,stroke .18s ease}.topbar .btn-theme-toggle:focus-visible,.topbar .btn-theme-toggle:hover{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#7a4b3a;transform:scale(1.08)}.topbar .btn-theme-toggle:hover svg path{fill:currentColor}.topbar .btn-theme-toggle:active{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#5c3527;transform:scale(.92)}.topbar .btn-theme-toggle:active svg path{fill:currentColor}html[data-theme=dark] .topbar .btn-theme-toggle{background:transparent!important;border:transparent!important;box-shadow:none!important;color:var(--ds-nav,#c5a898)}html[data-theme=dark] .topbar .btn-theme-toggle:focus-visible,html[data-theme=dark] .topbar .btn-theme-toggle:hover{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#f59e0b;transform:scale(1.08)}html[data-theme=dark] .topbar .btn-theme-toggle:hover svg circle{fill:currentColor}html[data-theme=dark] .topbar .btn-theme-toggle:active{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#d97706;transform:scale(.92)}html[data-theme=dark] .topbar .btn-theme-toggle:active svg circle{fill:currentColor}html[data-theme=dark] .notification-toast{background:#f6ebe2;border:1px solid #e3cfc1;color:#2b1a12;box-shadow:0 16px 36px rgba(0,0,0,.5)}html[data-theme=dark] .notification-toast strong{color:#2b1a12}html[data-theme=dark] .notification-toast span{color:#4a342b}html[data-theme=dark] .notification-toast .toast-close-btn{color:#7a4b3a}html[data-theme=dark] .notification-toast .toast-close-btn:hover{color:#2b1a12}.sq-num{display:none!important}html[data-theme=dark] .feat-icon-float,html[data-theme=dark] .feat-icon-float svg,html[data-theme=dark] .feature-hero .feat-chip svg{color:var(--ds-body)}html[data-theme=dark] .feat-actions .landlord-application-cta{font-weight:500}html[data-theme=dark] .feat-actions .landlord-application-cta:active,html[data-theme=dark] .feat-actions .landlord-application-cta:focus-visible,html[data-theme=dark] .feat-actions .landlord-application-cta:hover{font-weight:700}:root{--cream:#fdfaf5;--brown:#7a4b3a;--brown-light:#98614b;--brown-dark:#5f3425;--brown-darker:#452618;--ink:#1f1a17;--text:#3b312c;--blush:#e9b8a3;--field-bg:#f5f1ec;--field-line:#d9c9bd;--muted:#9a8c84}*,:after,:before{box-sizing:border-box}body,html{margin:0;min-height:100%}body{min-height:100vh;display:flex;flex-direction:column;padding-top:var(--topbar-height,72px);background:var(--cream);color:var(--text);font:15px/1.4 DM Sans,Segoe UI,system-ui,sans-serif}a{color:inherit;text-decoration:none;transition:.2s}a:hover{scale:1.1}button,input{font:inherit}:focus-visible{outline:3px solid #ac7728;outline-offset:3px}svg{width:20px;height:20px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.stage{position:relative;flex:1 1 auto;display:grid;place-items:center;align-content:center;justify-content:center;min-height:calc(100vh - var(--topbar-height, 72px));padding:0 20px;background:radial-gradient(60% 45% at 30% 10%,#fff 0,hsla(0,0%,100%,0) 70%),radial-gradient(40% 35% at 95% 40%,#fffdfa 0,hsla(0,0%,100%,0) 70%),var(--cream);grid-template-columns:minmax(0,1125px)}.card,.stage{overflow:hidden}.card{display:grid;grid-template-columns:529fr 596fr;width:100%;max-width:1125px;min-height:499px;background:#fff;border-radius:24px;box-shadow:6px 8px 8px rgba(60,40,30,.28)}@media (min-width:761px){.card{height:auto;grid-template-rows:auto}.card,.form-side{min-height:550px}.form-main{overflow:visible}}.panel{position:relative;display:flex;flex-direction:column;justify-content:center;padding:48px 63px;color:#fff;background:radial-gradient(55% 55% at 78% 8%,rgba(176,117,92,.85) 0,rgba(176,117,92,0) 100%),radial-gradient(60% 55% at 12% 100%,rgba(166,107,82,.9) 0,rgba(166,107,82,0) 100%),var(--brown)}.panel h1{margin:0 0 22px;font:700 clamp(42px,4.6vw,56px) /1.1 Fraunces,Georgia,Times New Roman,serif;letter-spacing:-.5px}.panel-copy{max-width:400px;margin:0 0 32px;color:var(--blush);font-size:18px;line-height:29px}.tagline{margin:0;font-size:13px;font-weight:700;letter-spacing:.55em;white-space:nowrap}.form-side{background:#fff}.form-main,.form-side{display:flex;flex-direction:column}.form-main{flex:1 1 auto;padding:40px 58px 0;min-height:0}.form-side h2{margin:0 0 20px;color:var(--ink);font-family:Fraunces,Georgia,serif;font-weight:700;font-size:32px;line-height:1.2;letter-spacing:-.5px}.field{margin-bottom:18px}.field label{display:block;margin-bottom:7px;color:var(--ink);font-size:14px;font-weight:500}.control{position:relative}.control input{width:100%;height:45px;padding:0 46px 0 44px;border:1px solid var(--field-line);border-radius:10px;background:var(--field-bg);color:var(--ink);font-size:15px}.control input::-moz-placeholder{color:var(--muted)}.control input::placeholder{color:var(--muted)}.control input:focus{outline:0;background:#fff;border-color:var(--brown);box-shadow:0 0 0 3px rgba(122,75,58,.15)}.control .lead-icon{left:14px;width:20px;height:20px;color:#5b4a42;transform:translateY(-50%);pointer-events:none}.control .lead-icon,.eye{position:absolute;top:50%}.eye{right:8px;display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:8px;background:transparent!important;color:#5b4a42;cursor:pointer;transform:translateY(-50%);transition:color .15s ease}.eye:active,.eye:focus,.eye:focus-visible,.eye:hover{background:transparent!important;cursor:pointer;outline:none}.eye:active,.eye:hover{color:#20130d}.eye:active svg,.eye:hover svg{stroke-width:2.6}.eye .slash{display:none}.eye[aria-pressed=true] .slash,.forgot{display:block}.forgot{width:-moz-max-content;width:max-content;margin:8px 0 0 auto;color:var(--brown,#7a4b3a);font-size:14px;font-weight:700;text-decoration:none;transition:color .15s ease}.forgot:active,.forgot:hover{scale:1;font-weight:700!important;color:var(--brown-dark,#5f3425)!important}.forgot:active{color:var(--brown-darker,#452618)!important}.alert{margin:0 0 16px;padding:10px 14px;border:1px solid #e5b8b2;border-radius:10px;background:#fbeeec;color:#9d3028;font-size:14px}.hint{color:var(--muted)}.notice{margin:0 0 16px;padding:10px 14px;border:1px solid #bcd9c5;border-radius:10px;background:#eff7f1;color:#1f6b3a;font-size:14px}.back-link{color:var(--muted);font-size:14px;font-weight:600}.back-link:active,.back-link:hover{scale:1;color:var(--brown-dark)}.actions{display:grid;justify-items:center;gap:12px;margin-top:14px}.actions.is-left{justify-items:start}.btn-login{min-width:80px;height:45px;padding:0 20px;border:1px solid var(--brown);border-radius:8px;background:var(--brown);color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:.2s}.btn-login:hover{color:#fff;background:var(--brown-dark);border-color:var(--brown-dark);scale:1.1}.btn-login:active{color:#fff;background:var(--brown-darker);scale:1.1}.btn-google,a.btn-login{display:inline-flex;align-items:center;justify-content:center}.btn-google{gap:10px;width:200px;height:45px;border:1px solid var(--field-line);border-radius:10px;background:#fff;color:var(--brown);font-size:14px;font-weight:600;transition:.2s}.btn-google:hover{background:var(--cream);filter:brightness(.95)}.btn-google:active{filter:brightness(.9)}.btn-google svg{width:18px;height:18px;stroke:none}.actions--full{justify-items:stretch}.actions--full .btn-google,.actions--full .btn-login{width:100%;border-radius:10px}.actions--full .btn-google:hover,.actions--full .btn-login:active,.actions--full .btn-login:hover{scale:1.02}.actions--full .or{text-align:center}.paths{display:block;margin:auto 58px 0;padding:0;border-top:1px solid #dfcdbf}.paths-row{display:flex;align-items:center;justify-content:flex-start;gap:8px;padding:22px 0 26px;font-size:14px;color:var(--ink);white-space:nowrap}.paths-left{text-align:left}.btn-signup{display:inline;height:auto;padding:0;border:0;border-radius:0;background:none;box-shadow:none;color:var(--brown);font-size:14px;font-weight:700;text-decoration:none;cursor:pointer;transition:color .18s ease}.btn-signup:hover{color:#4e2c20;scale:none;filter:none}.btn-signup:active{color:#351c14;scale:none;filter:none}.signed-in{display:grid;justify-items:center;gap:8px;margin:auto 0;padding-bottom:40px;text-align:center}.signed-in .avatar{width:72px;height:72px;border:2px solid var(--brown);border-radius:50%;-o-object-fit:cover;object-fit:cover;background:var(--field-bg)}.signed-in .avatar--initials{display:grid;place-items:center;background:var(--brown);color:#fff;font-size:24px;font-weight:700;letter-spacing:.5px}.signed-in h2{margin:8px 0 0;font-size:26px;font-family:Fraunces,Georgia,serif;font-weight:700}.signed-in p{margin:0 0 12px;color:var(--muted)}.signed-in .signed-in-actions{display:flex;flex-direction:column;align-items:center;gap:20px;width:100%;max-width:320px;margin-top:12px}.signed-in .btn-login{display:inline-flex;align-items:center;justify-content:center;padding:0 24px}.signed-in .btn-continue{gap:10px;width:100%;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--brown) 0,#4a2416 100%);color:#fff;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(66,32,19,.28);text-decoration:none;border:none;cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);letter-spacing:.01em}.signed-in .btn-continue:hover{background:linear-gradient(135deg,var(--brown-dark) 0,#3a1c11 100%);transform:translateY(-1px);box-shadow:0 6px 18px rgba(66,32,19,.38);scale:1.01}.signed-in .btn-continue:active{transform:translateY(0);scale:.99}.signed-in .signed-in-secondary-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%}.signed-in .btn-signed-in-secondary{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:38px;padding:0 10px;border-radius:10px;background:#fbf6f2;border:1px solid #ebd9cb;color:#63331b;font-size:12.5px;font-weight:600;text-decoration:none;transition:all .18s cubic-bezier(.16,1,.3,1);white-space:nowrap}.signed-in .btn-signed-in-secondary:hover{background:#f3e8de;border-color:#dfc4b0;color:#3b1e10;transform:translateY(-1px);box-shadow:0 3px 10px rgba(66,32,19,.08)}.signed-in .btn-signed-in-secondary:active{transform:translateY(0);background:#ebdccf}.signed-in .btn-signed-in-secondary svg{flex-shrink:0;stroke:currentColor;opacity:.85}@media (max-width:960px){.paths{margin:auto 36px 0}.paths-row{padding:16px 0 24px;flex-wrap:wrap;align-items:center;justify-content:flex-start}}@media (max-width:760px){.paths{margin:auto 24px 0}.paths-row{padding:16px 0 24px}.stage{padding:28px 14px 48px}.card{grid-template-columns:1fr;border-radius:20px}.panel{padding:36px 28px}.panel h1{font-size:40px}.panel-copy{font-size:16px;line-height:26px}.form-main{padding:32px 24px 0}.form-main h2{font-size:28px}.paths-row{padding:16px 24px 24px;flex-direction:row;justify-content:flex-start;align-items:center;white-space:nowrap}}@media (prefers-reduced-motion:reduce){*{transition:none!important}}.alert--success{border-color:#b9d8bf;background:#eef7ef;color:#2c6b3a}.hint{margin:0 0 18px;color:#6b5e57;font-size:14px;line-height:1.5}.rules{margin:6px 0 0;color:var(--muted);font-size:12px}.signed-in .avatar{margin:0 auto 12px!important}.signed-in .avatar--initials{display:flex!important;align-items:center!important;justify-content:center!important;text-align:center!important;line-height:1!important;box-sizing:border-box!important;margin:0 auto 12px!important;font-size:26px!important;font-weight:700!important}.resend-box{margin:18px 0;padding:14px 16px;background:#fbf6f0;border:1px dashed #ebd9cb;border-radius:10px;text-align:center}.resend-box .hint{margin:0 0 8px;font-size:.9rem}.btn-resend-link{background:none;border:none;padding:0;font-family:inherit;font-size:.92rem;font-weight:700;color:var(--brown,#7a4b3a);text-decoration:underline;cursor:pointer;transition:color .15s ease}.btn-resend-link:hover{color:var(--brown-dark,#5f3425)}.resend-alt-link{font-size:.92rem;font-weight:600;color:var(--brown,#7a4b3a);text-decoration:underline;transition:color .15s ease}.resend-alt-link:hover{color:var(--brown-dark,#5f3425)}.btn-landing-primary{font:600 13.5px DM Sans,sans-serif;padding:0 22px;height:42px;border-radius:9px;border:1px solid var(--brown,#7a4b3a);background:var(--brown,#7a4b3a);color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none!important;transition:all .2s ease;width:100%;box-sizing:border-box}.btn-landing-primary:hover{background:var(--brown-dark,#5f3425);border-color:var(--brown-dark,#5f3425);color:#fff}.btn-landing-primary:active{background:var(--brown-darker,#452618);border-color:var(--brown-darker,#452618)}.btn-landing-primary:disabled{opacity:.65;cursor:not-allowed;pointer-events:none}.btn-landing-outline{font:600 13.5px DM Sans,sans-serif;padding:0 22px;height:42px;border-radius:9px;border:1px solid var(--field-line,#dfcdbf);background:var(--bg,#fbf7f4);color:var(--brown,#7a4b3a);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none!important;transition:all .2s ease;width:100%;box-sizing:border-box}.btn-landing-outline:hover{background:#f5ece4;border-color:var(--brown,#7a4b3a);color:var(--brown-dark,#5f3425)}.btn-landing-outline:active{background:#ede0d5}.btn-landing-outline:disabled{opacity:.65;cursor:not-allowed;pointer-events:none}.reset-success-text{font-family:DM Sans,sans-serif;font-size:15px;font-weight:500;line-height:1.5;color:var(--ink,#3b312c);margin:10px 0 24px;background:none!important;border:none!important;padding:0!important}.actions--stack{display:flex;flex-direction:column;gap:10px;width:100%;margin-top:14px}.actions--stack .btn-landing-outline,.actions--stack .btn-landing-outline:active,.actions--stack .btn-landing-outline:hover,.actions--stack .btn-landing-primary,.actions--stack .btn-landing-primary:active,.actions--stack .btn-landing-primary:hover,.actions--stack .btn-login,.actions--stack .btn-login:active,.actions--stack .btn-login:hover,.actions--stack a,.actions--stack a:active,.actions--stack a:hover,.actions--stack button,.actions--stack button:active,.actions--stack button:hover,.btn-landing-outline,.btn-landing-outline:active,.btn-landing-outline:hover,.btn-landing-primary,.btn-landing-primary:active,.btn-landing-primary:hover{scale:1!important;transform:none!important}html[data-theme=dark] .stage,html[data-theme=dark] body{background:var(--ds-page,#1e1612);color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .topbar{background:var(--topbar-bg,rgba(30,22,18,.96));border-bottom:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .topbar .logo,html[data-theme=dark] .topbar .logo span{color:var(--ds-title,#fff7f2)!important}html[data-theme=dark] .card{background:var(--ds-surface,#2b1f19);border:1px solid var(--ds-line,#3d2d24);box-shadow:0 16px 48px rgba(0,0,0,.5)}html[data-theme=dark] .panel{background:linear-gradient(135deg,#32221b,#241813);border-right:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .panel h1{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .tagline{color:var(--ds-muted,#a8948a)}html[data-theme=dark] .form-side{background:var(--dark-form-surface,#382a22)!important}html[data-theme=dark] .form-side h2{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .btn-signup,html[data-theme=dark] .eye,html[data-theme=dark] .eye svg,html[data-theme=dark] .field label,html[data-theme=dark] .forgot,html[data-theme=dark] .form-main .hint,html[data-theme=dark] .hint,html[data-theme=dark] .panel-copy,html[data-theme=dark] .paths-left,html[data-theme=dark] .paths-row,html[data-theme=dark] .resend-box .hint{color:var(--ds-body,#d9c8bc)!important}html[data-theme=dark] .control input::-moz-placeholder{color:var(--ds-body,#d9c8bc)!important;opacity:.9}html[data-theme=dark] .control input::placeholder{color:var(--ds-body,#d9c8bc)!important;opacity:.9}html[data-theme=dark] .control input{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639);color:var(--ds-body,#d9c8bc)!important}html[data-theme=dark] .control input:focus{border-color:var(--ds-accent,#e2ab94);background:#281d17;color:var(--ds-body,#d9c8bc)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)}html[data-theme=dark] .control .lead-icon{color:var(--ds-body,#d9c8bc);stroke:var(--ds-body,#d9c8bc)}html[data-theme=dark] .eye{background:transparent!important;cursor:pointer!important}html[data-theme=dark] .eye:active,html[data-theme=dark] .eye:focus,html[data-theme=dark] .eye:focus-visible,html[data-theme=dark] .eye:hover{background:transparent!important;cursor:pointer!important;color:#8c6e5e!important;outline:none}html[data-theme=dark] .eye:active svg,html[data-theme=dark] .eye:hover svg{stroke:#8c6e5e!important;stroke-width:2.6!important}html[data-theme=dark] .forgot:active,html[data-theme=dark] .forgot:hover{color:#8c6e5e!important;font-weight:700!important}html[data-theme=dark] .btn-signup:active,html[data-theme=dark] .btn-signup:hover{color:var(--ds-accent-hover,#efc2ae)!important}html[data-theme=dark] .btn-login{background:var(--ds-accent,#e2ab94);border-color:var(--ds-accent,#e2ab94);color:var(--ds-on-accent,#1e1612);font-weight:700}html[data-theme=dark] .btn-login:hover{background:var(--ds-accent-hover,#efc2ae);border-color:var(--ds-accent-hover,#efc2ae);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-login:active{background:var(--ds-accent-press,#cf967f);border-color:var(--ds-accent-press,#cf967f);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-google{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639);color:var(--ds-title,#fff7f2)}html[data-theme=dark] .btn-google:hover{background:#2c2019;border-color:var(--ds-accent,#e2ab94);color:#fff}html[data-theme=dark] .actions--full .or{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .actions--full .or:after,html[data-theme=dark] .actions--full .or:before{background:var(--ds-accent,#e2ab94)!important;opacity:.6}html[data-theme=dark] .paths{border-top:1px solid var(--ds-accent,#e2ab94)!important}html[data-theme=dark] .back-link{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .back-link:hover{color:var(--ds-accent-hover,#efc2ae)}html[data-theme=dark] .resend-box{background:#231914;border-color:#563e33}html[data-theme=dark] .btn-resend-link,html[data-theme=dark] .resend-alt-link{color:var(--ds-accent,#e2ab94)}html[data-theme=dark] .btn-resend-link:hover,html[data-theme=dark] .resend-alt-link:hover{color:var(--ds-accent-hover,#efc2ae)}html[data-theme=dark] .signed-in h2{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .signed-in p{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .signed-in .btn-signed-in-secondary{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639);color:var(--ds-title,#fff7f2)}html[data-theme=dark] .signed-in .btn-signed-in-secondary:hover{background:#2c2019;border-color:var(--ds-accent,#e2ab94);color:#fff}html[data-theme=dark] .alert{background:rgba(180,50,50,.2);border:1px solid rgba(220,80,80,.4);color:#ffb4b4}html[data-theme=dark] .alert--success{background:rgba(50,150,80,.2);border:1px solid rgba(80,200,120,.4);color:#a3e9c4}html[data-theme=dark] .btn-landing-primary{background:var(--ds-accent,#e2ab94);border-color:var(--ds-accent,#e2ab94);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-landing-primary:hover{background:var(--ds-accent-hover,#efc2ae);border-color:var(--ds-accent-hover,#efc2ae);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-landing-primary:active{background:var(--ds-accent-press,#cf967f);border-color:var(--ds-accent-press,#cf967f)}html[data-theme=dark] .btn-landing-outline{background:transparent;border:1px solid var(--ds-accent-light,#f3d2c3);color:var(--ds-accent-light,#f3d2c3)}html[data-theme=dark] .btn-landing-outline:hover{background:var(--ds-accent-soft,hsla(18,57%,73%,.16));color:#fff;border-color:var(--ds-accent-light,#f3d2c3)}html[data-theme=dark] .reset-success-text{color:var(--ds-body,#d9c8bc)!important}.notification-toast{position:fixed;z-index:100000;right:24px;bottom:24px;display:flex;flex-direction:column;justify-content:center;gap:8px;width:min(400px,calc(100vw - 32px));min-height:108px;box-sizing:border-box;padding:16px 20px;border:none;border-radius:16px;background:#ecdccb;color:#3b2820;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);opacity:0;transform:translateY(24px) scale(.96);pointer-events:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1)}.notification-toast.is-visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}.notification-toast.is-hiding{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.notification-toast .toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%}.notification-toast strong{font-size:15px;font-weight:700;color:#3b2820;line-height:1.35}.notification-toast span{font-size:13.5px;font-weight:400;color:#5a4236;line-height:1.45;display:block}.notification-toast .toast-close-btn{background:transparent;border:none;color:#7a4b3a;font-size:24px;line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;transition:color .15s,transform .15s}.notification-toast .toast-close-btn:hover{color:#3b2820;transform:scale(1.15)}.notification-toast .toast-actions{display:flex;align-items:center;gap:10px;margin-top:4px}@media (prefers-reduced-motion:reduce){.notification-toast,.notification-toast.is-hiding{transition:none}}@view-transition{navigation:auto}@keyframes page-fade-in{0%{opacity:0}to{opacity:1}}@keyframes page-fade-out{0%{opacity:1}to{opacity:0}}::view-transition-old(root){animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}::view-transition-new(root){animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-entering body{animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-leaving body{animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}@media (prefers-reduced-motion:reduce){::view-transition-new(root),::view-transition-old(root),html.page-entering body,html.page-leaving body{animation:none}}input:-webkit-autofill,input:-webkit-autofill:active,input:-webkit-autofill:focus,input:-webkit-autofill:hover,select:-webkit-autofill,select:-webkit-autofill:focus,select:-webkit-autofill:hover,textarea:-webkit-autofill,textarea:-webkit-autofill:focus,textarea:-webkit-autofill:hover{-webkit-transition:background-color 600000s 0s,color 600000s 0s;transition:background-color 600000s 0s,color 600000s 0s;-webkit-text-fill-color:currentColor;caret-color:currentColor}input:-webkit-autofill,select:-webkit-autofill,textarea:-webkit-autofill{filter:none;background-clip:padding-box}input:autofill,select:autofill,textarea:autofill{filter:none;background-clip:padding-box}.visible{visibility:visible}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.flex-shrink{flex-shrink:1}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.flex-wrap{flex-wrap:wrap}.border{border-width:1px}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.underline{text-decoration-line:underline}.outline{outline-style:solid}.blur{--tw-blur:blur(8px)}.blur,.filter{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}
/* auth-tailwind:login:end */
    </style>
    <script src="../assets/js/theme.js"></script>
</head>
<body>

    <nav class="topbar">
        <a class="logo" href="../index.php" aria-label="UniStay home">
            <img src="../assets/images/logo.png" alt="UniStay logo">
            <span>UniStay</span>
        </a>
        <div class="actions">
            <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle Dark Mode" title="Toggle Dark Mode"></button>
        </div>
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

                        <?php if ($forgot_error !== ''): ?>
                            <p class="alert" role="alert"><?= htmlspecialchars($forgot_error, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>

                        <form action="login.php?view=forgot" method="POST" id="forgotForm" novalidate>
                            <input type="hidden" name="action" value="forgot_password">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                            <div class="field">
                                <label for="forgot-email">Email</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input type="email" id="forgot-email" name="email" placeholder="example@email.com"
                                           value="<?= $forgot_sent ? '' : htmlspecialchars($forgot_email, ENT_QUOTES, 'UTF-8') ?>"
                                           autocomplete="email" <?= $forgot_sent ? '' : 'required' ?>>
                                </div>
                            </div>

                            <?php if ($forgot_sent): ?>
                                <input type="hidden" name="sent_email" id="sentEmail" value="<?= htmlspecialchars($forgot_email, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <div class="actions--stack">
                                <?php if ($forgot_sent): ?>
                                    <button type="submit" class="btn-landing-primary" id="btnForgotSubmit">Send again</button>
                                    <button type="button" class="btn-landing-outline" id="btnTryAnother">Try another email</button>
                                <?php else: ?>
                                    <button type="submit" class="btn-login" id="btnForgotSubmit">Send reset link</button>
                                <?php endif; ?>
                                <a class="btn-landing-outline" href="login.php">Back to sign in</a>
                            </div>
                        </form>

                        <?php if ($forgot_link !== ''): ?>
                            <div class="resend-box" style="margin-top: 18px;">
                                <p class="hint">Dev Mode (No local mail server): <a href="<?= htmlspecialchars($forgot_link, ENT_QUOTES, 'UTF-8') ?>" style="font-weight:700; text-decoration:underline;">Open reset link &rarr;</a></p>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($view === 'reset'): ?>
                        <h2>Set a new password</h2>

                        <?php if ($reset_done): ?>
                            <p class="reset-success-text">Password updated successfully!</p>

                            <div class="actions--stack">
                                <a class="btn-landing-outline" href="login.php">Back to sign in</a>
                            </div>
                        <?php elseif (!$reset_valid): ?>
                            <p class="alert" role="alert">This reset link is invalid or has expired. Please request a new one.</p>

                            <div class="actions--stack">
                                <a class="btn-login" href="login.php?view=forgot">Request a new link</a>
                                <a class="btn-landing-outline" href="login.php">Back to sign in</a>
                            </div>
                        <?php else: ?>
                            <p class="hint">Your new password must be 8-16 characters and include uppercase and lowercase letters, a number, and a symbol.</p>

                            <?php if ($reset_error !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($reset_error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <form action="login.php?view=reset&amp;token=<?= htmlspecialchars($reset_token, ENT_QUOTES, 'UTF-8') ?>" method="POST" id="resetForm">
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

                                <div class="actions--stack">
                                    <button type="submit" class="btn-login" id="btnResetSubmit">Update password</button>
                                    <a class="btn-landing-outline" href="login.php">Back to sign in</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($is_logged_in): ?>
                        <div class="signed-in">
                            <?php if ($user_photo !== ''): ?>
                                <img class="avatar" src="<?= htmlspecialchars($user_photo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>" referrerpolicy="no-referrer" onerror="this.outerHTML='<span class=\'avatar avatar--initials\' aria-hidden=\'true\' style=\'<?= $user_color ? 'background-color:' . htmlspecialchars($user_color, ENT_QUOTES, 'UTF-8') . ';' : '' ?>\'><?= htmlspecialchars($user_initials !== '' ? $user_initials : 'U', ENT_QUOTES, 'UTF-8') ?></span>';">

                            <?php elseif ($user_initials !== ''): ?>
                                <span class="avatar avatar--initials" aria-hidden="true" style="<?= $user_color ? 'background-color:' . htmlspecialchars($user_color, ENT_QUOTES, 'UTF-8') . ';' : '' ?>"><?= htmlspecialchars($user_initials, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="avatar avatar--initials" aria-hidden="true" style="<?= $user_color ? 'background-color:' . htmlspecialchars($user_color, ENT_QUOTES, 'UTF-8') . ';' : '' ?>">U</span>
                            <?php endif; ?>
                            <h2>Welcome back!</h2>
                            <?php if ($user_name !== '' && $user_name !== $user_email): ?>
                                <p><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>)</p>
                            <?php else: ?>
                                <p><?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <div class="signed-in-actions">
                                <a href="<?= htmlspecialchars($continue_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-login btn-continue">
                                    <span>Continue</span>
                                </a>
                                <div class="signed-in-secondary-grid">
                                    <a href="login.php?action=switch" class="btn-signed-in-secondary" title="Switch to another account">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                                        <span>Switch account</span>
                                    </a>
                                    <a href="login.php?action=logout" class="btn-signed-in-secondary" title="Sign out of this account">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                        <span>Sign out</span>
                                    </a>
                                </div>
                            </div>
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
                                <label for="email">Email</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input type="email" id="email" name="email" placeholder="example@email.com"
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

                            <div class="actions actions--full">
                                <button type="submit" class="btn-login">Log in</button>
                                <span class="or">OR</span>
                                <a class="btn-google" id="googleSignUp" href="google_oauth.php">
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
                        <span class="paths-left">New to UniStay?</span>
                        <a class="btn-signup" href="register.php">Sign up</a>
                    </div>
                </div>
                <?php endif; ?>
            </section>


        </div>
    </main>

    <?php if ($view === 'forgot' && $forgot_sent): ?>
        <div class="notification-toast is-visible" id="forgotToast" role="alert">
            <div class="toast-header">
                <strong>Reset link sent!</strong>
                <button type="button" class="toast-close-btn" onclick="this.closest('.notification-toast').remove()" aria-label="Close notification">&times;</button>
            </div>
            <span>We emailed a link to <?= htmlspecialchars($forgot_email, ENT_QUOTES, 'UTF-8') ?>. Check your inbox to continue.</span>
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

    <?php if ($suspendedLandlordNotice !== ''): ?>
        <div class="notification-toast is-visible" id="suspendedLandlordToast" role="alert">
            <div class="toast-header"><strong>Account suspended</strong><button type="button" class="toast-close-btn" aria-label="Close notification" onclick="this.closest('.notification-toast').remove()">&times;</button></div>
            <span><?= htmlspecialchars($suspendedLandlordNotice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <script>setTimeout(() => document.getElementById('suspendedLandlordToast')?.remove(), 8000);</script>
    <?php endif; ?>

    <?php if ($pendingLandlordNotice !== ''): ?>
        <div class="notification-toast is-visible" id="pendingLandlordToast" role="alert">
            <div class="toast-header"><strong>Application Under Review</strong><button type="button" class="toast-close-btn" aria-label="Close notification" onclick="this.closest('.notification-toast').remove()">&times;</button></div>
            <span><?= htmlspecialchars($pendingLandlordNotice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <script>setTimeout(() => document.getElementById('pendingLandlordToast')?.remove(), 8000);</script>
    <?php endif; ?>

    <?php if ($declinedLandlordNotice !== ''): ?>
        <div class="notification-toast is-visible" id="declinedLandlordToast" role="alert">
            <div class="toast-header"><strong>Application Status</strong><button type="button" class="toast-close-btn" aria-label="Close notification" onclick="this.closest('.notification-toast').remove()">&times;</button></div>
            <span><?= htmlspecialchars($declinedLandlordNotice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <script>setTimeout(() => document.getElementById('declinedLandlordToast')?.remove(), 8000);</script>
    <?php endif; ?>

    <?php if ($unactivatedLandlordNotice !== ''): ?>
        <div class="notification-toast is-visible" id="unactivatedLandlordToast" role="alert">
            <div class="toast-header"><strong>Account Activation Required</strong><button type="button" class="toast-close-btn" aria-label="Close notification" onclick="this.closest('.notification-toast').remove()">&times;</button></div>
            <span><?= htmlspecialchars($unactivatedLandlordNotice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <script>setTimeout(() => document.getElementById('unactivatedLandlordToast')?.remove(), 8000);</script>
    <?php endif; ?>

    <?php if ($suspendedTenantNotice !== ''): ?>
        <div class="notification-toast is-visible" id="suspendedTenantToast" role="alert">
            <div class="toast-header"><strong>Account suspended</strong><button type="button" class="toast-close-btn" aria-label="Close notification" onclick="this.closest('.notification-toast').remove()">&times;</button></div>
            <span><?= htmlspecialchars($suspendedTenantNotice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <script>setTimeout(() => document.getElementById('suspendedTenantToast')?.remove(), 8000);</script>
    <?php endif; ?>

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

            // Forgot password form submission state
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

            // Try another email button handler
            var btnTryAnother = document.getElementById('btnTryAnother');
            if (btnTryAnother) {
                btnTryAnother.addEventListener('click', function () {
                    var emailInput = document.getElementById('forgot-email');
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

            // Reset password form submission state
            var resetForm = document.getElementById('resetForm');
            if (resetForm) {
                resetForm.addEventListener('submit', function () {
                    var btn = document.getElementById('btnResetSubmit');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = 'Updating...';
                    }
                });
            }
        })();
    </script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>
