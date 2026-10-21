<?php

/**
 * Google sign-up / sign-in.
 *
 * Start the flow:   auth/google_oauth.php?role=tenant
 *                   auth/google_oauth.php?role=landlord
 * Google calls back: auth/google_oauth.php?code=...&state=...
 *
 * The callback URL is the one registered as an authorised redirect URI of the
 * OAuth client, see config/oauth.example.php.
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
 * Splits the name of the Google profile over the first, middle and last name
 * column of the users table.
 */
function google_oauth_split_name(string $fullName): array
{
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $parts = array_values(array_filter($parts, static function ($part) {
        return $part !== '';
    }));

    if (count($parts) === 0) {
        return ['first_name' => null, 'middle_name' => null, 'last_name' => null];
    }

    if (count($parts) === 1) {
        return ['first_name' => $parts[0], 'middle_name' => null, 'last_name' => null];
    }

    $lastName = array_pop($parts);

    return [
        'first_name' => array_shift($parts),
        'middle_name' => count($parts) > 0 ? implode(' ', $parts) : null,
        'last_name' => $lastName,
    ];
}

/**
 * Signs the visitor in, creating the account when the email is still unknown.
 */
function google_oauth_account(array $profile, string $role): array
{
    $pdo = database();
    $email = $profile['email'];

    $statement = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, personal_email, role, photo FROM users WHERE personal_email = ? LIMIT 1'
    );
    $statement->execute([$email]);
    $account = $statement->fetch();

    if ($account !== false) {
        $photo = (string) ($account['photo'] ?? '');
        $googlePhoto = substr((string) ($profile['picture'] ?? ''), 0, 255);

        // Keep the picture of the Google account in sync, also for accounts that
        // were created with an email and password first.
        if ($googlePhoto !== '' && $googlePhoto !== $photo) {
            $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?')
                ->execute([$googlePhoto, (int) $account['id']]);

            $photo = $googlePhoto;
        }

        return [
            'id' => (int) $account['id'],
            'role' => (string) $account['role'],
            'name' => trim((string) $account['first_name'] . ' ' . (string) $account['middle_name'] . ' ' . (string) $account['last_name']),
            'email' => (string) $account['personal_email'],
            'photo' => $photo,
        ];
    }

    $names = google_oauth_split_name((string) ($profile['name'] ?? ''));
    $photo = (string) ($profile['picture'] ?? '');
    $photo = substr($photo, 0, 255);

    $insert = $pdo->prepare(
        'INSERT INTO users (first_name, middle_name, last_name, personal_email, password_hash, role, photo)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $names['first_name'],
        $names['middle_name'],
        $names['last_name'],
        $email,
        password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        $role,
        $photo !== '' ? $photo : null,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'role' => $role,
        'name' => trim((string) $profile['name']),
        'email' => $email,
        'photo' => $photo,
    ];
}

try {
    $settings = google_oauth_settings();
    $clientId = $settings['client_id'];
    $clientSecret = $settings['client_secret'];

    if ($clientId === '' || $clientSecret === '' || strpos($clientId, 'YOUR_GOOGLE') === 0) {
        google_oauth_fail(
            'Google sign-in is not set up yet. Add your Google OAuth credentials to config/oauth.local.php.'
        );
    }

    $redirectUri = google_oauth_redirect_uri();
    $role = 'tenant';

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

        $account = google_oauth_account([
            'email' => $email,
            'name' => is_string($profile['name'] ?? null) ? $profile['name'] : $email,
            'picture' => is_string($profile['picture'] ?? null) ? $profile['picture'] : '',
        ], $savedRole);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $account['id'];
        $_SESSION['role'] = $account['role'];
        $_SESSION['user'] = [
            'name' => $account['name'] !== '' ? $account['name'] : $account['email'],
            'email' => $account['email'],
            'photo' => $account['photo'],
        ];

        if ($account['role'] === 'landlord') {
            header('Location: ../landlord/dashboard.php', true, 303);
            exit;
        }

        $complete = tenant_profile_complete(database(), $account['id']);

        header('Location: ../tenant/' . ($complete ? 'dashboard.php' : 'complete-profile.php'), true, 303);
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
        'hd' => '*',
    ], '', '&', PHP_QUERY_RFC3986);

    header('Location: ' . $authorizationUrl, true, 302);
    exit;
} catch (PDOException $error) {
    error_log('UniStay Google sign-up failed: ' . $error->getMessage());
    google_oauth_fail('Google sign-up is temporarily unavailable. Please try again later.');
} catch (Throwable $error) {
    error_log('UniStay Google sign-in failed: ' . $error->getMessage());
    google_oauth_fail('Google sign-in could not be completed. Please try again.');
}
