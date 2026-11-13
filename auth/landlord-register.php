<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

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
                    $ins = $pdo->prepare(
                        "INSERT INTO landlords (first_name, middle_name, last_name, email, phone_number, password_hash, profile_photo, status, approved_by, feedback, decided_at, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, 'Account directly created and verified in person by administrator.', NOW(), NOW())"
                    );
                    $ins->execute([$firstName, ($middleName !== '' ? $middleName : null), $lastName, $email, $phone, $hash, $photo, $adminName]);
                    $newLandlordId = (int)$pdo->lastInsertId();
                    $code = 'L' . $newLandlordId;
                    $pdo->prepare('UPDATE landlords SET code = ? WHERE landlord_id = ?')->execute([$code, $newLandlordId]);

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
            $upd = $pdo->prepare("UPDATE landlords SET password_hash = ?, activation_token = NULL WHERE id = ?");
            $upd->execute([$hash, (int)$landlord['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$landlord['id'];
            $_SESSION['role'] = 'landlord';
            $_SESSION['user'] = [
                'id' => (int)$landlord['id'],
                'name' => trim(($landlord['first_name'] ?? '') . ' ' . ($landlord['last_name'] ?? '')) ?: $landlord['email'],
                'email' => $landlord['email'],
                'photo' => $landlord['profile_photo'] ?? '',
            ];

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
    <link rel="stylesheet" href="../assets/css/topbar.css">
    <link rel="stylesheet" href="../assets/css/register.css?v=35">
    <link rel="stylesheet" href="../assets/css/page-transition.css">
    <style>
        .readonly-email-box {
            background-color: #f7ede5 !important;
            color: #5c4335 !important;
            cursor: not-allowed !important;
            user-select: none;
            font-weight: 600;
        }
        .readonly-email-badge {
            font-size: 0.76rem;
            color: #28733a;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
        }
        .control input {
            padding-left: 14px !important;
            padding-right: 14px !important;
        }
        .control input[type="password"] {
            padding-right: 42px !important;
            padding-left: 14px !important;
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
                                                I agree to the Terms of Service, Code of Conduct, and Privacy Policy for UniStay Landlords.
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
                                                I agree to the Terms of Service, Code of Conduct, and Privacy Policy for UniStay Landlords.
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
                                        aria-describedby="email-approved-badge"
                                        required
                                    >
                                </div>
                                <span class="readonly-email-badge" id="email-approved-badge">
                                    ✓ Verified application email &middot; unchangeable
                                </span>
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
                                        I agree to the Terms of Service, Code of Conduct, and Privacy Policy for UniStay Landlords.
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
    </script>
</body>
</html>
