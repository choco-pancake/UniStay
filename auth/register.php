<?php
$type = isset($_GET['type']) && $_GET['type'] === 'landlord'
    ? 'landlord'
    : 'tenant';

$isLandlord = $type === 'landlord';
$pageTitle = $isLandlord ? 'Landlord Sign Up' : 'Tenant Sign Up';

// Text for the brown panel on the left.
$panelTitleTop = $isLandlord ? 'Your space,' : 'Your new place';
$panelTitleBottom = $isLandlord ? 'their new home.' : 'starts here.';
$panelCopy = $isLandlord
    ? 'List your property, reach verified student tenants, and manage everything in one place.'
    : 'Find verified rooms near your university, book with confidence, and settle in without the guesswork.';

$switchText = $isLandlord
    ? 'Looking for a place to stay?'
    : 'Looking to list a property?';

$switchLinkText = $isLandlord
    ? 'Sign up as Tenant'
    : 'Apply as Landlord';

$switchType = $isLandlord ? 'tenant' : 'landlord';
$accountType = $type;

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../pages/policy-content.php';

start_app_session();

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$errorStep = 1; // Which step the error belongs to (1 = details, 2 = password).

$firstName = '';
$middleName = '';
$lastName = '';
$email = '';
$phone = '';
$facebook = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read and preserve submitted form values.
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    $phone = trim((string) ($_POST['phone'] ?? ''));
    $facebook = trim((string) ($_POST['facebook'] ?? ''));

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    $nameLength = preg_match_all('/./us', $firstName . $middleName . $lastName);

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
    ) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
        $errorStep = 2;
    } elseif (
        $firstName === ''
        || $lastName === ''
        || $nameLength === false
        || $nameLength > 120
    ) {
        $errorMessage = 'Enter a valid full name no longer than 120 characters.';
    } elseif (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 254
    ) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (!preg_match('/^9[0-9]{9}$/', $phone)) {
        $errorMessage = 'Enter a valid 10-digit Philippine mobile number starting with 9.';
    } elseif (!preg_match('/^[A-Za-z0-9.]{5,50}$/', $facebook)) {
        $errorMessage = 'Enter a valid Facebook username containing 5–50 letters, numbers, or periods.';
    } elseif (
        !is_string($password)
        || !preg_match(
            '/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/',
            $password
        )
    ) {
        $errorMessage = 'Your password must be 8–16 characters and include uppercase and lowercase letters, a number, and a symbol, with no spaces.';
        $errorStep = 2;
    } elseif (
        !is_string($confirmPassword)
        || !hash_equals($password, $confirmPassword)
    ) {
        $errorMessage = 'The passwords do not match.';
        $errorStep = 2;
    } elseif (!isset($_POST['terms'])) {
        $errorMessage = 'Please agree to the terms and policies before registering.';
        $errorStep = 2;
    } else {
        try {
            $statement = database()->prepare(
                'INSERT INTO users (first_name, middle_name, last_name, personal_email, phone_number, facebook_link, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName,
                $email,
                $phone,
                'https://facebook.com/' . $facebook,
                password_hash($password, PASSWORD_DEFAULT),
                $type
            ]);

            $userId = (int) database()->lastInsertId();

            session_regenerate_id(true);

            $_SESSION['user_id'] = $userId;
            $_SESSION['role'] = $type;
            $_SESSION['user'] = [
                'name' => trim($firstName . ' ' . $middleName . ' ' . $lastName),
                'email' => $email,
                'photo' => '',
            ];

            // New tenants finish their profile first; landlords go straight to their dashboard.
            header(
                $type === 'tenant'
                    ? 'Location: ../tenant/complete-profile.php'
                    : 'Location: ../landlord/dashboard.php',
                true,
                303
            );
            exit;
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                $errorMessage = 'An account with this email already exists. Try signing in instead.';
            } else {
                error_log(
                    'UniStay account registration failed: ' . $error->getMessage()
                );

                $errorMessage = 'Registration is temporarily unavailable. Check the database setup and try again.';
            }
        }
    }
}

