<?php
require_once __DIR__ . '/../config/session.php';
start_app_session();
$_SESSION['auth_notice'] = 'Landlord registration is completed during the initial application. Please log in with your credentials once your application is approved.';
header('Location: login.php', true, 303);
exit;
