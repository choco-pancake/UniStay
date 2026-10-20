<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/google_oauth.php';

google_oauth_start_session();

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
                'SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1'
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
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'photo' => '',
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

$user = $_SESSION['user'] ?? [];
$is_logged_in = is_array($user) && isset($_SESSION['role']);
$user_name = $is_logged_in ? (string)($user['name'] ?? '') : '';
$user_email = $is_logged_in ? (string)($user['email'] ?? '') : '';
$user_photo = $is_logged_in ? (string)($user['photo'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Sign in</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-color: #F8F5F0;
            --card-brown: #7C4B39;
            --card-brown-gradient: linear-gradient(135deg, #7C4B39 0%, #683D2E 100%);
            --text-dark: #2B211E;
            --text-muted: #8C7C75;
            --input-bg: #F4EFEA;
            --input-border: #E8DFD7;
            --button-brown: #5C3325;
            --button-hover: #48271B;
            --border-light: #ECE5DE;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Navigation */
        .header {
            padding: 18px 40px;
            background-color: transparent;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-dark);
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1.5px solid var(--text-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .brand-name {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        /* Main Container */
        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        /* Sign-in Card */
        .auth-card {
            width: 100%;
            max-width: 900px;
            background: #FFFFFF;
            border-radius: 16px;
            display: flex;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(92, 51, 37, 0.07);
            min-height: 480px;
        }

        /* Left Banner Section */
        .card-banner {
            flex: 1;
            background: var(--card-brown-gradient);
            padding: 48px 40px;
            color: #FFFFFF;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .banner-title {
            font-family: 'Playfair Display', serif;
            font-size: 42px;
            line-height: 1.15;
            font-weight: 600;
            margin-top: 10px;
            margin-bottom: 24px;
        }

        .banner-description {
            font-size: 13px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.7);
            max-width: 340px;
        }

        .banner-footer {
            font-size: 12px;
            letter-spacing: 3px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.85);
            text-transform: uppercase;
            margin-top: 40px;
        }

        /* Right Form Section */
        .card-form-wrapper {
            flex: 1.1;
            padding: 40px 48px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #FFFFFF;
        }

        .form-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #6B5E57;
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: #A0938C;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-dark);
            outline: none;
            transition: border-color 0.2s, background-color 0.2s;
        }

        .form-input:focus {
            border-color: var(--card-brown);
            background-color: #FFFFFF;
        }

        .form-input::placeholder {
            color: #B5A8A0;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            background: none;
            border: none;
            cursor: pointer;
            color: #A0938C;
            display: flex;
            align-items: center;
        }

        .forgot-link {
            display: block;
            text-align: right;
            font-size: 11px;
            color: var(--text-muted);
            text-decoration: none;
            margin-top: 6px;
            font-weight: 500;
        }

        .forgot-link:hover {
            color: var(--button-brown);
        }

        .btn-submit-container {
            text-align: center;
            margin-top: 18px;
            margin-bottom: 14px;
        }

        .btn-primary {
            background-color: var(--button-brown);
            color: #FFFFFF;
            border: none;
            padding: 10px 32px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            min-width: 100px;
        }

        .btn-primary:hover {
            background-color: var(--button-hover);
        }

        .divider-or {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #A0938C;
            margin: 10px 0;
            letter-spacing: 0.5px;
        }

        /* Google Sign In Button */
        .btn-google {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            padding: 12px 18px;
            background: #FFFFFF;
            border: 1px solid #E0D6CE;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            color: #4A3E39;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s, border-color 0.2s;
        }

        .btn-google:hover {
            background-color: #FAF8F5;
            border-color: #C8BDB4;
        }

        .google-role-select {
            display: block;
            width: min(100%, 210px);
            min-height: 36px;
            margin: 10px auto 0;
            padding: 8px 12px;
            border: 1px solid #E0D6CE;
            border-radius: 6px;
            background: #FFFFFF;
            color: #4A3E39;
            font: inherit;
        }

        /* Footer Links inside Card */
        .card-bottom-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 16px;
            margin-top: 20px;
            border-top: 1px solid var(--border-light);
            font-size: 11px;
            color: var(--text-muted);
        }

        .path-links {
            display: flex;
            gap: 16px;
        }

        .path-link {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
        }

        .path-link:hover {
            color: var(--button-brown);
        }

        /* Logged in state dashboard card */
        .logged-in-profile {
            text-align: center;
            padding: 20px 0;
        }

        .avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            margin-bottom: 12px;
            object-fit: cover;
            border: 2px solid var(--card-brown);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .auth-card {
                flex-direction: column;
            }
            .card-banner {
                padding: 30px 24px;
            }
            .card-form-wrapper {
                padding: 30px 24px;
            }
            .banner-title {
                font-size: 32px;
            }
            .card-bottom-bar {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="header">
        <a href="#" class="brand-logo">
            <div class="logo-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
            </div>
            <span class="brand-name">UniStay</span>
        </a>
    </header>

    <!-- Main Container -->
    <main class="main-container">
        <div class="auth-card">

            <!-- Left Side Banner -->
            <div class="card-banner">
                <div>
                    <h1 class="banner-title">Your room is<br>waiting.</h1>
                    <p class="banner-description">
                        Discover convenient, comfortable, and affordable student housing solutions tailored for your university journey.
                    </p>
                </div>
                <div class="banner-footer">
                    LIVE &nbsp;&bull;&nbsp; STUDY &nbsp;&bull;&nbsp; THRIVE
                </div>
            </div>

            <!-- Right Side Form -->
            <div class="card-form-wrapper">

                <?php if ($is_logged_in): ?>
                    <!-- State when user is signed in -->
                    <div class="logged-in-profile">
                        <img src="<?= htmlspecialchars($user_photo ? $user_photo : 'https://via.placeholder.com/70') ?>" class="avatar" alt="User Profile">
                        <h2 style="font-size: 20px; color: var(--text-dark); margin-bottom: 6px;">Welcome back!</h2>
                        <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;"><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_email) ?>)</p>

                        <a href="login.php?action=logout" class="btn-primary" style="display: inline-block; text-decoration: none;">Sign Out</a>
                    </div>
                <?php else: ?>
                    <!-- Sign In Form -->
                    <div>
                        <h2 class="form-title">Sign in to UniStay</h2>

                        <form action="login.php" method="POST">
                            <input type="hidden" name="action" value="email_login">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['auth_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($login_error !== ''): ?>
                                <p role="alert" style="color: #9d3028; margin-bottom: 16px;"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <!-- Email Input -->
                            <div class="form-group">
                                <label class="form-label">Enter your email</label>
                                <div class="input-wrapper">
                                    <span class="input-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                    </span>
                                    <input type="email" name="email" class="form-input" placeholder="Email address" value="<?= htmlspecialchars($login_email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" required>
                                </div>
                            </div>

                            <!-- Password Input -->
                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <div class="input-wrapper">
                                    <span class="input-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                    </span>
                                    <input type="password" id="password" name="password" class="form-input" placeholder="••••••••••••" autocomplete="current-password" required>
                                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                                        <svg id="eye-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                                <a href="#" class="forgot-link">Forgot Password?</a>
                            </div>

                            <!-- Submit Button -->
                            <div class="btn-submit-container">
                                <button type="submit" class="btn-primary">Log in</button>
                            </div>
                        </form>

                        <div class="divider-or">OR</div>

                        <!-- Google Sign In -->
                        <a class="btn-google" id="google-sign-in" href="../tenant/dashboard.php">
                            <svg width="16" height="16" viewBox="0 0 24 24">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                            </svg>
                            <span>Sign in with Google</span>
                        </a>
                        <label for="google-account-type" class="form-label" style="margin-top: 12px; text-align: center;">Choose account type</label>
                        <select class="google-role-select" id="google-account-type">
                            <option value="tenant">Tenant</option>
                            <option value="landlord">Landlord</option>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Bottom Bar inside Card -->
                <div class="card-bottom-bar">
                    <span>New to UniStay? Choose your path!</span>
                    <div class="path-links">
                        <a href="register.php?role=tenant" class="path-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            </svg>
                            Find a Home
                        </a>
                        <a href="register.php?role=landlord" class="path-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            List a Property
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        const googleAccountType = document.getElementById('google-account-type');
        const googleSignIn = document.getElementById('google-sign-in');
        googleAccountType.addEventListener('change', function () {
            googleSignIn.href = '../' + this.value + '/dashboard.php';
        });

        // Password Visibility Toggle
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
            }
        }
    </script>
</body>
</html>
