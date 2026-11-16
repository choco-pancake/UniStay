<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/account_ids.php';

start_app_session();

$pdo = database();

$emailParam = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
$tokenParam = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$landlord = null;
$errorMessage = '';
$isApproved = false;

$adminProfile = null;
if (($_SESSION['role'] ?? '') === 'admin') {
    $adminProfile = session_user();
}
$isAdminCreating = ($adminProfile !== null && (isset($_GET['admin_create']) || isset($_POST['admin_create'])));

$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
if (!isset($_SESSION['admin_landlord_draft'])) {
    $_SESSION['admin_landlord_draft'] = [];
}

// Strictly prevent accessing Step 2 if required Step 1 details are missing
if ($isAdminCreating && $step === 2 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $draft = $_SESSION['admin_landlord_draft'] ?? [];
    if (empty($draft['first_name']) || empty($draft['last_name']) || empty($draft['phone'])) {
        header('Location: landlord-register.php?admin_create=1&step=1', true, 303);
        exit;
    }
}

$pending = $_SESSION['google_pending'] ?? null;
$isGoogle = is_array($pending) && !empty($pending['email']) && (($_GET['google'] ?? '') === '1');

if ($isAdminCreating && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['auth_csrf_token'] ?? '', $submittedToken)) {
        $errorMessage = 'Your session expired. Please refresh and try again.';
    } elseif ($step === 1) {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $rawPhone = trim((string)($_POST['phone'] ?? ''));
        $phoneDigits = preg_replace('/\D/', '', $rawPhone);
        if (str_starts_with($phoneDigits, '63')) {
            $phone = '+' . $phoneDigits;
        } elseif (str_starts_with($phoneDigits, '0')) {
            $phone = '+63' . substr($phoneDigits, 1);
        } elseif ($phoneDigits !== '') {
            $phone = '+63' . $phoneDigits;
        } else {
            $phone = '';
        }

        if ($firstName === '' || $lastName === '' || $phone === '') {
            $errorMessage = 'Please fill in First Name, Last Name, and Phone Number before proceeding to the next page.';
        } else {
            $_SESSION['admin_landlord_draft'] = [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'phone' => $phone,
            ];
            header('Location: landlord-register.php?admin_create=1&step=2', true, 303);
            exit;
        }
    } elseif ($step === 2) {
        $draft = $_SESSION['admin_landlord_draft'] ?? [];
        $firstName = trim((string)($draft['first_name'] ?? ''));
        $middleName = trim((string)($draft['middle_name'] ?? ''));
        $lastName = trim((string)($draft['last_name'] ?? ''));
        $phone = trim((string)($draft['phone'] ?? ''));

        if ($firstName === '' || $lastName === '' || $phone === '') {
            header('Location: landlord-register.php?admin_create=1&step=1', true, 303);
            exit;
        }

        $email = $isGoogle ? strtolower(trim((string)($pending['email'] ?? ''))) : strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $terms = isset($_POST['terms']);
        $photo = $isGoogle ? substr((string)($pending['picture'] ?? ''), 0, 255) : null;

        if (!$terms) {
            $errorMessage = 'Please agree to the Terms of Service, Code of Conduct, and Privacy Policy.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'A valid email address is required.';
        } elseif (!$isGoogle && !preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/', $password)) {
            $errorMessage = 'Password must be 8–16 characters with uppercase, lowercase, a number, and a symbol (no spaces).';
        } elseif (!$isGoogle && !hash_equals($password, $confirmPassword)) {
            $errorMessage = 'The passwords do not match.';
        } else {
            $chk = $pdo->prepare('SELECT 1 FROM landlords WHERE LOWER(email) = ? UNION SELECT 1 FROM tenants WHERE LOWER(email) = ? UNION SELECT 1 FROM admins WHERE LOWER(email) = ? LIMIT 1');
            $chk->execute([$email, $email, $email]);
            if ($chk->fetchColumn()) {
                $errorMessage = 'An account with this email address already exists.';
            } else {
                try {
                    $hash = (!$isGoogle && $password !== '') ? password_hash($password, PASSWORD_DEFAULT) : null;
                    $adminName = trim(($adminProfile['name'] ?? '') ?: 'Administrator');
                    $newLandlordId = next_account_id($pdo, 'landlords');
                    $ins = $pdo->prepare(
                        "INSERT INTO landlords (landlord_id, first_name, middle_name, last_name, email, phone_number, password_hash, profile_photo, status, approved_by, feedback, decided_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, 'Account directly created and verified in person by administrator.', NOW(), NOW())"
                    );
                    $ins->execute([$newLandlordId, $firstName, ($middleName !== '' ? $middleName : null), $lastName, $email, $phone, $hash, $photo, $adminName]);

                    unset($_SESSION['admin_landlord_draft'], $_SESSION['google_pending']);
                    $fullName = trim($firstName . ' ' . $lastName);
                    header('Location: ../admin/users.php?landlord_created=' . urlencode($fullName), true, 303);
                    exit;
                } catch (Throwable $e) {
                    $errorMessage = 'Could not create landlord account: ' . $e->getMessage();
                }
            }
        }
    }
} elseif ($emailParam !== '') {
    $stmt = $pdo->prepare("SELECT * FROM landlords WHERE LOWER(email) = ? LIMIT 1");
    $stmt->execute([$emailParam]);
    $landlord = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($landlord) {
        $status = strtolower((string)($landlord['status'] ?? ''));
        $dbToken = (string)($landlord['activation_token'] ?? '');

        if ($status !== 'approved') {
            $errorMessage = 'Your landlord application is currently ' . ($status === 'pending' ? 'under review' : $status) . '. You can only set your password once your application has been approved.';
        } elseif ($dbToken !== '' && $tokenParam !== '' && !hash_equals($dbToken, $tokenParam)) {
            $errorMessage = 'Invalid or expired activation link. Please check your approval email or contact support.';
        } else {
            $isApproved = true;
        }
    } else {
        $errorMessage = 'No landlord application found matching this email address.';
    }
}

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isApproved && $landlord) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $terms = isset($_POST['terms']);
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['auth_csrf_token'], $submittedToken)) {
        $errorMessage = 'Your session expired. Please refresh and try again.';
    } elseif (!$terms) {
        $errorMessage = 'Please agree to the Terms of Service, Code of Conduct, and Privacy Policy.';
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/', $password)) {
        $errorMessage = 'Password must be 8–16 characters with uppercase, lowercase, a number, and a symbol (no spaces).';
    } elseif (!hash_equals($password, $confirmPassword)) {
        $errorMessage = 'The passwords do not match.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE landlords SET password_hash = ?, activation_token = NULL WHERE landlord_id = ?");
            $upd->execute([$hash, $landlord['landlord_id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (string)$landlord['landlord_id'];
            $_SESSION['role'] = 'landlord';
            $_SESSION['user'] = [
                'id' => (string)$landlord['landlord_id'],
                'name' => trim(($landlord['first_name'] ?? '') . ' ' . ($landlord['last_name'] ?? '')) ?: $landlord['email'],
                'email' => $landlord['email'],
                'photo' => $landlord['profile_photo'] ?? '',
            ];
            remember_authenticated_account((string)$landlord['landlord_id'], 'landlord');

            $_SESSION['landlord_welcome_toast'] = 'Account activated! Welcome to the UniStay Landlord Portal.';
            header('Location: ../landlord/dashboard.php', true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Landlord password activation error: ' . $e->getMessage());
            $errorMessage = 'Could not activate account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Landlord Registration · UniStay</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Fraunces:ital,opsz,wght@0,9..144,100..900;1,9..144,100..900&display=swap" rel="stylesheet">
    <style id="auth-tailwind-landlord-register">
    /* auth-tailwind:landlord-register:start */
:root{--topbar-height:72px;--topbar-bg:#fdfaf5;--topbar-head:#6b3624}.logo{display:flex;align-items:center;gap:10px;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:19px;color:var(--topbar-head)}.logo:hover{scale:1}.topbar{position:fixed;top:0;left:0;right:0;z-index:10;height:var(--topbar-height);background:var(--topbar-bg);box-shadow:0 2px 4px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:space-between;padding:0 16px;font-family:DM Sans,sans-serif}.topbar a{transition:.2s}.topbar .logo img{width:54px;height:54px}.topbar .links{position:absolute;left:50%;transform:translateX(-50%);display:flex;gap:48px;font-size:13px;font-weight:500}.topbar .actions{display:flex;align-items:center;gap:12px}.topbar .btn-theme-toggle{width:28px!important;height:34px!important;padding:0!important;margin-right:14px!important;background:transparent!important;border:0!important;box-shadow:none!important;cursor:pointer;display:inline-flex!important;align-items:center!important;justify-content:center!important;color:inherit;transition:transform .18s ease,color .18s ease}.topbar .btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}.topbar .btn-theme-toggle:hover{transform:scale(1.08)}.topbar .btn-theme-toggle:active{transform:scale(.92)}.topbar .logo{gap:10px}.topbar .logo-name{margin-right:0}@media (max-width:900px){.topbar{padding:0 20px}.topbar .links{display:none}}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body{background:var(--bg);color:var(--text)}[data-theme=dark] .shell{background:#382a22!important;border-color:#3d2d24!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .bar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar{background:rgba(30,22,18,.96);border-bottom-color:var(--line);box-shadow:0 4px 18px rgba(0,0,0,.35)}[data-theme=dark] .admin-topbar h1,[data-theme=dark] .bar .logo,[data-theme=dark] .topbar h1,[data-theme=dark] .topbar h2,[data-theme=dark] h1,[data-theme=dark] h2,[data-theme=dark] h3,[data-theme=dark] h4{color:var(--head)}[data-theme=dark] .btn-theme-toggle{background:#2b1f19;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-theme-toggle:hover{background:#382921;border-color:var(--brown);color:var(--brown)}[data-theme=dark] .admin-sidebar,[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#32221b,#241813);border-right-color:#3d2d24}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08);color:#fff7f2}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14);color:#d9c8bc;font-weight:700}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .current-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .room-card,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .settings-modal-card,[data-theme=dark] .stat-card,[data-theme=dark] dialog{background:#2b1f19;border-color:var(--line);color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,.4)}[data-theme=dark] .dorm-card,[data-theme=dark] .dorm-card-body{background:#382a22;border-color:#4b382d;color:#d9c8bc}[data-theme=dark] .current-booking-body,[data-theme=dark] .dorm-modal-body,[data-theme=dark] .dorm-modal-header,[data-theme=dark] .landlord-profile-card-rect{background:#2b1f19;color:var(--text)}[data-theme=dark] .dorm-card-title{color:#fff7f2}[data-theme=dark] .profile-name,[data-theme=dark] .profile-switch-header,[data-theme=dark] .settings-section-title,[data-theme=dark] .stat-card-value{color:var(--head)}[data-theme=dark] .dorm-card-amenity{background:#231a14;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .admin-user-badge,[data-theme=dark] .topbar-categories-bar{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea{background:#231a14!important;color:var(--ink)!important;border-color:var(--line)!important}[data-theme=dark] .um-select-menu li{color:var(--text)}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f;color:var(--ink)}[data-theme=dark] .settings-tabs{background:#241a15;border-color:var(--line)}[data-theme=dark] .settings-tab-btn{color:#bba89d}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .settings-tab-btn[aria-selected=true]{color:var(--ink)}[data-theme=dark] .settings-tabs-indicator{background:var(--brown)}[data-theme=dark] .switch-slider{background-color:#4b382d}[data-theme=dark] .switch-toggle input:checked+.switch-slider{background-color:var(--brown)}[data-theme=dark] .profile-account-item{border-color:var(--line)}[data-theme=dark] .profile-account-item:hover{background:#34261f}[data-theme=dark] .profile-account-item strong{color:var(--head)}[data-theme=dark] .skeleton-card,[data-theme=dark] .skeleton-shimmer{background:linear-gradient(90deg,#2b1f19 25%,#382921 50%,#2b1f19 75%);background-size:200% 100%}[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .settings-modal-overlay,[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}html[data-theme=dark] dialog:not(.onboarding-toast-modal):not(.guide-modal):not(.landlord-profile-modal):not(.us-confirm):not(.rm-modal){position:fixed;inset:0;margin:auto}html[data-theme=dark] #modalOverlay,html[data-theme=dark] .cropper-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal,html[data-theme=dark] .preview-modal,html[data-theme=dark] .settings-modal-overlay{align-items:center!important;justify-content:center!important}html[data-theme=dark] #modalOverlay>.modal-card,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .preview-modal-box,html[data-theme=dark] .settings-modal-card{margin:auto}[data-theme=dark] .notification-toast{background:#32241d;border:1px solid var(--line);color:var(--ink);box-shadow:0 16px 36px rgba(0,0,0,.55)}[data-theme=dark] .notification-toast strong{color:var(--head)}[data-theme=dark] .notification-toast span{color:var(--text)}[data-theme=dark] .wrap section{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .crumb{color:#ab978c}[data-theme=dark] .foot{background:#18110d;color:#e2d3c8;border-top:1px solid var(--line)}[data-theme=dark] .feat.feature-hero{background:radial-gradient(circle at 50% 20%,#2b1f19 0,#1e1612 100%)}[data-theme=dark] .sec{background:#1e1612}[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--line)}[data-theme=dark] .tab{color:#bba89d}[data-theme=dark] .tab.active{color:#fff7f2}[data-theme=dark] .tab-indicator{background:var(--brown)}[data-theme=dark] .panel{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .hero-wrap.photo-section{background:#1e1612}[data-theme=dark] .hero-copy{color:var(--text)}[data-theme=dark] .hero-copy h2{color:var(--head)}[data-theme=dark] .sq{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .sq-badge{background:rgba(30,22,18,.85);color:var(--ink);border-color:var(--line)}[data-theme=dark] .sq-num{color:var(--head)}[data-theme=dark] .apply-dialog{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right-color:var(--line)}[data-theme=dark] .apply-ws-right{background:#2b1f19}[data-theme=dark] .la-label{color:#d1b8ab}[data-theme=dark] .la-input{background:#231a14!important;border-color:var(--line)!important;color:var(--ink)!important}[data-theme=dark] body{background-color:#1e1612}[data-theme=dark] .stage{background:#1e1612}[data-theme=dark] .card{background:#2b1f19;border-color:#563e33;box-shadow:0 16px 40px rgba(0,0,0,.45)}[data-theme=dark] .form-side{background:#2b1f19;color:#fbf5f0}[data-theme=dark] .form-side h2,[data-theme=dark] .form-side h3{color:#fbf5f0}[data-theme=dark] .field label,[data-theme=dark] .form-side .hint,[data-theme=dark] .tagline{color:#c5a898}[data-theme=dark] .control input,[data-theme=dark] .control select{background:#231914;border-color:#563e33;color:#d9c8bc}[data-theme=dark] .control input:focus,[data-theme=dark] .control select:focus{background:#281d17;border-color:var(--brown,#b85d34);color:#d9c8bc}[data-theme=dark] .control input::-moz-placeholder{color:#8a6c5d}[data-theme=dark] .control input::placeholder{color:#8a6c5d}[data-theme=dark] .control .lead-icon{stroke:#c5a898}[data-theme=dark] .btn-google{background:#231914;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-google:hover{background:#2f211a;border-color:#7a4b3a}[data-theme=dark] .actions--full .or:after,[data-theme=dark] .actions--full .or:before{background:#563e33}[data-theme=dark] .actions--full .or{color:#c5a898}[data-theme=dark] .back-link,[data-theme=dark] .btn-resend-link,[data-theme=dark] .forgot,[data-theme=dark] .form-footer a,[data-theme=dark] .resend-alt-link{color:#e2a07f}[data-theme=dark] .readonly-email-box{background-color:#231914!important;color:#c5a898!important;border-color:#563e33!important}:root{--cream:#fdfaf5;--brown:#7a4b3a;--brown-light:#98614b;--brown-dark:#5f3425;--brown-darker:#452618;--ink:#1f1a17;--text:#3b312c;--blush:#e9b8a3;--field-bg:#f5f1ec;--field-line:#d9c9bd;--muted:#9a8c84}*,:after,:before{box-sizing:border-box}body,html{margin:0;min-height:100%}body{min-height:100vh;display:flex;flex-direction:column;padding-top:var(--topbar-height,72px);background:var(--cream);color:var(--text);font:15px/1.4 DM Sans,Segoe UI,system-ui,sans-serif}a{color:inherit;text-decoration:none;transition:.2s}button,input{font:inherit}:focus-visible{outline:3px solid #ac7728;outline-offset:3px}svg{width:20px;height:20px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.stage{position:relative;flex:1 1 auto;display:grid;grid-template-columns:minmax(0,1125px);place-items:center;align-content:center;justify-content:center;min-height:calc(100vh - var(--topbar-height, 72px));padding:0 20px;background:radial-gradient(60% 45% at 30% 10%,#fff 0,hsla(0,0%,100%,0) 70%),radial-gradient(40% 35% at 95% 40%,#fffdfa 0,hsla(0,0%,100%,0) 70%),var(--cream)}.card,.stage{overflow:hidden}.card{display:grid;grid-template-columns:529fr 596fr;width:min(100%,1125px);max-width:1125px;min-height:499px;background:#fff;border-radius:24px;box-shadow:6px 8px 8px rgba(60,40,30,.28)}@media (min-width:761px){.card{height:auto;min-height:550px;grid-template-columns:529fr 596fr;grid-template-rows:auto}.form-side{min-height:550px;display:flex;flex-direction:column;justify-content:space-between}.form-main{overflow:visible;padding:30px 48px 0}}.panel{position:relative;display:flex;flex-direction:column;justify-content:center;padding:44px 56px;color:#fff;background:radial-gradient(55% 55% at 78% 8%,rgba(176,117,92,.85) 0,rgba(176,117,92,0) 100%),radial-gradient(60% 55% at 12% 100%,rgba(166,107,82,.9) 0,rgba(166,107,82,0) 100%),var(--brown)}.panel h1{margin:0 0 18px;font:700 clamp(36px,4vw,50px) /1.1 Fraunces,Georgia,Times New Roman,serif;letter-spacing:-.5px}.panel h1,.panel-copy{transition:opacity .2s ease}.panel-copy{max-width:380px;margin:0 0 26px;color:var(--blush);font-size:16.5px;line-height:26px}.tagline{margin:0 0 24px;font-size:13px;font-weight:700;letter-spacing:.55em;white-space:nowrap}.card-switch{margin:0;font-size:14px;color:#fff}.card-switch a{color:#fff;font-weight:700;text-decoration:none;margin-left:4px}.card-switch a:hover{color:#fff;opacity:.88;text-decoration:none}.form-side{display:flex;flex-direction:column;justify-content:space-between;background:#fff;height:100%;overflow:hidden}.form-main{justify-content:flex-start;padding:30px 48px 0;box-sizing:border-box}.form-main,.form-main form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}.form-header-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;width:100%}.form-title{margin:0;color:var(--ink);font-family:Fraunces,Georgia,serif;font-weight:700;font-size:24px;line-height:1.2;letter-spacing:-.5px}.role-fade-target{transition:opacity .2s cubic-bezier(.4,0,.2,1),transform .2s cubic-bezier(.4,0,.2,1);will-change:opacity,transform}.role-fade-target.is-switching{opacity:0;transform:translateY(3px)}.role-toggle{position:relative;display:inline-flex;align-items:center;background:#efebe7;border:1px solid var(--field-line);border-radius:11px;padding:3px;gap:2px}.role-toggle .tab-indicator{position:absolute;top:3px;left:0;height:calc(100% - 6px);border-radius:8px;background:var(--brown);z-index:0;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1);box-shadow:0 1px 4px rgba(122,75,58,.22);pointer-events:none}.role-pill{position:relative;z-index:1;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border:0;border-radius:8px;background:transparent!important;color:var(--brown);font-size:12.5px;font-weight:600;cursor:pointer;transition:color .25s ease}.role-pill svg{width:14px;height:14px;stroke-width:2}.role-pill.active,.role-pill.is-active{color:#fff!important;box-shadow:none}.role-pill:not(.is-active):hover{color:var(--ink)}.alert{margin:0 0 10px;padding:8px 12px;border-radius:8px;background:#fdf2f0;border:1px solid #f6cfca;color:#b03525;font-size:12.5px;line-height:1.35}.fields-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:0}@media (max-width:580px){.fields-row-2{grid-template-columns:1fr;gap:0}}.field{margin-bottom:12px}.field label{display:block;margin-bottom:5px;color:var(--ink);font-size:13px;font-weight:500}.control{position:relative}.control input{width:100%;height:44px;padding:0 42px 0 40px;border:1px solid var(--field-line);border-radius:9px;background:var(--field-bg);color:var(--ink);font-size:14px;transition:border-color .15s ease,box-shadow .15s ease,background-color .15s ease}.control input::-moz-placeholder{color:var(--muted);font-size:13.5px}.control input::placeholder{color:var(--muted);font-size:13.5px}.control input:focus{outline:0;background:#fff;border-color:var(--brown);box-shadow:0 0 0 3px rgba(122,75,58,.15)}.control input.is-invalid{border-color:#c93b2b;background:snow}.control .lead-icon{left:12px;width:18px;height:18px;color:#5b4a42;transform:translateY(-50%);pointer-events:none}.control .lead-icon,.eye{position:absolute;top:50%}.eye{right:6px;display:grid;place-items:center;width:30px;height:30px;padding:0;border:0;border-radius:7px;background:transparent!important;color:#5b4a42;transform:translateY(-50%);cursor:pointer;transition:color .15s ease}.eye:active,.eye:focus,.eye:focus-visible,.eye:hover{background:transparent!important;cursor:pointer;outline:none}.eye:active,.eye:hover{color:#20130d}.eye:active svg,.eye:hover svg{stroke-width:2.6}.eye svg{width:18px;height:18px}.eye .slash{display:none}.eye[aria-pressed=true] .slash{display:block}.google-line{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:14px;margin-bottom:8px}.or-separator{color:var(--muted);font-size:13px;font-weight:600;text-transform:lowercase;-webkit-user-select:none;-moz-user-select:none;user-select:none;flex-shrink:0}.btn-google-line{display:inline-flex;align-items:center;justify-content:center;gap:10px;flex:0 0 auto;width:-moz-max-content;width:max-content;padding:0 16px;height:40px;border:1px solid var(--field-line);border-radius:9px;background:#fff;color:var(--brown);font-size:13.5px;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap;transition:all .2s ease}.btn-google-line:hover{background:var(--cream);border-color:var(--blush);box-shadow:0 2px 6px rgba(122,75,58,.1);transform:translateY(-1px)}.btn-google-line:active{transform:none}.btn-google-line svg{width:17px;height:17px;stroke:none}.terms-group{margin-bottom:14px}.terms-label{display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:12.5px;line-height:1.4;color:var(--text)}.terms-label input[type=checkbox]{width:16px;height:16px;margin-top:2px;accent-color:var(--brown);cursor:pointer;flex-shrink:0}.policy-link{display:inline;padding:0;margin:0;border:0;background:transparent;color:var(--brown,#7a4b3a);font-weight:600;font-size:inherit;cursor:pointer;text-decoration:none!important;transition:color .15s ease}.policy-link:hover{color:var(--brown-dark,#5f3425)!important;text-decoration:none!important}.policy-link:active{color:var(--brown-darker,#452618)!important;text-decoration:none!important}.actions{display:flex;align-items:center;justify-content:flex-end;gap:16px;margin-top:4px;padding-top:0;border-top:none}.actions--full{display:flex;flex-direction:column;gap:12px;width:100%;margin-top:6px}.actions--full .btn-register{width:100%;margin-left:0;border-radius:9px;height:44px;display:flex;align-items:center;justify-content:center}.or{display:flex;align-items:center;text-align:center;color:var(--muted);font-size:13px;font-weight:500;letter-spacing:.5px}.or:after,.or:before{content:"";flex:1;border-bottom:1px solid var(--border-line,#dfcdbf)}.or:not(:empty):before{margin-right:12px}.or:not(:empty):after{margin-left:12px}.btn-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:44px;border:1px solid var(--field-line);border-radius:9px;background:#fff;color:var(--brown);font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;transition:all .2s ease}.btn-google:hover{background:var(--cream);border-color:var(--blush);box-shadow:0 2px 8px rgba(122,75,58,.12);transform:translateY(-1px)}.btn-google:active{transform:none}.btn-google svg{width:18px;height:18px;stroke:none}.paths{display:block;margin:auto 48px 0;padding:0;border-top:none!important}.paths-row{display:flex;align-items:center;justify-content:flex-start;padding:16px 0 24px;font-size:13.5px;color:var(--muted);white-space:nowrap}.paths-left{text-align:left}.paths-right{display:none}.btn-login-link{color:var(--brown,#7a4b3a);font-weight:700;text-decoration:none!important;transition:color .15s ease}.btn-login-link:hover{color:var(--brown-dark,#5f3425)!important;text-decoration:none!important}.btn-login-link:active{color:var(--brown-darker,#452618)!important;text-decoration:none!important}@media (max-width:580px){.paths{margin:16px 20px 0}.paths-row{flex-direction:column;gap:8px;align-items:center;text-align:center;white-space:normal}}.btn-register{margin-left:auto;display:inline-flex;align-items:center;justify-content:center;width:auto;min-width:-moz-max-content;min-width:max-content;padding:0 26px;height:44px;border:1px solid var(--brown);border-radius:9px;background:var(--brown);color:#fff;font-size:14.5px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .2s ease}.btn-register:hover{background:var(--brown-dark);border-color:var(--brown-dark);transform:translateY(-1px);box-shadow:0 4px 10px rgba(122,75,58,.2)}.btn-register:active{background:var(--brown-darker);transform:none}.google-badge-notice{display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--field-bg);border:1px solid var(--field-line);border-radius:10px;margin-bottom:16px}.google-badge-avatar{width:36px;height:36px;border-radius:50%;overflow:hidden;background:var(--cream);display:grid;place-items:center;flex-shrink:0}.google-badge-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.google-badge-info{flex:1;min-width:0}.badge-title{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}.badge-email,.badge-title{display:block;font-weight:600}.badge-email{font-size:13.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.google-badge-switch{font-size:12px;color:var(--brown);font-weight:600;text-decoration:none;transition:color .18s ease,transform .18s ease}.google-badge-switch:hover{color:var(--brown-dark);text-decoration:none}.google-badge-switch:active{color:var(--brown-darker);transform:scale(.96);text-decoration:none}.google-finish-form .control input{color:var(--text)}.policy-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:20px}.policy-modal[hidden]{display:none!important}.policy-modal-backdrop{position:fixed;inset:0;background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);transition:opacity .22s ease}.policy-modal-panel{position:relative;width:min(100%,640px);max-height:84vh;display:flex;flex-direction:column;background:#fffdf9;border:1px solid #ebd9cb;border-radius:24px;box-shadow:0 28px 70px rgba(55,31,22,.28);color:#3b2820;overflow:hidden;animation:policy-pop .28s cubic-bezier(.16,1,.3,1) both}@keyframes policy-pop{0%{opacity:0;transform:scale(.95) translateY(14px)}to{opacity:1;transform:scale(1) translateY(0)}}.policy-modal.is-closing{pointer-events:none}.policy-modal.is-closing .policy-modal-backdrop{opacity:0;transition:opacity .22s ease}.policy-modal.is-closing .policy-modal-panel{opacity:0;transform:scale(.96) translateY(10px);transition:opacity .22s ease,transform .22s ease}.policy-modal-header{display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1px solid #ebd9cb;background:#fffdf9}.policy-modal-title-group{display:flex;flex-direction:column;gap:2px}.policy-modal-crumb{font-size:11.5px;font-weight:600;color:#8b7467;letter-spacing:.5px;text-transform:uppercase;margin:0}.policy-modal-header h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:24px;line-height:1.2;color:#3b2820}.policy-modal-close{display:grid;place-items:center;width:36px;height:36px;border:0;background:transparent;color:#7a4b3a;font-size:22px;line-height:1;cursor:pointer;border-radius:0;box-shadow:none;transition:color .18s ease,transform .18s ease}.policy-modal-close:hover{background:transparent;color:#3b2820;border-color:transparent;transform:scale(1.08)}.policy-modal-close:active{background:transparent;color:#1a0f0a;transform:scale(.92)}.policy-modal-body{padding:24px 26px;overflow-y:auto;font-family:DM Sans,sans-serif;color:#8b5a4a;scrollbar-color:#7b4a3a transparent;scrollbar-width:thin}.policy-modal-body::-webkit-scrollbar{width:10px}.policy-modal-body::-webkit-scrollbar-track{background:transparent!important}.policy-modal-body::-webkit-scrollbar-thumb{background:#7b4a3a;border:2px solid #f3e9df;border-radius:999px}.policy-modal-body::-webkit-scrollbar-thumb:hover{background:#5c3527}.policy-modal-body .policy-card,.policy-modal-body section{background:#f7f1e7;border:1px solid #e8ddd3;border-radius:18px;padding:20px 24px;margin-bottom:16px}.policy-modal-body .policy-card:last-child,.policy-modal-body section:last-child{margin-bottom:0}.policy-modal-body .policy-card h2,.policy-modal-body h3,.policy-modal-body section h2{font-family:Fraunces,Georgia,serif;font-weight:700;font-size:19px;color:#6b3624;margin:0 0 8px}.policy-modal-body .policy-card p,.policy-modal-body p,.policy-modal-body section p{font-size:14.5px;line-height:24px;color:#8b5a4a;margin:0}.policy-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:18px 28px;border-top:1px solid #ebd9cb;background:#fffdf9}.policy-modal-footer-note{font-size:12.5px;color:#8b7467;font-weight:500}.btn-policy-confirm{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 24px;border:none;border-radius:12px;background:linear-gradient(135deg,#7a4b3a,#633b2c);color:#fff;font-family:DM Sans,sans-serif;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px rgba(122,75,58,.24);transition:all .18s ease}.btn-policy-confirm:hover{background:#613829;transform:translateY(-1px)}.policy-modal-page-link{color:#7b4a3a;font-size:13px;font-weight:600;text-decoration:underline;transition:color .18s ease}.policy-modal-page-link:hover{color:#5c3527}@media (max-width:760px){.stage,body{height:auto;overflow-y:auto}.stage{padding:16px 12px 32px}.card{grid-template-columns:1fr;height:auto;min-height:0;border-radius:18px}.panel{padding:32px 24px}.panel h1{font-size:32px}.panel-copy{font-size:15px;line-height:23px}.form-main,.form-side{height:auto}.form-main{padding:28px 20px}.actions{flex-wrap:wrap;gap:12px}}@media (max-width:480px){.actions{flex-direction:column-reverse;align-items:stretch}.actions .btn-register{width:100%;margin-left:0}.actions .signin-switch{text-align:center}}html[data-theme=dark] .stage,html[data-theme=dark] body{background:var(--ds-page,#1e1612);color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .topbar{background:var(--topbar-bg,rgba(30,22,18,.96));border-bottom:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .topbar .logo,html[data-theme=dark] .topbar .logo span{color:var(--ds-title,#fff7f2)!important}html[data-theme=dark] .card{background:var(--ds-surface,#2b1f19);border:1px solid var(--ds-line,#3d2d24);box-shadow:0 16px 48px rgba(0,0,0,.5)}html[data-theme=dark] .panel{background:linear-gradient(135deg,#32221b,#241813);border-right:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .panel h1,html[data-theme=dark] .panel h1 span{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .tagline{color:var(--ds-muted,#a8948a)}html[data-theme=dark] .form-side{background:#382a22!important}html[data-theme=dark] .form-side h2,html[data-theme=dark] .form-title{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .btn-landlord-link,html[data-theme=dark] .btn-login-link,html[data-theme=dark] .eye,html[data-theme=dark] .eye svg,html[data-theme=dark] .field label,html[data-theme=dark] .form-main .hint,html[data-theme=dark] .hint,html[data-theme=dark] .panel-copy,html[data-theme=dark] .paths-left,html[data-theme=dark] .paths-right,html[data-theme=dark] .paths-row,html[data-theme=dark] .policy-link,html[data-theme=dark] .terms-label,html[data-theme=dark] .terms-text{color:var(--ds-body,#d9c8bc)!important}html[data-theme=dark] .control input::-moz-placeholder{color:var(--ds-body,#d9c8bc)!important;opacity:.9}html[data-theme=dark] .control input::placeholder{color:var(--ds-body,#d9c8bc)!important;opacity:.9}html[data-theme=dark] .control input{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639);color:var(--ds-body,#d9c8bc)!important}html[data-theme=dark] .control input:focus{border-color:var(--ds-accent,#e2ab94);background:#281d17;color:var(--ds-body,#d9c8bc)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)}html[data-theme=dark] .control .lead-icon{color:var(--ds-body,#d9c8bc);stroke:var(--ds-body,#d9c8bc)}html[data-theme=dark] .eye{background:transparent!important;cursor:pointer!important}html[data-theme=dark] .eye:active,html[data-theme=dark] .eye:focus,html[data-theme=dark] .eye:focus-visible,html[data-theme=dark] .eye:hover{background:transparent!important;cursor:pointer!important;color:#8c6e5e!important;outline:none}html[data-theme=dark] .eye:active svg,html[data-theme=dark] .eye:hover svg{stroke:#8c6e5e!important;stroke-width:2.6!important}html[data-theme=dark] .policy-link{color:var(--ds-body,#d9c8bc)!important;text-decoration:none!important}html[data-theme=dark] .policy-link:hover{color:#b59d8f!important;text-decoration:none!important}html[data-theme=dark] .policy-link:active{color:#94796b!important;text-decoration:none!important}html[data-theme=dark] .btn-login-link{color:var(--ds-body,#d9c8bc)!important;text-decoration:none!important}html[data-theme=dark] .btn-login-link:hover{color:#b59d8f!important;text-decoration:none!important}html[data-theme=dark] .btn-login-link:active{color:#94796b!important;text-decoration:none!important}html[data-theme=dark] .terms-label input[type=checkbox]{accent-color:var(--ds-accent,#e2ab94)}html[data-theme=dark] .btn-register{background:var(--ds-accent,#e2ab94);border-color:var(--ds-accent,#e2ab94);color:var(--ds-on-accent,#1e1612);font-weight:700}html[data-theme=dark] .btn-register:hover{background:var(--ds-accent-hover,#efc2ae);border-color:var(--ds-accent-hover,#efc2ae);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-register:active{background:var(--ds-accent-press,#cf967f);border-color:var(--ds-accent-press,#cf967f);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .btn-google{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639);color:var(--ds-title,#fff7f2)}html[data-theme=dark] .btn-google:hover{background:#2c2019;border-color:var(--ds-accent,#e2ab94);color:#fff}html[data-theme=dark] .actions--full .or{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .actions--full .or:after,html[data-theme=dark] .actions--full .or:before{background:var(--ds-accent,#e2ab94)!important;opacity:.6}html[data-theme=dark] .paths{border-top:none!important}html[data-theme=dark] .google-badge-notice{background:var(--ds-field,#231a14);border:1px solid var(--ds-field-line,#5c4639)}html[data-theme=dark] .badge-title{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .badge-email{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .google-badge-switch{color:var(--ds-accent,#e2ab94)}html[data-theme=dark] .google-badge-switch:hover{color:#c48974}html[data-theme=dark] .google-badge-switch:active{color:#aa6f5b}html[data-theme=dark] .google-finish-form .control input,html[data-theme=dark] .google-finish-form .control input:focus{color:var(--ds-body,#d9c8bc)!important}html[data-theme=dark] .policy-modal-backdrop{background:rgba(15,10,8,.72)}html[data-theme=dark] .policy-modal-panel{background:var(--ds-surface,#2b1f19);border:1px solid var(--ds-line,#3d2d24);box-shadow:0 28px 70px rgba(0,0,0,.65);color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .policy-modal-header{background:#241813;border-bottom:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .policy-modal-header h2{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .policy-modal-crumb{color:var(--ds-muted,#a8948a)}html[data-theme=dark] .policy-modal-close{background:transparent;border:0;color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .policy-modal-close:hover{background:transparent;color:var(--ds-accent,#e2ab94);border-color:transparent}html[data-theme=dark] .policy-modal-close:active{background:transparent;color:var(--ds-accent-press,#cf967f)}html[data-theme=dark] .policy-modal-body{scrollbar-color:var(--ds-accent,#e2ab94) transparent;color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .policy-modal-body .policy-card,html[data-theme=dark] .policy-modal-body section{background:#231a14;border:1px solid var(--ds-field-line,#5c4639)}html[data-theme=dark] .policy-modal-body .policy-card h2,html[data-theme=dark] .policy-modal-body h3,html[data-theme=dark] .policy-modal-body section h2{color:var(--ds-title,#fff7f2)}html[data-theme=dark] .policy-modal-body .policy-card p,html[data-theme=dark] .policy-modal-body p,html[data-theme=dark] .policy-modal-body section p{color:var(--ds-body,#d9c8bc)}html[data-theme=dark] .policy-modal-footer{background:#241813;border-top:1px solid var(--ds-line,#3d2d24)}html[data-theme=dark] .policy-modal-footer-note{color:var(--ds-muted,#a8948a)}html[data-theme=dark] .btn-policy-confirm{background:var(--ds-accent,#e2ab94);border-color:var(--ds-accent,#e2ab94);color:var(--ds-on-accent,#1e1612);font-weight:700}html[data-theme=dark] .btn-policy-confirm:hover{background:var(--ds-accent-hover,#efc2ae);border-color:var(--ds-accent-hover,#efc2ae);color:var(--ds-on-accent,#1e1612)}html[data-theme=dark] .policy-modal-page-link{color:var(--ds-accent,#e2ab94)}html[data-theme=dark] .policy-modal-page-link:hover{color:var(--ds-accent-hover,#efc2ae)}@view-transition{navigation:auto}@keyframes page-fade-in{0%{opacity:0}to{opacity:1}}@keyframes page-fade-out{0%{opacity:1}to{opacity:0}}::view-transition-old(root){animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}::view-transition-new(root){animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-entering body{animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-leaving body{animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}@media (prefers-reduced-motion:reduce){::view-transition-new(root),::view-transition-old(root),html.page-entering body,html.page-leaving body{animation:none}}.visible{visibility:visible}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.flex-shrink{flex-shrink:1}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.flex-wrap{flex-wrap:wrap}.border{border-width:1px}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.underline{text-decoration-line:underline}.outline{outline-style:solid}.blur{--tw-blur:blur(8px)}.blur,.filter{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}
/* auth-tailwind:landlord-register:end */
    </style>
    <script src="../assets/js/theme.js"></script>
    <style>
        .readonly-email-box {
            background-color: #f7ede5 !important;
            color: #5c4335 !important;
            cursor: not-allowed !important;
            user-select: none;
            font-weight: 600;
        }
        .control input {
            padding-left: 14px !important;
            padding-right: 14px !important;
        }
        .control input[type="password"] {
            padding-right: 44px !important;
            padding-left: 14px !important;
        }
        .control .lead-icon {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            z-index: 2 !important;
            pointer-events: none !important;
        }
        #landlordRegisterForm .control input,
        .control:has(.lead-icon) input,
        .control .lead-icon ~ input,
        .control input#reg-email,
        .control input#reg-password,
        .control input#reg-confirm-password {
            padding-left: 46px !important;
        }
        #landlordRegisterForm .control input[type="password"],
        .control:has(.lead-icon) input[type="password"],
        .control .lead-icon ~ input[type="password"] {
            padding-left: 46px !important;
            padding-right: 44px !important;
        }
        .input-prefix-wrapper {
            display: flex;
            align-items: stretch;
            width: 100%;
            border: 1px solid var(--field-line);
            border-radius: 9px;
            background: #ffffff;
            overflow: hidden;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .input-prefix-wrapper:focus-within {
            border-color: var(--brown);
            box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.15);
        }
        .input-prefix-wrapper .input-prefix-addon {
            display: flex;
            align-items: center;
            padding: 0 14px;
            background: #fbf6f2;
            color: #78665c;
            font-size: 0.92rem;
            font-weight: 600;
            border-right: 1px solid #ebd9cb;
            user-select: none;
            white-space: nowrap;
        }
        .input-prefix-wrapper input {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            flex: 1;
            min-width: 0;
            height: 44px;
            padding-left: 12px !important;
            background: #ffffff !important;
        }
        .btn-landing-back {
            font: 500 13px 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 0 20px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid #e5dcd3;
            background: #fbf5ec;
            color: #7a4b3a;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            transition: 0.2s;
            text-decoration: none;
            white-space: nowrap;
            box-sizing: border-box;
        }
        .btn-landing-back:hover {
            filter: brightness(0.95);
            background: #f0e6dc;
            border-color: #d1bfae;
            color: #5c3527;
        }
        .step2-actions-bottom-right {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 12px !important;
            margin-top: 24px !important;
            width: 100% !important;
            flex-wrap: nowrap !important;
        }
        .btn-register.btn-short {
            display: inline-flex !important;
            flex: 0 0 auto !important;
            width: auto !important;
            max-width: max-content !important;
            min-width: 0 !important;
            padding: 0 24px !important;
            height: 40px !important;
            border-radius: 8px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            align-items: center !important;
            justify-content: center !important;
            white-space: nowrap !important;
            box-sizing: border-box !important;
            margin-left: 0 !important;
        }
        .form-section-label {
            font-size: 0.92rem;
            font-weight: 700;
            color: #4a342b;
            margin: 4px 0 16px;
        }
        .password-validation-hint {
            font-size: 0.70rem;
            color: #b83227;
            margin-top: 3px;
            display: none;
            line-height: 1.25;
            font-weight: 500;
        }
        .control input.is-invalid {
            border-color: #c93b2b !important;
            background: #fffafa !important;
        }
    </style>
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

            <!-- Brown left panel (identical styling to tenant register) -->
            <section class="panel">
                <?php if ($isAdminCreating): ?>
                    <h1 id="panelTitle">
                        <span>Create landlord</span><br>
                        <span>account.</span>
                    </h1>
                    <p class="panel-copy" id="panelCopy">
                        Admin direct registration. Since in-person verification has already occurred between administration and the landlord, this account will be approved and activated immediately.
                    </p>
                    <p class="tagline">ADMINISTRATOR ACCREDITATION</p>
                <?php else: ?>
                    <h1 id="panelTitle">
                        <span>Your property</span><br>
                        <span>starts here.</span>
                    </h1>
                    <p class="panel-copy" id="panelCopy">
                        List verified student dorms, connect with trusted tenants, and manage your bookings seamlessly with UniStay.
                    </p>
                    <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
                <?php endif; ?>
            </section>

            <!-- Form side -->
            <section class="form-side">
                <div class="form-main">
                    <?php if ($isAdminCreating): ?>
                        <?php if ($step === 1): ?>
                            <div class="form-header-row" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                                <h2 class="form-title" style="margin:0;">Register Landlord Account</h2>
                                <a href="../admin/users.php" style="color:#7a4b3a; font-size:0.85rem; font-weight:700; text-decoration:none;">&larr; Back to Users</a>
                            </div>

                            <?php if ($errorMessage !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <form method="post" action="landlord-register.php?admin_create=1&step=1" id="adminLandlordRegisterStep1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="admin_create" value="1">
                                <input type="hidden" name="step" value="1">

                                <div class="form-section-label">Personal Details</div>

                                <div class="field">
                                    <label for="admin-first-name">First Name *</label>
                                    <div class="control">
                                        <input type="text" id="admin-first-name" name="first_name" required value="<?= htmlspecialchars($_SESSION['admin_landlord_draft']['first_name'] ?? $_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Maria">
                                    </div>
                                </div>

                                <div class="field">
                                    <label for="admin-middle-name">Middle Name (Optional)</label>
                                    <div class="control">
                                        <input type="text" id="admin-middle-name" name="middle_name" value="<?= htmlspecialchars($_SESSION['admin_landlord_draft']['middle_name'] ?? $_POST['middle_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Dela Cruz">
                                    </div>
                                </div>

                                <div class="field">
                                    <label for="admin-last-name">Last Name *</label>
                                    <div class="control">
                                        <input type="text" id="admin-last-name" name="last_name" required value="<?= htmlspecialchars($_SESSION['admin_landlord_draft']['last_name'] ?? $_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Santos">
                                    </div>
                                </div>

                                <div class="field">
                                    <label for="admin-phone">Phone Number *</label>
                                    <div class="input-prefix-wrapper">
                                        <span class="input-prefix-addon">+63</span>
                                        <input type="tel" id="admin-phone" name="phone" required value="<?= htmlspecialchars(preg_replace('/^(?:\+?63|0)/', '', $_SESSION['admin_landlord_draft']['phone'] ?? $_POST['phone'] ?? '')) ?>" placeholder="9XXXXXXXXX" inputmode="numeric" minlength="10" maxlength="10" pattern="9[0-9]{9}">
                                    </div>
                                </div>

                                <div class="actions actions--full" style="margin-top:24px;">
                                    <button type="submit" class="btn-register" id="step1NextBtn">
                                        <span>Next</span>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Step 2: Account setup matching tenant sign up page -->
                            <div class="form-header-row" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                                <h2 class="form-title" style="margin:0;">Create your account</h2>
                                <a href="../admin/users.php" style="color:#7a4b3a; font-size:0.85rem; font-weight:700; text-decoration:none;">&larr; Back to Users</a>
                            </div>

                            <?php if ($errorMessage !== ''): ?>
                                <p class="alert" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <?php if ($isGoogle): ?>
                                <form method="post" action="landlord-register.php?admin_create=1&step=2&google=1" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="admin_create" value="1">
                                    <input type="hidden" name="step" value="2">

                                    <div class="google-badge-notice">
                                        <div class="google-badge-avatar">
                                            <?php if (!empty($pending['picture'])): ?>
                                                <img src="<?= htmlspecialchars($pending['picture'], ENT_QUOTES, 'UTF-8') ?>" alt="Google profile photo" referrerpolicy="no-referrer">
                                            <?php else: ?>
                                                <div class="google-badge-avatar-fallback">
                                                    <?= htmlspecialchars(strtoupper(substr($pending['name'] ?: $pending['email'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="google-badge-details">
                                            <span class="google-badge-name"><?= htmlspecialchars($pending['name'] ?: 'Google Account', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="google-badge-email"><?= htmlspecialchars($pending['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>

                                    <div class="agreement" style="margin-top: 14px;">
                                        <label class="terms-label" for="termsCheckboxGoogle">
                                            <input type="checkbox" id="termsCheckboxGoogle" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                                            <span class="terms-text">
                                                I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button> for UniStay Landlords.
                                            </span>
                                        </label>
                                    </div>

                                    <div class="step2-actions-bottom-right">
                                        <a href="landlord-register.php?admin_create=1&step=1" class="btn-landing-back">Back</a>
                                        <button type="submit" class="btn-register btn-short">
                                            <span>Create account</span>
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <form method="post" action="landlord-register.php?admin_create=1&step=2" id="adminLandlordRegisterStep2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="admin_create" value="1">
                                    <input type="hidden" name="step" value="2">

                                    <div class="form-section-label">Account Creation</div>

                                    <div class="field">
                                        <label for="reg-email">Email Address *</label>
                                        <div class="control">
                                            <input type="email" id="reg-email" name="email" required placeholder="landlord@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                    </div>

                                    <div class="field">
                                        <label for="reg-password">Password *</label>
                                        <div class="control">
                                            <input
                                                type="password"
                                                id="reg-password"
                                                name="password"
                                                autocomplete="new-password"
                                                placeholder="8–16 characters (upper, lower, number, symbol)"
                                                required
                                                minlength="8"
                                                maxlength="16"
                                            >
                                            <button class="eye" type="button" id="toggleRegPassword" aria-label="Show password" aria-pressed="false">
                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                    <path class="slash" d="m4 4 16 16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <div id="passwordRequirements" class="password-validation-hint">
                                            Password must be 8–16 characters with uppercase, lowercase, a number, and a symbol (no spaces).
                                        </div>
                                    </div>

                                    <div class="field">
                                        <label for="reg-confirm-password">Repeat Password *</label>
                                        <div class="control">
                                            <input
                                                type="password"
                                                id="reg-confirm-password"
                                                name="confirm_password"
                                                autocomplete="new-password"
                                                placeholder="Repeat your password"
                                                required
                                                minlength="8"
                                                maxlength="16"
                                            >
                                            <button class="eye" type="button" id="toggleRegConfirm" aria-label="Show confirm password" aria-pressed="false">
                                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                    <path class="slash" d="m4 4 16 16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <div id="passwordMismatchHint" class="password-validation-hint">
                                            Passwords do not match.
                                        </div>
                                    </div>

                                    <div class="agreement">
                                        <label class="terms-label" for="termsCheckboxManual">
                                            <input type="checkbox" id="termsCheckboxManual" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                                            <span class="terms-text">
                                                I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button> for UniStay Landlords.
                                            </span>
                                        </label>
                                    </div>

                                    <div class="google-line" style="margin-top: 14px; margin-bottom: 2px;">
                                        <span class="or-separator">or</span>
                                        <a href="google_oauth.php?role=landlord" class="btn-google-line" id="googleSignUpBtn">
                                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                                <path fill="#4285F4" d="M23 12.27c0-.8-.07-1.56-.2-2.3H12v4.36h6.17a5.28 5.28 0 0 1-2.29 3.46v2.87h3.7C21.74 18.7 23 15.7 23 12.27z"/>
                                                <path fill="#34A853" d="M12 23.5c3.1 0 5.7-1.03 7.58-2.79l-3.7-2.87c-1.03.69-2.35 1.1-3.88 1.1-2.98 0-5.5-2.01-6.4-4.72H1.78v2.96A11.5 11.5 0 0 0 12 23.5z"/>
                                                <path fill="#FBBC05" d="M5.6 14.22a6.9 6.9 0 0 1 0-4.44V6.82H1.78a11.5 11.5 0 0 0 0 10.36l3.82-2.96z"/>
                                                <path fill="#EA4335" d="M12 5.06c1.69 0 3.2.58 4.39 1.72l3.29-3.29C17.7 1.64 15.1.5 12 .5 7.52.5 3.65 3.07 1.78 6.82l3.82 2.96C6.5 7.07 9.02 5.06 12 5.06z"/>
                                            </svg>
                                            <span>Sign up with Google</span>
                                        </a>
                                    </div>

                                    <div class="step2-actions-bottom-right">
                                        <a href="landlord-register.php?admin_create=1&step=1" class="btn-landing-back">Back</a>
                                        <button type="submit" class="btn-register btn-short" id="submitBtn">
                                            <span>Create account</span>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($isApproved && $landlord): ?>
                        <div class="form-header-row">
                            <h2 class="form-title">Complete your landlord account</h2>
                        </div>
                        <p style="font-size: 0.88rem; color: #78665c; margin: -6px 0 16px;">
                            Your application has been approved! Create your password to finalize your account and access the landlord portal.
                        </p>

                        <?php if ($errorMessage !== ''): ?>
                            <p class="alert" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>

                        <form method="post" action="landlord-register.php" id="landlordRegisterForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($tokenParam, ENT_QUOTES, 'UTF-8') ?>">

                            <!-- Email Field (Pre-filled and Strictly Unchangeable) -->
                            <div class="field">
                                <label for="reg-email">Approved Email Address</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input
                                        type="email"
                                        id="reg-email"
                                        name="email"
                                        value="<?= htmlspecialchars($landlord['email'], ENT_QUOTES, 'UTF-8') ?>"
                                        readonly
                                        tabindex="-1"
                                        class="readonly-email-box"
                                        required
                                    >
                                </div>
                            </div>

                            <!-- Password Field -->
                            <div class="field">
                                <label for="reg-password">Create Password</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                    <input
                                        type="password"
                                        id="reg-password"
                                        name="password"
                                        autocomplete="new-password"
                                        placeholder="8–16 characters (upper, lower, number, symbol)"
                                        required
                                        minlength="8"
                                        maxlength="16"
                                    >
                                    <button class="eye" type="button" id="toggleRegPassword" aria-label="Show password" aria-pressed="false">
                                        <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Confirm Password Field -->
                            <div class="field">
                                <label for="reg-confirm-password">Confirm Password</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                                    </svg>
                                    <input
                                        type="password"
                                        id="reg-confirm-password"
                                        name="confirm_password"
                                        autocomplete="new-password"
                                        placeholder="Repeat your password"
                                        required
                                        minlength="8"
                                        maxlength="16"
                                    >
                                    <button class="eye" type="button" id="toggleRegConfirm" aria-label="Show confirm password" aria-pressed="false">
                                        <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Terms -->
                            <div class="terms-group">
                                <label class="terms-label" for="termsCheckbox">
                                    <input type="checkbox" id="termsCheckbox" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                                    <span class="terms-text">
                                        I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button> for UniStay Landlords.
                                    </span>
                                </label>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn-register" id="submitBtn">
                                    <span>Activate Landlord Account</span>
                                </button>
                            </div>
                        </form>

                    <?php else: ?>
                        <!-- Exclusive Notice for unapproved or direct visitors -->
                        <div class="form-header-row">
                            <h2 class="form-title">Landlord Registration</h2>
                        </div>
                        <div style="background:#fdf9f6; border:1px solid #ebd9cb; border-radius:14px; padding:20px; margin-bottom:20px; color:#4a342b; line-height:1.5;">
                            <?php if ($errorMessage !== ''): ?>
                                <p style="color:#a33d2d; font-weight:700; margin:0 0 10px;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <p style="margin:0 0 12px; font-size:0.92rem;">
                                Landlord accounts on UniStay are exclusively available to approved applicants. Once your application is approved by the university administration, an activation link will be sent to your email to create your password.
                            </p>
                            <p style="margin:0; font-size:0.88rem; color:#78665c;">
                                Have an approved account already? <a href="login.php" style="color:#7a4b3a; font-weight:700;">Sign in here</a>.
                            </p>
                        </div>

                        <div class="actions">
                            <a href="../index.php#landlord-application" class="btn-register" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">
                                <span>Apply as Landlord</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <!-- Policy modal data & overlay -->
    <script id="policyData" type="application/json">
    {
      "terms": {
        "title": "Terms of Service",
        "slug": "terms-of-service",
        "sections": [
          ["Using UniStay", "By using UniStay you agree to provide accurate information, respect other members of the community, and use the platform only for lawful purposes."],
          ["Bookings and Listings", "Landlords are responsible for keeping listings truthful and up to date. Students are responsible for reviewing a room before sending a booking request."],
          ["Reservations and Safety", "All listings and bookings made via UniStay are subject to approval by property owners and compliance with community safety standards."],
          ["Changes to the Service", "We may add, change, or remove features as UniStay evolves, and we will keep our community updated when platform terms change."]
        ]
      },
      "conduct": {
        "title": "Code of Conduct",
        "slug": "code-of-conduct",
        "sections": [
          ["Treat Everyone with Respect", "Mutual dignity, courteous communication, and professional honesty are mandatory for all tenants and landlords. Harassment, discrimination, hate speech, and abusive conduct are strictly prohibited across the platform, messaging, and accommodations."],
          ["Student Living Standards & Campus Rules", "Tenants agree to observe curfew policies, quiet study hours, cleanliness standards, and guest regulations established by the accommodation, respecting the property and fellow residents."],
          ["Landlord Standards & Fair Housing", "Landlords agree to provide safe, sanitary, and habitable accommodations, transparent fee structures without hidden costs, and equal, non-discriminatory treatment for all student applicants."],
          ["Truthful Listings & Verified Information", "All photos, amenities, bed capacities, and rates must accurately depict the accommodation. Misleading advertisements or falsified credentials are removed immediately and may result in account termination."],
          ["Platform Communication & Safety", "Keep inquiries, reservation requests, tenancy agreements, and key notifications within UniStay to ensure documented transparency and verified protection for both students and landlords."]
        ]
      },
      "community": {
        "title": "Community Guidelines for Landlords",
        "slug": "community-guidelines",
        "sections": [
          ["Honest & Accurate Listings", "Photos, descriptions, amenities, and rent prices must accurately reflect the real condition of the property. Misleading listings are removed immediately."],
          ["Fair and Respectful Treatment", "Landlords agree to provide safe, habitable living spaces and treat all student tenants fairly without discrimination or harassment."],
          ["Clear House Rules and Agreements", "Landlords must clearly state house rules, deposit terms, and payment schedules before accepting bookings on UniStay."],
          ["Platform Communication", "All inquiries, bookings, and tenancy documentation should remain accessible on UniStay for mutual protection and record keeping."]
        ]
      },
      "privacy": {
        "title": "Privacy Policy",
        "slug": "privacy-policy",
        "sections": [
          ["What We Collect", "We collect only what UniStay needs to run: your name, contact details, verification documents, booking activity, and the listings a landlord publishes."],
          ["How We Use It", "Your data powers search, bookings, payments, and notifications. We never sell your personal information to third parties."],
          ["How We Protect It", "Passwords are hashed, verification files are stored outside the public web root, and database access is restricted to the application."],
          ["Your Choices", "You can request a copy of your data or ask us to delete your account at any time from your settings page."]
        ]
      }
    }
    </script>

    <div class="policy-modal" id="policyModal" hidden>
        <div class="policy-modal-backdrop" data-policy-close></div>
        <div class="policy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="policyModalTitle">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Policy Details</p>
                    <h2 id="policyModalTitle">Policy</h2>
                </div>
                <button type="button" class="policy-modal-close" data-policy-close aria-label="Close modal">&times;</button>
            </header>
            <div class="policy-modal-body" id="policyModalBody"></div>
            <footer class="policy-modal-footer">
                <span class="policy-modal-footer-note" id="policyModalFooterNote">&copy; 2026 UniStay Legal Standards</span>
                <button type="button" class="btn-policy-confirm" data-policy-close>Understood</button>
            </footer>
        </div>
    </div>

    <script>
        // Form validation for Step 1: require First Name, Last Name, and Phone (Middle Name is optional)
        const s1Form = document.getElementById('adminLandlordRegisterStep1');
        if (s1Form) {
            s1Form.addEventListener('submit', (e) => {
                const fn = document.getElementById('admin-first-name');
                const ln = document.getElementById('admin-last-name');
                const ph = document.getElementById('admin-phone');

                const fnVal = fn ? fn.value.trim() : '';
                const lnVal = ln ? ln.value.trim() : '';
                const phVal = ph ? ph.value.trim() : '';

                if (!fnVal || !lnVal || !phVal) {
                    e.preventDefault();
                    if (!fnVal && fn) { fn.focus(); fn.reportValidity?.(); return; }
                    if (!lnVal && ln) { ln.focus(); ln.reportValidity?.(); return; }
                    if (!phVal && ph) { ph.focus(); ph.reportValidity?.(); return; }
                }
            });
        }

        // Real-time and submit password validation for Step 2
        const s2Form = document.getElementById('adminLandlordRegisterStep2');
        const pwdInput = document.getElementById('reg-password');
        const confirmInput = document.getElementById('reg-confirm-password');
        const pwdReqHint = document.getElementById('passwordRequirements');
        const pwdMismatchHint = document.getElementById('passwordMismatchHint');

        const pwdRegex = /^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/;

        function validatePassword() {
            if (!pwdInput) return true;
            const val = pwdInput.value;
            if (!val) {
                pwdInput.classList.remove('is-invalid');
                if (pwdReqHint) pwdReqHint.style.display = 'none';
                return true;
            }
            const isValid = pwdRegex.test(val);
            if (!isValid) {
                pwdInput.classList.add('is-invalid');
                if (pwdReqHint) pwdReqHint.style.display = 'block';
            } else {
                pwdInput.classList.remove('is-invalid');
                if (pwdReqHint) pwdReqHint.style.display = 'none';
            }
            return isValid;
        }

        function validateConfirm() {
            if (!confirmInput || !pwdInput) return true;
            const pVal = pwdInput.value;
            const cVal = confirmInput.value;
            if (!cVal) {
                confirmInput.classList.remove('is-invalid');
                if (pwdMismatchHint) pwdMismatchHint.style.display = 'none';
                return true;
            }
            const isMatch = (pVal === cVal);
            if (!isMatch) {
                confirmInput.classList.add('is-invalid');
                if (pwdMismatchHint) pwdMismatchHint.style.display = 'block';
            } else {
                confirmInput.classList.remove('is-invalid');
                if (pwdMismatchHint) pwdMismatchHint.style.display = 'none';
            }
            return isMatch;
        }

        if (pwdInput) {
            pwdInput.addEventListener('input', () => {
                validatePassword();
                if (confirmInput && confirmInput.value) validateConfirm();
            });
            pwdInput.addEventListener('blur', validatePassword);
        }

        if (confirmInput) {
            confirmInput.addEventListener('input', validateConfirm);
            confirmInput.addEventListener('blur', validateConfirm);
        }

        if (s2Form) {
            s2Form.addEventListener('submit', (e) => {
                const pVal = pwdInput ? pwdInput.value : '';
                const cVal = confirmInput ? confirmInput.value : '';

                if (!pVal) {
                    e.preventDefault();
                    pwdInput?.reportValidity?.() || pwdInput?.focus();
                    return;
                }

                if (!pwdRegex.test(pVal)) {
                    e.preventDefault();
                    pwdInput?.classList.add('is-invalid');
                    if (pwdReqHint) pwdReqHint.style.display = 'block';
                    pwdInput?.focus();
                    return;
                }

                if (!cVal) {
                    e.preventDefault();
                    confirmInput?.reportValidity?.() || confirmInput?.focus();
                    return;
                }

                if (pVal !== cVal) {
                    e.preventDefault();
                    confirmInput?.classList.add('is-invalid');
                    if (pwdMismatchHint) pwdMismatchHint.style.display = 'block';
                    confirmInput?.focus();
                    return;
                }
            });
        }

        // Eye toggles for passwords
        function setupEye(btnId, inputId) {
            const btn = document.getElementById(btnId);
            const input = document.getElementById(inputId);
            if (!btn || !input) return;
            btn.addEventListener('click', () => {
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                btn.setAttribute('aria-pressed', isPass ? 'true' : 'false');
                btn.setAttribute('aria-label', isPass ? 'Hide password' : 'Show password');
            });
        }
        setupEye('toggleRegPassword', 'reg-password');
        setupEye('toggleRegConfirm', 'reg-confirm-password');

        // --- Policy Dialog Modal Logic ---
        const policyModal = document.getElementById('policyModal');
        const policyTitle = document.getElementById('policyModalTitle');
        const policyBody = document.getElementById('policyModalBody');
        const policyFooterNote = document.getElementById('policyModalFooterNote');
        let policyData = {};

        try {
            const dataEl = document.getElementById('policyData');
            if (dataEl) policyData = JSON.parse(dataEl.textContent);
        } catch (err) {
            console.error('Failed to parse policy data', err);
        }

        function openPolicyModal(key) {
            if (!policyModal || !policyData[key]) return;
            const item = policyData[key];

            if (policyTitle) policyTitle.textContent = item.title || 'Policy';
            if (policyBody) {
                policyBody.innerHTML = (item.sections || []).map(sec => {
                    return `<section class="policy-card"><h2>${sec[0]}</h2><p>${sec[1]}</p></section>`;
                }).join('');
            }
            if (policyFooterNote) {
                policyFooterNote.textContent = '© 2026 UniStay Legal Standards';
            }

            policyModal.classList.remove('is-closing');
            policyModal.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closePolicyModal() {
            if (!policyModal || policyModal.hidden) return;
            policyModal.classList.add('is-closing');
            setTimeout(() => {
                policyModal.hidden = true;
                policyModal.classList.remove('is-closing');
                document.body.style.overflow = '';
            }, 220);
        }

        document.addEventListener('click', function (e) {
            const link = e.target.closest('.policy-link');
            if (link) {
                e.preventDefault();
                const key = link.dataset.policy;
                if (key) openPolicyModal(key);
                return;
            }

            if (e.target.closest('[data-policy-close]')) {
                e.preventDefault();
                closePolicyModal();
            }
        });

        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && policyModal && !policyModal.hidden) {
                closePolicyModal();
            }
        });
    </script>
</body>
</html>
