<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();

if (isset($_GET['action']) && $_GET['action'] === 'clear_google') {
    unset($_SESSION['google_pending']);
    $targetRole = (isset($_GET['role']) && $_GET['role'] === 'landlord') ? 'landlord' : 'tenant';
    header('Location: google_oauth.php?role=' . urlencode($targetRole), true, 303);
    exit;
}

// If an already signed-in user visits register.php, clear the prior session
// so they get a fresh, unrestricted sign-up experience.
if (session_user() !== null) {
    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['role']);
}

$pending = $_SESSION['google_pending'] ?? null;
$isGoogle = is_array($pending) && !empty($pending['email']);

// Determine role (default: tenant)
$roleParam = (string) ($_GET['type'] ?? $_GET['role'] ?? $_POST['role'] ?? ($pending['role'] ?? 'tenant'));
$role = in_array($roleParam, ['tenant', 'landlord'], true) ? $roleParam : 'tenant';
$isLandlord = ($role === 'landlord');

$pageTitle = $isGoogle ? 'Finish Your Account' : ($isLandlord ? 'Sign Up as Landlord' : 'Sign Up');

if (!function_exists('email_taken')) {
    function email_taken(string $email): bool
    {
        try {
            $statement = database()->prepare('SELECT 1 FROM users WHERE personal_email = ? LIMIT 1');
            $statement->execute([$email]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $error) {
            error_log('UniStay duplicate check failed: ' . $error->getMessage());
            return false;
        }
    }
}

if (!isset($_SESSION['auth_csrf_token'])) {
    $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
}

$email = $isGoogle ? (string) ($pending['email'] ?? '') : '';
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $terms = isset($_POST['terms']);

    $submittedRole = (string) ($_POST['role'] ?? $role);
    $role = in_array($submittedRole, ['tenant', 'landlord'], true) ? $submittedRole : 'tenant';
    $isLandlord = ($role === 'landlord');

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['auth_csrf_token'], $submittedToken)
    ) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif (!$terms) {
        $errorMessage = 'Please agree to the Terms of Service, Code of Conduct, and Privacy Policy.';
    } elseif ($isGoogle) {
        // --- GOOGLE SIGN UP ---
        $email = (string) ($pending['email'] ?? '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errorMessage = 'Invalid Google account email. Please try signing in again.';
        } elseif (email_taken($email)) {
            $errorMessage = 'An account with this email address already exists. Try signing in instead.';
        } else {
            $photo = !empty($pending['picture']) ? (string) $pending['picture'] : null;
            $googleName = trim((string) ($pending['name'] ?? ''));
            $firstName = null;
            $middleName = null;
            $lastName = null;

            if ($googleName !== '') {
                $parts = preg_split('/\s+/u', $googleName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($parts) === 1) {
                    $firstName = $parts[0];
                } elseif (count($parts) === 2) {
                    $firstName = $parts[0];
                    $lastName = $parts[1];
                } elseif (count($parts) > 2) {
                    $firstName = $parts[0];
                    $lastName = array_pop($parts);
                    $middleName = implode(' ', array_slice($parts, 1));
                }
            }

            try {
                $statement = database()->prepare(
                    'INSERT INTO users (first_name, middle_name, last_name, personal_email, password_hash, role, photo, profile_completed)
                     VALUES (?, ?, ?, ?, NULL, ?, ?, 0)'
                );
                $statement->execute([
                    $firstName,
                    $middleName,
                    $lastName,
                    $email,
                    $role,
                    $photo,
                ]);

                $userId = (int) database()->lastInsertId();

                session_regenerate_id(true);
                unset($_SESSION['google_pending']);

                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = $role;
                $_SESSION['user'] = [
                    'id' => $userId,
                    'name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $email,
                    'email' => $email,
                    'photo' => $photo ?? '',
                ];

                if ($role === 'landlord') {
                    $_SESSION['is_new_landlord_registration'] = true;
                    header('Location: ../landlord/dashboard.php', true, 303);
                } else {
                    $_SESSION['show_profile_completion_toast'] = true;
                    $_SESSION['is_new_tenant_registration'] = true;
                    header('Location: ../tenant/dorms.php', true, 303);
                }
                exit;
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    $errorMessage = 'An account with this email address already exists. Try signing in instead.';
                } else {
                    error_log('UniStay Google account registration failed: ' . $error->getMessage());
                    $errorMessage = 'Registration is temporarily unavailable. Check the database setup and try again.';
                }
            }
        }
    } else {
        // --- MANUAL SIGN UP ---
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254
        ) {
            $errorMessage = 'Enter a valid email address.';
        } elseif (email_taken($email)) {
            $errorMessage = 'An account with this email address already exists. Try signing in instead.';
        } elseif (
            !is_string($password)
            || !preg_match(
                '/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,16}$/',
                $password
            )
        ) {
            $errorMessage = 'Password must be 8–16 characters with uppercase, lowercase, a number, and a symbol (no spaces).';
        } elseif (
            !is_string($confirmPassword)
            || !hash_equals($password, $confirmPassword)
        ) {
            $errorMessage = 'The passwords do not match.';
        } else {
            try {
                $statement = database()->prepare(
                    'INSERT INTO users (personal_email, password_hash, role, profile_completed)
                     VALUES (?, ?, ?, 0)'
                );
                $statement->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                ]);

                $userId = (int) database()->lastInsertId();

                session_regenerate_id(true);

                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = $role;
                $_SESSION['user'] = [
                    'id' => $userId,
                    'name' => $email,
                    'email' => $email,
                    'photo' => '',
                ];

                if ($role === 'landlord') {
                    $_SESSION['is_new_landlord_registration'] = true;
                    header('Location: ../landlord/dashboard.php', true, 303);
                } else {
                    $_SESSION['show_profile_completion_toast'] = true;
                    $_SESSION['is_new_tenant_registration'] = true;
                    header('Location: ../tenant/dorms.php', true, 303);
                }
                exit;
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    $errorMessage = 'An account with this email address already exists. Try signing in instead.';
                } else {
                    error_log('UniStay manual account registration failed: ' . $error->getMessage());
                    $errorMessage = 'Registration is temporarily unavailable. Check the database setup and try again.';
                }
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
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> · UniStay</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="stylesheet" href="../assets/css/topbar.css">
    <link rel="stylesheet" href="../assets/css/register.css?v=30">
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

            <!-- Brown left panel -->
            <section class="panel">
                <h1 id="panelTitle" class="role-fade-target">
                    <span><?= $isLandlord ? 'List your place' : 'Your new place' ?></span><br>
                    <span><?= $isLandlord ? 'with confidence.' : 'starts here.' ?></span>
                </h1>
                <p class="panel-copy role-fade-target" id="panelCopy">
                    <?= $isLandlord
                        ? 'Publish verified rooms, connect directly with reliable students, and manage your tenancies with ease.'
                        : 'Find verified rooms near your university, book with confidence, and settle in without the guesswork.' ?>
                </p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <!-- Form side -->
            <section class="form-side">
                <div class="form-main">
                    <?php if ($isGoogle): ?>
                        <!-- ============ GOOGLE FLOW: FINISH YOUR ACCOUNT ============ -->
                        <div class="form-header-row">
                            <h2 class="form-title">Finish your account</h2>
                            <div class="role-toggle tabs" role="tablist" aria-label="Account role">
                                <span class="tab-indicator" aria-hidden="true"></span>
                                <button type="button" class="role-pill tab <?= !$isLandlord ? 'is-active active' : '' ?>" data-role="tenant" role="tab" aria-selected="<?= !$isLandlord ? 'true' : 'false' ?>">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 10 12 5 2 10l10 5 10-5z"></path><path d="M6 12v5c3 2 9 2 12 0v-5"></path></svg>
                                    <span>Tenant</span>
                                </button>
                                <button type="button" class="role-pill tab <?= $isLandlord ? 'is-active active' : '' ?>" data-role="landlord" role="tab" aria-selected="<?= $isLandlord ? 'true' : 'false' ?>">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                    <span>Landlord</span>
                                </button>
                            </div>
                        </div>

                        <?php if ($errorMessage !== ''): ?>
                            <p class="alert" role="alert">
                                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>

                        <form method="post" action="register.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="google-badge-notice">
                                <div class="google-badge-avatar">
                                    <?php if (!empty($pending['picture'])): ?>
                                        <img src="<?= htmlspecialchars($pending['picture'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="8" r="3"></circle><path d="M5.5 18a8.5 8.5 0 0 1 13 0"></path></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="google-badge-info">
                                    <span class="badge-title" id="googleBadgeTitle">Signing up as <?= $isLandlord ? 'Landlord' : 'Tenant' ?></span>
                                    <span class="badge-email"><?= htmlspecialchars($pending['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <a href="register.php?action=clear_google&role=<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" id="googleChangeLink" class="google-badge-switch" title="Sign in with a different Google account">Change</a>
                            </div>

                            <div class="terms-group">
                                <label class="terms-label" for="termsCheckboxGoogle">
                                    <input
                                        type="checkbox"
                                        id="termsCheckboxGoogle"
                                        name="terms"
                                        required
                                        <?= isset($_POST['terms']) ? 'checked' : '' ?>
                                    >
                                    <span class="terms-text" id="termsTextGoogle">
                                        I agree to the
                                        <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>,
                                        <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and
                                        <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.
                                    </span>
                                </label>
                            </div>

                            <div class="actions">
                                <p class="signin-switch">Already have an account? <a href="login.php">Log in</a></p>
                                <button type="submit" class="btn-register">
                                    Create account
                                </button>
                            </div>
                        </form>

                    <?php else: ?>
                        <!-- ============ MANUAL FLOW: CREATE YOUR ACCOUNT ============ -->
                        <div class="form-header-row">
                            <h2 class="form-title role-fade-target" id="formTitle"><?= $isLandlord ? 'Landlord Sign Up' : 'Create your account' ?></h2>
                            <div class="role-toggle tabs" role="tablist" aria-label="Account role">
                                <span class="tab-indicator" aria-hidden="true"></span>
                                <button type="button" class="role-pill tab <?= !$isLandlord ? 'is-active active' : '' ?>" data-role="tenant" role="tab" aria-selected="<?= !$isLandlord ? 'true' : 'false' ?>">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 10 12 5 2 10l10 5 10-5z"></path><path d="M6 12v5c3 2 9 2 12 0v-5"></path></svg>
                                    <span>Tenant</span>
                                </button>
                                <button type="button" class="role-pill tab <?= $isLandlord ? 'is-active active' : '' ?>" data-role="landlord" role="tab" aria-selected="<?= $isLandlord ? 'true' : 'false' ?>">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21V10l6-4 6 4v11M15 21V13h6v8M2 21h20"></path></svg>
                                    <span>Landlord</span>
                                </button>
                            </div>
                        </div>

                        <?php if ($errorMessage !== ''): ?>
                            <p class="alert" role="alert">
                                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>

                        <form method="post" action="register.php" id="registerForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

                            <!-- Email Field -->
                            <div class="field">
                                <label for="reg-email">Email Address</label>
                                <div class="control">
                                    <svg class="lead-icon" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                        <path d="m3 7 9 6 9-6"></path>
                                    </svg>
                                    <input
                                        type="email"
                                        id="reg-email"
                                        name="email"
                                        autocomplete="email"
                                        placeholder="Email address"
                                        required
                                        maxlength="254"
                                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                            </div>

                            <!-- Password Field -->
                            <div class="field">
                                <label for="reg-password">Password</label>
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
                                        placeholder="8–16 characters with a symbol"
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
                                        placeholder="Confirm password"
                                        required
                                        minlength="8"
                                        maxlength="16"
                                    >
                                    <button class="eye" type="button" id="toggleRegConfirmPassword" aria-label="Show password" aria-pressed="false">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                            <path class="slash" d="m4 4 16 16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Single line Google Sign up row directly at the bottom of confirm password -->
                            <div class="google-line">
                                <span class="or-separator">or</span>
                                <a href="google_oauth.php?role=<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" class="btn-google-line" id="googleSignUpBtn">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path fill="#4285F4" d="M23 12.27c0-.8-.07-1.56-.2-2.3H12v4.36h6.17a5.28 5.28 0 0 1-2.29 3.46v2.87h3.7C21.74 18.7 23 15.7 23 12.27z"/>
                                        <path fill="#34A853" d="M12 23.5c3.1 0 5.7-1.03 7.58-2.79l-3.7-2.87c-1.03.69-2.35 1.1-3.88 1.1-2.98 0-5.5-2.01-6.4-4.72H1.78v2.96A11.5 11.5 0 0 0 12 23.5z"/>
                                        <path fill="#FBBC05" d="M5.6 14.22a6.9 6.9 0 0 1 0-4.44V6.82H1.78a11.5 11.5 0 0 0 0 10.36l3.82-2.96z"/>
                                        <path fill="#EA4335" d="M12 5.06c1.69 0 3.2.58 4.39 1.72l3.29-3.29C17.7 1.64 15.1.5 12 .5 7.52.5 3.65 3.07 1.78 6.82l3.82 2.96C6.5 7.07 9.02 5.06 12 5.06z"/>
                                    </svg>
                                    <span>Sign up with Google</span>
                                </a>
                            </div>

                            <!-- Terms & Policies Checkbox -->
                            <div class="terms-group">
                                <label class="terms-label" for="termsCheckboxManual">
                                    <input
                                        type="checkbox"
                                        id="termsCheckboxManual"
                                        name="terms"
                                        required
                                        <?= isset($_POST['terms']) ? 'checked' : '' ?>
                                    >
                                    <span class="terms-text role-fade-target" id="termsTextManual">
                                        I agree to the
                                        <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>,
                                        <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and
                                        <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.
                                    </span>
                                </label>
                            </div>

                            <div class="actions">
                                <p class="signin-switch">Already have an account? <a href="login.php">Log in</a></p>
                                <button type="submit" class="btn-register" id="submitBtn">
                                    <span>Create account</span>
                                </button>
                            </div>
                        </form>
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

    <script src="../assets/js/register.js?v=4"></script>
    <script src="../assets/js/page-transition.js"></script>
</body>
</html>