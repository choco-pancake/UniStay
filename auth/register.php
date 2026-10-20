<?php
$type = isset($_GET['type']) && $_GET['type'] === 'landlord'
    ? 'landlord'
    : 'tenant';

$isLandlord = $type === 'landlord';
$pageTitle = $isLandlord ? 'Landlord Sign Up' : 'Tenant Sign Up';
$heading = $isLandlord
    ? 'Your Space, Their New Home'
    : 'Your New Place Starts Here';

$switchText = $isLandlord
    ? 'Looking for a place to stay?'
    : 'Looking to list a property?';

$switchLinkText = $isLandlord
    ? 'Sign up as Tenant'
    : 'Apply as Landlord';

$switchType = $isLandlord ? 'tenant' : 'landlord';
$accountType = $type;

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/google_oauth.php';

google_oauth_start_session();

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';

$firstName = '';
$middleName = '';
$lastName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read and preserve submitted form values.
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    $phone = trim((string) ($_POST['phone'] ?? ''));
    $messenger = trim((string) ($_POST['messenger'] ?? ''));

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    // Combine the name fields because the database has one "name" column.
    $name = trim(implode(' ', array_filter(
        [$firstName, $middleName, $lastName],
        static fn ($part) => $part !== ''
    )));

    $nameLength = preg_match_all('/./us', $name);

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
    ) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
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
    } elseif (!preg_match('/^[A-Za-z0-9.]{5,50}$/', $messenger)) {
        $errorMessage = 'Enter a valid Messenger username containing 5–50 letters, numbers, or periods.';
    } elseif (
        !is_string($password)
        || !preg_match(
            '/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/',
            $password
        )
    ) {
        $errorMessage = 'Your password must be 8–16 characters and include uppercase and lowercase letters, a number, and a symbol, with no spaces.';
    } elseif (
        !is_string($confirmPassword)
        || !hash_equals($password, $confirmPassword)
    ) {
        $errorMessage = 'The passwords do not match.';
    } elseif (!isset($_POST['terms'])) {
        $errorMessage = 'Please agree to the terms and policies before registering.';
    } else {
        try {
            $statement = database()->prepare(
                'INSERT INTO users (name, email, password_hash, role)
                 VALUES (?, ?, ?, ?)'
            );

            $statement->execute([
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $type
            ]);

            $userId = (int) database()->lastInsertId();

            session_regenerate_id(true);

            $_SESSION['user_id'] = $userId;
            $_SESSION['role'] = $type;
            $_SESSION['user'] = [
                'name' => $name,
                'email' => $email,
                'photo' => '',
            ];

            // Prevent the registration form from being resubmitted on refresh.
            header(
                'Location: ../' . rawurlencode($type) . '/dashboard.php',
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>UniStay - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="../assets/css/register.css">
</head>
<body>

    <header class="header">
        <a class="logo-container" href="../index.php" aria-label="UniStay home">
            <img class="logo-icon" src="../assets/images/logo.png" alt="UniStay logo">
            <span class="logo-text">UniStay</span>
        </a>
    </header>

    <main class="container">
        <div class="top-switch">
            <?= htmlspecialchars($switchText, ENT_QUOTES, 'UTF-8') ?>

            <a href="register.php?type=<?= rawurlencode($switchType) ?>">
                <?= htmlspecialchars($switchLinkText, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>

        <div class="card">
            <h1><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>

            <p class="subtitle">
                Description Description Description Description
            </p>

            <?php if ($errorMessage !== ''): ?>
                <p class="error" role="alert">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <form
                method="post"
                action="register.php?type=<?= rawurlencode($type) ?>"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="form-grid">

                    <!-- Full Name -->
                    <div class="form-group">
                        <label>
                            Full Name <span class="required">*</span>
                        </label>

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

                    <!-- Email Address -->
                    <div class="form-group">
                        <label>
                            Email Address <span class="required">*</span>
                        </label>

                        <div class="input-wrapper">
                            <span class="input-prefix" aria-hidden="true">
                                <svg viewBox="0 0 20 20" focusable="false">
                                    <path d="M2.5 4.5h15v11h-15zM3 5l7 5 7-5"/>
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

                    <!-- Phone Number -->
                    <div class="form-group">
                        <label>
                            Phone Number <span class="required">*</span>
                        </label>

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
                                value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-validation="phone"
                            >
                        </div>
                    </div>

                    <!-- Messenger Link -->
                    <div class="form-group">
                        <label>
                            Messenger Link <span class="required">*</span>
                        </label>

                        <div class="input-wrapper">
                            <span class="input-prefix input-prefix--messenger" aria-hidden="true">
                                <svg viewBox="0 0 20 20" focusable="false">
                                    <path d="M10 2.5a7 7 0 0 0-6.1 10.4L3 17l4.2-1a7 7 0 1 0 2.8-13.5z"/>
                                    <path d="m6.5 11 3-3 2 2 2.5-2"/>
                                </svg>
                                <span>https://m.me/</span>
                            </span>

                            <input
                                type="text"
                                name="messenger"
                                autocomplete="url"
                                placeholder="Your Messenger username"
                                required
                                minlength="5"
                                maxlength="50"
                                pattern="[A-Za-z0-9.]{5,50}"
                                value="<?= htmlspecialchars($messenger ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-validation="messenger"
                            >
                        </div>
                    </div>

                    <!-- Create Password -->
                    <div class="form-group">
                        <label>
                            Create Password <span class="required">*</span>
                        </label>

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

                            <button
                                class="eye-icon"
                                type="button"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                <svg viewBox="0 0 20 20" focusable="false">
                                    <path d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5z"/>
                                    <circle cx="10" cy="10" r="2.3"/>
                                    <path class="eye-slash" d="m3 3 14 14"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label>
                            Confirm Password <span class="required">*</span>
                        </label>

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

                            <button
                                class="eye-icon"
                                type="button"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                <svg viewBox="0 0 20 20" focusable="false">
                                    <path d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5z"/>
                                    <circle cx="10" cy="10" r="2.3"/>
                                    <path class="eye-slash" d="m3 3 14 14"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Terms and Policies -->
                    <div class="form-group full-width terms-group">
                        <input
                            type="checkbox"
                            id="terms"
                            name="terms"
                            required
                            data-validation="terms"
                        >

                        <label for="terms">
                            I agree to the
                            <a href="#">Terms of Residence</a>,
                            <a href="#">Student Code of Conduct</a>, and
                            <a href="#">Privacy Policy</a>.
                        </label>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <span>Create Account</span>
                    </button>

                    <div class="bottom-link">
                        Already a
                        <?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>?
                        <a href="login.php">Log in</a>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script src="../assets/js/register.js"></script>
</body>
</html>