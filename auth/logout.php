<?php
require_once __DIR__ . '/../config/session.php';

start_app_session();
$_SESSION = [];

// Destroy session cookie if present
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Redirect destination (defaults to login page)
$target = $_GET['redirect'] ?? '../auth/login.php';
// Whitelist safe local redirects
if (!preg_match('/^(?:\.\.\/|[a-zA-Z0-9_\-\/]+\.php)/', $target)) {
    $target = '../auth/login.php';
}

header('Location: ' . $target, true, 303);
exit;
