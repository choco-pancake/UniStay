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

if ($emailParam !== '') {
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
                <h1 id="panelTitle">
                    <span>Your property</span><br>
                    <span>starts here.</span>
                </h1>
                <p class="panel-copy" id="panelCopy">
                    List verified student dorms, connect with trusted tenants, and manage your bookings seamlessly with UniStay.
                </p>
                <p class="tagline">LIVE &bull; STUDY &bull; THRIVE</p>
            </section>

            <!-- Form side -->
            <section class="form-side">
                <div class="form-main">
                    <?php if ($isApproved && $landlord): ?>
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

                    <footer class="form-foot">
                        <p class="signin-text">
                            Already have your password? <a href="login.php" class="link-signin">Sign in</a>
                        </p>
                    </footer>
                </div>
            </section>
        </div>
    </main>

    <script>
        // Eye toggles for passwords
        function setupEye(btnId, inputId) {
            const btn = document.getElementById(btnId);
            const input = document.getElementById(inputId);
            if (!btn || !input) return;
            btn.addEventListener('click', () => {
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                btn.setAttribute('aria-pressed', isPass ? 'true' : 'false');
                const open = btn.querySelector('.eye-open');
                const closed = btn.querySelector('.eye-closed');
                if (open) open.style.display = isPass ? 'none' : 'block';
                if (closed) closed.style.display = isPass ? 'block' : 'none';
            });
        }
        setupEye('toggleRegPassword', 'reg-password');
        setupEye('toggleRegConfirm', 'reg-confirm-password');
    </script>
</body>
</html>