$startStep = $errorMessage !== '' ? $errorStep : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>UniStay - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="../assets/css/topbar.css">
    <link rel="stylesheet" href="../assets/css/register.css">
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
                <h1>
                    <?= htmlspecialchars($panelTitleTop, ENT_QUOTES, 'UTF-8') ?><br>
                    <?= htmlspecialchars($panelTitleBottom, ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <p class="panel-copy"><?= htmlspecialchars($panelCopy, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <!-- Form side -->
            <section class="form-side">
                <div class="form-main">
                    <form
                        method="post"
                        action="register.php?type=<?= rawurlencode($type) ?>"
                        data-steps
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <!-- ============ STEP 1: Details ============ -->
                        <section class="step" data-step="1" <?= $startStep === 1 ? '' : 'hidden' ?>>
                            <div class="card-top">
                                <span class="card-switch">
                                    <?= htmlspecialchars($switchText, ENT_QUOTES, 'UTF-8') ?>
                                    <a href="register.php?type=<?= rawurlencode($switchType) ?>">
                                        <?= htmlspecialchars($switchLinkText, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </span>
                            </div>

                            <h2>Create your account</h2>
                            <p class="hint">Step 1 of 2 · Tell us who you are and how to reach you.</p>

                            <?php if ($errorMessage !== '' && $errorStep === 1): ?>
                                <p class="error" role="alert">
                                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>

                            <!-- Full Name -->
                            <div class="form-section">
                                <h3 class="section-title">Full Name</h3>

                                <div class="sub-labels">
                                    <div class="sub-label-item">
                                        <div class="input-wrapper">
                                            <input
                                                type="text"
                                                name="first_name"
                                                aria-label="First Name"
                                                autocomplete="given-name"
                                                placeholder="First Name"
                                                required
                                                minlength="1"
                                                maxlength="50"
                                                pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:[ '’-][A-Za-zÀ-ÖØ-öø-ÿ]+)*"
                                                value="<?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="name"
                                            >
                                        </div>
                                        <span class="sub-text">First Name</span>
                                    </div>

                                    <div class="sub-label-item">
                                        <div class="input-wrapper">
                                            <input
                                                type="text"
                                                name="middle_name"
                                                aria-label="Middle Name (optional)"
                                                autocomplete="additional-name"
                                                placeholder="Middle Name"
                                                maxlength="50"
                                                pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:[ '’-][A-Za-zÀ-ÖØ-öø-ÿ]+)*"
                                                value="<?= htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="name"
                                            >
                                            <span class="optional-badge">Optional</span>
                                        </div>
                                        <span class="sub-text">Middle Name</span>
                                    </div>

                                    <div class="sub-label-item">
                                        <div class="input-wrapper">
                                            <input
                                                type="text"
                                                name="last_name"
                                                aria-label="Last Name"
                                                autocomplete="family-name"
                                                placeholder="Last Name"
                                                required
                                                minlength="1"
                                                maxlength="50"
                                                pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:[ '’-][A-Za-zÀ-ÖØ-öø-ÿ]+)*"
                                                value="<?= htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="name"
                                            >
                                        </div>
                                        <span class="sub-text">Last Name</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact -->
                            <div class="form-section">
                                <h3 class="section-title">Contact</h3>

                                <div class="field-stack">
                                    <div class="form-group">
                                        <label>Email Address <span class="required">*</span></label>

                                        <div class="input-wrapper">
                                            <span class="input-prefix" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" focusable="false">
                                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                                    <path d="m3 7 9 6 9-6"></path>
                                                </svg>
                                            </span>

                                            <input
                                                type="email"
                                                name="email"
                                                autocomplete="email"
                                                placeholder="Email Address"
                                                required
                                                maxlength="254"
                                                pattern="[^@\s]+@[^@\s]+\.[^@\s]{2,}"
                                                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="email"
                                            >
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Phone Number <span class="required">*</span></label>

                                        <div class="input-wrapper">
                                            <span class="input-prefix input-prefix--country" aria-hidden="true">
                                                <svg viewBox="0 0 20 20" focusable="false">
                                                    <path d="M6.1 2.8 8.4 6l-1.6 1.7a12 12 0 0 0 5.5 5.5l1.7-1.6 3.2 2.3-.8 3a1.2 1.2 0 0 1-1.3.9A14.4 14.4 0 0 1 2.7 5a1.2 1.2 0 0 1 .9-1.3z"/>
                                                </svg>
                                                <span>+63</span>
                                            </span>

                                            <input
                                                type="tel"
                                                name="phone"
                                                autocomplete="tel-national"
                                                inputmode="numeric"
                                                placeholder="9XXXXXXXXX"
                                                required
                                                minlength="10"
                                                maxlength="10"
                                                pattern="9[0-9]{9}"
                                                value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="phone"
                                            >
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Facebook Link <span class="required">*</span></label>

                                        <div class="input-wrapper">
                                            <span class="input-prefix input-prefix--facebook" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" focusable="false">
                                                    <path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/>
                                                </svg>
                                                <span>facebook.com/</span>
                                            </span>

                                            <input
                                                type="text"
                                                name="facebook"
                                                autocomplete="off"
                                                placeholder="Your Facebook username"
                                                required
                                                minlength="5"
                                                maxlength="50"
                                                pattern="[A-Za-z0-9.]{5,50}"
                                                value="<?= htmlspecialchars($facebook, ENT_QUOTES, 'UTF-8') ?>"
                                                data-validation="facebook"
                                            >
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <div class="bottom-link">
                                    Already a
                                    <?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>?
                                    <a href="login.php">Log in</a>
                                </div>

                                <button type="button" class="btn-primary" data-next>
                                    <span>Continue</span>
                                </button>
                            </div>
                        </section>

                        <!-- ============ STEP 2: Password & terms ============ -->
                        <section class="step" data-step="2" <?= $startStep === 2 ? '' : 'hidden' ?>>
                            <div class="card-top">
                                <button type="button" class="back-btn" data-back aria-label="Back to previous step">
                                    <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                                        <path d="M12.5 4 6.5 10l6 6"/>
                                    </svg>
                                </button>
                            </div>

                            <h2>Secure your account</h2>
                            <p class="hint">Step 2 of 2 · Create a password and agree to our terms.</p>

                            <?php if ($errorMessage !== '' && $errorStep === 2): ?>
                                <p class="error" role="alert">
                                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>

                            <div class="form-section">
                                <h3 class="section-title">Password</h3>

                                <div class="field-stack">
                                    <div class="form-group">
                                        <label>Create Password <span class="required">*</span></label>

                                        <div class="input-wrapper">
                                            <input
                                                type="password"
                                                name="password"
                                                autocomplete="new-password"
                                                placeholder="8–16 characters with a symbol"
                                                required
                                                minlength="8"
                                                maxlength="16"
                                                pattern="(?!.*\s)(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}"
                                                data-validation="password"
                                            >

                                            <button class="eye-icon" type="button" aria-label="Show password" aria-pressed="false">
                                                <svg viewBox="0 0 24 24" focusable="false">
                                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                    <path class="eye-slash" d="m4 4 16 16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Confirm Password <span class="required">*</span></label>

                                        <div class="input-wrapper">
                                            <input
                                                type="password"
                                                name="confirm_password"
                                                autocomplete="new-password"
                                                placeholder="Confirm Password"
                                                required
                                                minlength="8"
                                                maxlength="16"
                                                pattern="(?!.*\s)(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}"
                                                data-validation="confirm-password"
                                            >

                                            <button class="eye-icon" type="button" aria-label="Show password" aria-pressed="false">
                                                <svg viewBox="0 0 24 24" focusable="false">
                                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                    <path class="eye-slash" d="m4 4 16 16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3 class="section-title">Agreement</h3>

                                <div class="form-group terms-group">
                                    <input
                                        type="checkbox"
                                        id="terms"
                                        name="terms"
                                        required
                                        data-validation="terms"
                                    >

                                    <label for="terms">
                                        I agree to the
                                        <button type="button" class="policy-link" data-policy="terms-of-residence">Terms of Residence</button>,
                                        <button type="button" class="policy-link" data-policy="student-code-of-conduct">Student Code of Conduct</button>, and
                                        <button type="button" class="policy-link" data-policy="privacy-policy">Privacy Policy</button>.
                                    </label>
                                </div>
                            </div>

                            <div class="form-actions">
                                <div class="bottom-link">
                                    Already a
                                    <?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>?
                                    <a href="login.php">Log in</a>
                                </div>

                                <button type="submit" class="btn-primary">
                                    <span>Create account</span>
                                </button>
                            </div>
                        </section>
                    </form>
                </div>
            </section>

        </div>
    </main>

    <!-- Policy Viewer -->
    <div class="policy-modal" id="policyModal" hidden>
        <div class="policy-modal-backdrop" data-policy-close></div>

        <div class="policy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="policyModalTitle">
            <div class="policy-modal-header">
                <h2 id="policyModalTitle">Policy</h2>
                <button type="button" class="policy-modal-close" data-policy-close aria-label="Close policy">&times;</button>
            </div>

            <div class="policy-modal-body" id="policyModalBody"></div>

            <div class="policy-modal-footer">
                <a id="policyModalPageLink" href="#" target="_blank" rel="noopener">Open full page</a>
                <button type="button" class="btn-secondary" data-policy-close>Close</button>
            </div>
        </div>
    </div>

    <script id="policyData" type="application/json">
        <?= json_encode($policyPages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>

    <script src="../assets/js/register.js"></script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>