<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$_SESSION['user_id'] = 999999;
$_SESSION['role'] = 'landlord';
$_SESSION['user'] = [
    'name' => 'New Landlord',
    'email' => 'new.landlord@example.com',
    'photo' => ''
];

ob_start();
include __DIR__ . '/../landlord/dashboard.php';
$html = ob_get_clean();

$checks = [
    'Total Properties is 0' => strpos($html, '<h3 class="stat-value">0</h3>') !== false,
    'Occupancy Rate is 0%' => strpos($html, '<h3 class="stat-value">0%</h3>') !== false,
    'Empty properties message present' => strpos($html, 'No properties listed yet') !== false,
    'Empty bookings message present' => strpos($html, 'No student bookings yet') !== false,
    'Add Property CTA in empty state' => strpos($html, 'Add Property</a>') !== false,
];

$allPassed = true;
echo "=== LANDLORD DASHBOARD EMPTY STATE TESTS ===\n";
foreach ($checks as $label => $pass) {
    if ($pass) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\nAll 5 empty state checks passed successfully!\n";
    exit(0);
} else {
    echo "\nSome checks failed.\n";
    exit(1);
}
