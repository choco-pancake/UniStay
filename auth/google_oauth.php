<?php

function google_oauth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function google_oauth_redirect_uri(string $role): string
{
    $environmentKey = $role === 'tenant'
        ? 'GOOGLE_TENANT_REDIRECT_URI'
        : 'GOOGLE_LANDLORD_REDIRECT_URI';
    $configuredUri = getenv($environmentKey);
    if (is_string($configuredUri) && $configuredUri !== '') {
        return $configuredUri;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $expectedPath = '/' . $role . '/dashboard.php';
    if (
        !is_string($host)
        || preg_match('/\A[A-Z0-9](?:[A-Z0-9.-]*[A-Z0-9])?(?::[0-9]{1,5})?\z/iD', $host) !== 1
        || !is_string($scriptName)
        || !str_ends_with($scriptName, $expectedPath)
    ) {
        throw new RuntimeException('Could not determine the Google sign-in callback URL.');
    }

    $scheme = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        ? 'https'
        : 'http';

    return $scheme . '://' . $host . $scriptName;
}

function google_oauth_require_user(string $role): array
{
    if (!in_array($role, ['tenant', 'landlord'], true)) {
        throw new RuntimeException('Choose a valid account type to continue.');
    }

    google_oauth_start_session();
    $user = $_SESSION['user'] ?? null;
    if (is_array($user) && ($_SESSION['role'] ?? null) === $role) {
        return $user;
    }

    $clientId = getenv('GOOGLE_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_CLIENT_SECRET');
    if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
        throw new RuntimeException('Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the PHP environment.');
    }

    $redirectUri = google_oauth_redirect_uri($role);
    if (isset($_GET['code']) || isset($_GET['error']) || isset($_GET['state'])) {
        return google_oauth_complete_sign_in($role, $clientId, $clientSecret, $redirectUri);
    }

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
}

function google_oauth_complete_sign_in(
    string $role,
    string $clientId,
    string $clientSecret,
    string $redirectUri
): array {
    $state = $_GET['state'] ?? null;
    $savedState = $_SESSION['google_oauth_state'] ?? null;
    $savedRole = $_SESSION['google_oauth_role'] ?? null;
    $savedRedirectUri = $_SESSION['google_oauth_redirect_uri'] ?? null;
    unset(
        $_SESSION['google_oauth_state'],
        $_SESSION['google_oauth_role'],
        $_SESSION['google_oauth_redirect_uri']
    );

    if (
        !is_string($state)
        || !is_string($savedState)
        || !hash_equals($savedState, $state)
        || $savedRole !== $role
        || !is_string($savedRedirectUri)
        || !hash_equals($redirectUri, $savedRedirectUri)
    ) {
        throw new RuntimeException('Google sign-in verification failed. Please try again.');
    }

    if (isset($_GET['error'])) {
        throw new RuntimeException('Google sign-in was canceled or could not be completed.');
    }

    $code = $_GET['code'] ?? null;
    if (!is_string($code) || $code === '') {
        throw new RuntimeException('Google did not return a sign-in code.');
    }

    $token = google_oauth_request_json('https://oauth2.googleapis.com/token', [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
    ]);
    if (!isset($token['access_token']) || !is_string($token['access_token'])) {
        throw new RuntimeException('Google did not return a valid sign-in token.');
    }

    $profile = google_oauth_request_json(
        'https://openidconnect.googleapis.com/v1/userinfo',
        null,
        $token['access_token']
    );
    if (
        !isset($profile['email'], $profile['email_verified'])
        || !is_string($profile['email'])
        || filter_var($profile['email'], FILTER_VALIDATE_EMAIL) === false
        || $profile['email_verified'] !== true
    ) {
        throw new RuntimeException('Google did not return a verified email address.');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'name' => is_string($profile['name'] ?? null) && $profile['name'] !== ''
            ? $profile['name']
            : $profile['email'],
        'email' => $profile['email'],
        'photo' => is_string($profile['picture'] ?? null) ? $profile['picture'] : '',
    ];
    $_SESSION['role'] = $role;

    header('Location: ' . $redirectUri, true, 303);
    exit;
}

function google_oauth_request_json(string $url, ?array $postFields, ?string $accessToken = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Google sign-in.');
    }

    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not start a Google sign-in request.');
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
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postFields, '', '&', PHP_QUERY_RFC3986);
    }

    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        error_log('Google OAuth request failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $status));
        throw new RuntimeException('Google sign-in could not be verified. Please try again.');
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Google returned an invalid sign-in response.');
    }

    return $data;
}
