<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$_SESSION['user_id'] = 10;
$_SESSION['role'] = 'landlord';
$_SESSION['user'] = [
    'name' => 'Juan Dela Cruz',
    'email' => 'landlord.juan@example.com',
    'photo' => ''
];

ob_start();
include __DIR__ . '/../landlord/dashboard.php';
$html = ob_get_clean();

$checks = [
    'Contains Landlord Name' => strpos($html, 'Juan Dela Cruz') !== false,
    'Card 1: TOTAL PROPERTIES' => strpos($html, 'TOTAL PROPERTIES') !== false && strpos($html, '<h3 class="card-value">4</h3>') !== false,
    'Card 2: OCCUPANCY RATE' => strpos($html, 'OCCUPANCY RATE') !== false && preg_match('/<h3 class="card-value">\d+%<\/h3>/', $html) === 1,
    'Card 3: ACCOUNT STATUS' => strpos($html, 'ACCOUNT STATUS') !== false && (strpos($html, 'Verified') !== false || strpos($html, 'Action Required') !== false),
    'Sidebar has Dashboard' => strpos($html, '<span class="nav-label">Dashboard</span>') !== false,
    'Sidebar has Properties' => strpos($html, '<span class="nav-label">Properties</span>') !== false,
    'Sidebar has Tenants' => strpos($html, '<span class="nav-label">Tenants</span>') !== false,
    'Sidebar has Settings' => strpos($html, '<span class="nav-label">Settings</span>') !== false,
    'Recent Bookings has Alex Mendoza' => strpos($html, 'Alex Mendoza') !== false,
    'Recent Bookings has Sofia Lim' => strpos($html, 'Sofia Lim') !== false,
    'Active status badge present' => strpos($html, 'booking-status-tag active') !== false,
    'Pending status badge present' => strpos($html, 'booking-status-tag pending') !== false,
    'Linked to common.css' => strpos($html, 'assets/css/common.css') !== false,
    'Linked to dashboardstyle.css' => strpos($html, 'assets/css/dashboardstyle.css') !== false,
];

ob_start();
include __DIR__ . '/../landlord/tenants.php';
$tenantsHtml = ob_get_clean();

$checks['Tenants page renders'] = strlen($tenantsHtml) > 500 && strpos($tenantsHtml, 'Tenants & Bookings') !== false;

$allPassed = true;
echo "=== LANDLORD DASHBOARD & TENANTS RENDER TESTS ===\n";
foreach ($checks as $label => $pass) {
    if ($pass) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\nAll checks passed successfully!\n";
    exit(0);
} else {
    echo "\nSome checks failed.\n";
    exit(1);
}

