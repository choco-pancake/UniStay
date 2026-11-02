<?php
require_once __DIR__ . '/../config/session.php';
$u = session_user();
$to = $u === null ? 'login.php' : (($u['role'] ?? '') === 'landlord' ? '../landlord/dashboard.php'
    : (($u['role'] ?? '') === 'tenant' ? '../tenant/dashboard.php' : '../index.php'));
header('Location: ' . $to, true, 303);
exit;