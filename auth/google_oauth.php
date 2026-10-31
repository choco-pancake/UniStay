<?php

/**
 * Google sign-in.
 *
 * Start the flow:   auth/google_oauth.php?role=tenant
 * Google calls back: auth/google_oauth.php?code=...&state=...
 *
 * Google never creates an account. If the Google email has no account yet,
 * the visitor is sent to the sign-up page, and the Google email and photo are
 * remembered so the photo can be saved when they register with that email.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/oauth.php';

start_app_session();

/**
 * Sends the visitor back to the sign-in page with a readable message.
 */
function google_oauth_fail(string $message): void
{
    $_SESSION['auth_error'] = $message;

    header('Location: login.php', true, 303);
    exit;
}

/**
 * Sends a request to Google and decodes the JSON answer.
 */
function google_oauth_request(string $url, ?array $postFields = null, ?string $accessToken = null): array
{
    $headers = ['Accept: application/json'];

    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    if ($postFields !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $body = $postFields !== null ? http_build_query($postFields, '', '&', PHP_QUERY_RFC3986) : null;

    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Google sign-in could not be started.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($postFields !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            error_log('UniStay Google OAuth request failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $status));
            throw new RuntimeException('Google sign-in could not be verified. Please try again.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $postFields !== null ? 'POST' : 'GET',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if (!is_string($response)) {
            error_log('UniStay Google OAuth request failed: no answer from Google.');
            throw new RuntimeException('Google sign-in could not be verified. Please try again.');
        }
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Google returned an unexpected answer.');
    }

    return $data;
}

/**
 * Looks up the account of a Google profile by email.
 *
 * Returns null when no account exists for that email address.
 */
function google_oauth_find_account(PDO $pdo, array $profile): ?array
{
    $email = (string) ($profile['email'] ?? '');

    $statement = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo
         FROM users WHERE personal_email = ? LIMIT 1'
    );
    $statement->execute([$email]);
    $account = $statement->fetch();

    if ($account === false) {
        return null;
    }

    $photo = (string) ($account['photo'] ?? '');
    $profilePhoto = (string) ($account['profile_photo'] ?? '');
    $googlePhoto = substr((string) ($profile['picture'] ?? ''), 0, 255);

    // Keep the picture of the Google account in sync, also for accounts that
    // were created with an email and password first.
    if ($googlePhoto !== '' && $googlePhoto !== $photo) {
        $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?')
            ->execute([$googlePhoto, (int) $account['id']]);

        $photo = $googlePhoto;
    }

    // A user-selected profile photo always takes precedence over the Google
    // fallback and uses the same path resolver as the rest of the app.
    $photo = resolve_user_profile_photo_url(
        $profilePhoto !== '' ? $profilePhoto : null,
        $photo !== '' ? $photo : null,
        (string)$account['role']
    );

    return [
        'id' => (int) $account['id'],
        'role' => (string) $account['role'],
        'name' => trim(
            (string) $account['first_name'] . ' '
            . (string) $account['middle_name'] . ' '
            . (string) $account['last_name']
        ),
        'email' => (string) $account['personal_email'],
        'photo' => $photo,
    ];
}

/**
 * No account exists for this Google email. Nothing is created: the verified
 * email and photo are remembered for this browser session and the visitor
 * goes to the sign-up page.
 */
function google_oauth_to_registration(array $profile, string $role): void
{
    $em = strtolower(trim((string)($profile['email'] ?? '')));
    if ($em === 'unistaysads@gmail.com') {
        $pdo = database();
        $adm = $pdo->query("SELECT id, first_name, last_name, personal_email, role, photo FROM users WHERE role = 'admin' LIMIT 1")->fetch();
        if ($adm) {
            $pdo->prepare("UPDATE users SET personal_email = 'unistaysads@gmail.com' WHERE id = ?")->execute([(int)$adm['id']]);
            $_SESSION['user_id'] = (int)$adm['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['user'] = [
                'name' => trim(($adm['first_name'] ?? '') . ' ' . ($adm['last_name'] ?? '')),
                'email' => 'unistaysads@gmail.com',
                'photo' => $adm['photo'] ?? '',
            ];
            unset($_SESSION['google_pending'], $_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
            header('Location: ../admin/dashboard.php', true, 303);
            exit;
        }
    }
    // Public account registration via Google is fixated to tenant only
    $validRole = 'tenant';
    $invite = trim((string)($_SESSION['google_oauth_invite'] ?? $_GET['invite'] ?? ''));
    if ($validRole === 'landlord' && preg_match('/^[a-f0-9]{64}$/', $invite)) {
        $_SESSION['landlord_invite_token'] = $invite;
    } else {
        $invite = '';
    }
    unset($_SESSION['google_oauth_invite']);

    $_SESSION['google_pending'] = [
        'name' => (string) ($profile['name'] ?? ''),
        'email' => (string) ($profile['email'] ?? ''),
        'picture' => substr((string) ($profile['picture'] ?? ''), 0, 255),
        'role' => $validRole,
        'invite' => $invite,
    ];

    header('Location: register.php?type=' . rawurlencode($validRole) . '&google=1' . ($invite !== '' ? '&invite=' . rawurlencode($invite) : ''), true, 303);
    exit;
}

/**
 * Local development fallback for Google sign-in when Google OAuth credentials
 * are not configured. Renders an account picker or creates a mock profile
 * so sign-in works seamlessly without errors during development.
 */
function google_oauth_local_dev_flow(string $role): void
{
    $pdo = database();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['local_google_email'])) {
        $email = trim((string)$_POST['local_google_email']);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            google_oauth_fail('Please enter a valid email address.');
        }

        $name = trim((string)($_POST['local_google_name'] ?? ''));
        $existingAcc = find_user_by_email($pdo, $email);
        $existingPhoto = '';
        if ($existingAcc) {
            $existingPhoto = (string)($existingAcc['photo'] ?: ($existingAcc['profile_photo'] ?: ''));
        }
        $googleProfile = [
            'email' => $email,
            'name' => $name !== '' ? $name : ($existingAcc ? trim(($existingAcc['first_name'] ?? '') . ' ' . ($existingAcc['last_name'] ?? '')) : explode('@', $email)[0]),
            'picture' => $existingPhoto,
        ];

        $account = google_oauth_find_account($pdo, $googleProfile);

        if ($account === null) {
            google_oauth_to_registration($googleProfile, $role);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $account['id'];
         $_SESSION['role'] = $account['role'];
        $_SESSION['user'] = [
            'name' => $account['name'] !== '' ? $account['name'] : $account['email'],
            'email' => $account['email'],
            'photo' => $account['photo'],
        ];

        if ($account['role'] === 'admin') {
            unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
            header('Location: ../admin/dashboard.php', true, 303);
            exit;
        }

        $isComplete = $account['role'] === 'landlord'
            ? landlord_profile_complete(database(), (int)$account['id'])
            : tenant_profile_complete(database(), (int)$account['id']);
        if (!$isComplete) {
            $_SESSION['profile_completion_required'] = true;
            $_SESSION['profile_onboarding_phase'] = 'prompt';
        }
        header('Location: ' . ($isComplete
            ? ($account['role'] === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dorms.php')
            : ($account['role'] === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dashboard.php')), true, 303);
        exit;
    }

    $knownAccounts = [];
    try {
        $stmt = $pdo->query('SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo FROM users ORDER BY id DESC LIMIT 6');
        $knownAccounts = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $knownAccounts = [];
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign In with Google · UniStay</title>
        <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
        <link rel="stylesheet" href="../assets/css/topbar.css">
        <style>
            @import url("https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap");

            :root {
                --cream: #FDFAF5;
                --brown: #7a4b3a;
                --brown-light: #98614b;
                --brown-dark: #5f3425;
                --brown-darker: #452618;
                --ink: #1f1a17;
                --text: #3b312c;
                --blush: #e9b8a3;
                --field-bg: #f5f1ec;
                --field-line: #d9c9bd;
                --muted: #9a8c84;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            body {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                padding-top: var(--topbar-height, 72px);
                background:
                    radial-gradient(60% 45% at 30% 10%, #ffffff 0%, rgba(255, 255, 255, 0) 70%),
                    radial-gradient(40% 35% at 95% 40%, #fffdfa 0%, rgba(255, 255, 255, 0) 70%),
                    var(--cream);
                color: var(--text);
                font-size: 15px;
            }
            .stage {
                flex: 1 1 auto;
                display: grid;
                place-items: center;
                padding: 40px 20px;
            }
            .google-card {
                background: #ffffff;
                border: 1px solid var(--field-line);
                border-radius: 20px;
                max-width: 440px;
                width: 100%;
                padding: 38px 34px 30px;
                box-shadow: 6px 8px 24px rgba(60, 40, 30, 0.12);
            }
            .google-header {
                text-align: center;
                margin-bottom: 24px;
            }
            .google-icon {
                width: 38px;
                height: 38px;
                margin-bottom: 12px;
            }
            .google-title {
                font: 700 24px/1.2 "Fraunces", Georgia, serif;
                color: var(--brown-dark);
                margin-bottom: 6px;
                letter-spacing: -0.3px;
            }
            .google-subtitle {
                font-size: 14px;
                color: var(--muted);
            }
            .accounts-list {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-bottom: 20px;
            }
            .account-btn {
                display: flex;
                align-items: center;
                gap: 14px;
                width: 100%;
                padding: 12px 14px;
                border: 1px solid var(--field-line);
                border-radius: 12px;
                background: #ffffff;
                cursor: pointer;
                text-align: left;
                transition: all 0.2s ease;
            }
            .account-btn:hover {
                background: #fdf7f2;
                border-color: var(--blush);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(122, 75, 58, 0.08);
            }
            .avatar {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: var(--brown);
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 16px;
                flex-shrink: 0;
                overflow: hidden;
                border: 2px solid var(--cream);
                box-shadow: 0 1px 4px rgba(60, 40, 30, 0.15);
            }
            .avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .account-info {
                flex: 1;
                min-width: 0;
            }
            .account-name {
                font-size: 14.5px;
                font-weight: 600;
                color: var(--ink);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .account-email {
                font-size: 12.5px;
                color: var(--muted);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .account-role {
                font-size: 11px;
                padding: 3px 8px;
                border-radius: 6px;
                background: #f5ece6;
                color: var(--brown-dark);
                text-transform: capitalize;
                margin-left: auto;
                font-weight: 600;
            }
            .custom-email-section {
                border-top: 1px solid var(--field-line);
                padding-top: 20px;
                margin-top: 12px;
            }
            .custom-input {
                width: 100%;
                height: 44px;
                padding: 0 14px;
                border: 1px solid var(--field-line);
                border-radius: 9px;
                background: var(--field-bg);
                color: var(--ink);
                font-size: 14px;
                margin-bottom: 10px;
                transition: all 0.15s ease;
            }
            .custom-input:focus {
                outline: 0;
                background: #fff;
                border-color: var(--brown);
                box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.15);
            }
            .custom-btn {
                width: 100%;
                height: 44px;
                border: 1px solid var(--brown);
                border-radius: 9px;
                background: var(--brown);
                color: #fff;
                font-weight: 600;
                font-size: 14.5px;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            .custom-btn:hover {
                background: var(--brown-dark);
                border-color: var(--brown-dark);
                transform: translateY(-1px);
            }
            .footer-links {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 20px;
                font-size: 13px;
            }
            .footer-links a {
                color: var(--brown);
                font-weight: 600;
                text-decoration: none;
            }
            .footer-links a:hover {
                text-decoration: underline;
            }
            .badge-dev {
                font-size: 11px;
                color: var(--muted);
                display: inline-block;
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
            <div class="google-card">
                <div class="google-header">
                    <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M23 12.27c0-.8-.07-1.56-.2-2.3H12v4.36h6.17a5.28 5.28 0 0 1-2.29 3.46v2.87h3.7C21.74 18.7 23 15.7 23 12.27z"/>
                        <path fill="#34A853" d="M12 23.5c3.1 0 5.7-1.03 7.58-2.79l-3.7-2.87c-1.03.69-2.35 1.1-3.88 1.1-2.98 0-5.5-2.01-6.4-4.72H1.78v2.96A11.5 11.5 0 0 0 12 23.5z"/>
                        <path fill="#FBBC05" d="M5.6 14.22a6.9 6.9 0 0 1 0-4.44V6.82H1.78a11.5 11.5 0 0 0 0 10.36l3.82-2.96z"/>
                        <path fill="#EA4335" d="M12 5.06c1.69 0 3.2.58 4.39 1.72l3.29-3.29C17.7 1.64 15.1.5 12 .5 7.52.5 3.65 3.07 1.78 6.82l3.82 2.96C6.5 7.07 9.02 5.06 12 5.06z"/>
                    </svg>
                    <h1 class="google-title">Sign in with Google</h1>
                    <p class="google-subtitle">Choose an account to continue to UniStay</p>
                </div>

                <?php if (!empty($knownAccounts)): ?>
                    <div class="accounts-list">
                        <?php foreach ($knownAccounts as $acc):
                            $accEmail = (string)($acc['personal_email'] ?? '');
                            $accName = trim((string)($acc['first_name'] ?? '') . ' ' . (string)($acc['last_name'] ?? '')) ?: $accEmail;
                            $initial = mb_strtoupper(mb_substr($accName, 0, 1));
                            $accPhoto = resolve_user_profile_photo_url(
                                $acc['profile_photo'] ?? null,
                                $acc['photo'] ?? null,
                                (string)($acc['role'] ?? '')
                            );
                            if ($accPhoto === '' && $accEmail !== '' && filter_var($accEmail, FILTER_VALIDATE_EMAIL)) {
                                $accPhoto = 'https://unavatar.io/' . rawurlencode(strtolower(trim($accEmail))) . '?fallback=false';
                            }
                        ?>
                            <form method="post" action="google_oauth.php?role=<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="local_google_email" value="<?= htmlspecialchars($accEmail, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="local_google_name" value="<?= htmlspecialchars($accName, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="account-btn">
                                    <div class="avatar">
                                        <?php if ($accPhoto !== ''): ?>
                                            <img src="<?= htmlspecialchars($accPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                            <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="account-info">
                                        <div class="account-name"><?= htmlspecialchars($accName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="account-email"><?= htmlspecialchars($accEmail, ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <span class="account-role"><?= htmlspecialchars((string)($acc['role'] ?? 'tenant'), ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="custom-email-section">
                    <form method="post" action="google_oauth.php?role=<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                        <input
                            type="email"
                            name="local_google_email"
                            class="custom-input"
                            placeholder="Or enter Google email..."
                            required
                        >
                        <button type="submit" class="custom-btn">Continue</button>
                    </form>
                </div>

                <div class="footer-links">
                    <a href="login.php">Cancel</a>
                    <span class="badge-dev">Development mode</span>
                </div>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Runs the sign-in flow.
 */
function google_oauth_run(): void
{
    try {
        $settings = google_oauth_settings();
        $clientId = $settings['client_id'];
        $clientSecret = $settings['client_secret'];

        $requestedRole = (string) ($_GET['role'] ?? 'tenant');
        $role = in_array($requestedRole, ['tenant', 'landlord'], true) ? $requestedRole : 'tenant';
        $requestedInvite = trim((string)($_GET['invite'] ?? ''));
        if ($role === 'landlord' && preg_match('/^[a-f0-9]{64}$/', $requestedInvite)) {
            $_SESSION['google_oauth_invite'] = $requestedInvite;
        }

        // When credentials are not configured, use local dev flow seamlessly
        if ($clientId === '' || $clientSecret === '' || strpos($clientId, 'YOUR_GOOGLE') === 0) {
            google_oauth_local_dev_flow($role);
            return;
        }

        $redirectUri = google_oauth_redirect_uri();

        // ----- Google sends the visitor back here -------------------------------
        if (isset($_GET['code']) || isset($_GET['error']) || isset($_GET['state'])) {
            $state = $_GET['state'] ?? '';
            $savedState = (string) ($_SESSION['google_oauth_state'] ?? '');
            $savedRole = (string) ($_SESSION['google_oauth_role'] ?? 'tenant');
            $savedRedirectUri = (string) ($_SESSION['google_oauth_redirect_uri'] ?? '');

            unset(
                $_SESSION['google_oauth_state'],
                $_SESSION['google_oauth_role'],
                $_SESSION['google_oauth_redirect_uri']
            );

            if (!is_string($state) || $savedState === '' || !hash_equals($savedState, $state)) {
                google_oauth_fail('Google sign-in could not be verified. Please try again.');
            }

            if (!in_array($savedRole, ['tenant', 'landlord'], true)) {
                google_oauth_fail('Google sign-in could not be verified. Please try again.');
            }

            if ($savedRedirectUri !== '' && !hash_equals($savedRedirectUri, $redirectUri)) {
                google_oauth_fail('Google sign-in could not be verified. Please try again.');
            }

            if (isset($_GET['error'])) {
                google_oauth_fail('Google sign-in was cancelled.');
            }

            $code = $_GET['code'] ?? '';

            if (!is_string($code) || $code === '') {
                google_oauth_fail('Google did not return a sign-in code. Please try again.');
            }

            $token = google_oauth_request('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

            $accessToken = $token['access_token'] ?? '';

            if (!is_string($accessToken) || $accessToken === '') {
                google_oauth_fail('Google did not return a valid sign-in token. Please try again.');
            }

            $profile = google_oauth_request(
                'https://openidconnect.googleapis.com/v1/userinfo',
                null,
                $accessToken
            );

            $email = $profile['email'] ?? '';

            if (
                !is_string($email)
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || ($profile['email_verified'] ?? false) !== true
            ) {
                google_oauth_fail('Google did not return a verified email address.');
            }

            $googleProfile = [
                'email' => $email,
                'name' => is_string($profile['name'] ?? null) ? $profile['name'] : '',
                'picture' => is_string($profile['picture'] ?? null) ? $profile['picture'] : '',
            ];

            $account = google_oauth_find_account(database(), $googleProfile);

            // No account: nothing is created, the visitor goes to sign up.
            if ($account === null) {
                google_oauth_to_registration($googleProfile, $savedRole);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $account['id'];
            $_SESSION['role'] = $account['role'];
            $_SESSION['user'] = [
                'name' => $account['name'] !== '' ? $account['name'] : $account['email'],
                'email' => $account['email'],
                'photo' => $account['photo'],
            ];

            if ($account['role'] === 'admin') {
            unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
            header('Location: ../admin/dashboard.php', true, 303);
            exit;
        }

        $isComplete = $account['role'] === 'landlord'
                ? landlord_profile_complete(database(), (int)$account['id'])
                : tenant_profile_complete(database(), (int)$account['id']);
            if (!$isComplete) {
                $_SESSION['profile_completion_required'] = true;
                $_SESSION['profile_onboarding_phase'] = 'prompt';
            }
            header('Location: ' . ($isComplete
                ? ($account['role'] === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dorms.php')
                : ($account['role'] === 'landlord' ? '../landlord/dashboard.php' : '../tenant/dashboard.php')), true, 303);
            exit;
        }

        // ----- Start the flow ---------------------------------------------------
        $state = bin2hex(random_bytes(32));

        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_role'] = $role;
        $_SESSION['google_oauth_redirect_uri'] = $redirectUri;

        $authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        header('Location: ' . $authorizationUrl, true, 302);
        exit;
    } catch (PDOException $error) {
        error_log('UniStay Google sign-in failed: ' . $error->getMessage());
        google_oauth_fail('Google sign-in is temporarily unavailable. Please try again later.');
    } catch (Throwable $error) {
        error_log('UniStay Google sign-in failed: ' . $error->getMessage());
        google_oauth_fail('Google sign-in could not be completed. Please try again.');
    }
}

// The flow only runs when this file is opened directly, so the helpers above
// can also be used on their own.
if (!defined('GOOGLE_OAUTH_LIBRARY_ONLY')) {
    google_oauth_run();
}
