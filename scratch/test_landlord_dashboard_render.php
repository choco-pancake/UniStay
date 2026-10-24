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
    'Total Properties is 4' => strpos($html, '<h3 class="stat-value">4</h3>') !== false,
    'Total Rooms is 12' => strpos($html, '<h3 class="stat-value">12</h3>') !== false,
    'Occupancy Rate rendered' => preg_match('/<h3 class="stat-value">\d+%<\/h3>/', $html) === 1,
    'Contains St. Albert property' => strpos($html, 'St. Albert Student Suites') !== false,
    'Contains Pangasinan Scholars property' => strpos($html, 'Pangasinan Scholars Haven') !== false,
    'Contains Alex Mendoza booking' => strpos($html, 'Alex Mendoza') !== false,
    'Contains Sofia Lim booking' => strpos($html, 'Sofia Lim') !== false,
    'Active status badge present' => strpos($html, 'badge-status active') !== false,
    'Pending status badge present' => strpos($html, 'badge-status pending') !== false,
    'Linked to landlord-dashboard.css' => strpos($html, 'href="../assets/css/landlord-dashboard.css"') !== false,
    'Linked to landlord-dashboard.js' => strpos($html, 'src="../assets/js/landlord-dashboard.js"') !== false,
];

$allPassed = true;
echo "=== LANDLORD DASHBOARD DYNAMIC RENDER TESTS ===\n";
foreach ($checks as $label => $pass) {
    if ($pass) {
        echo "[PASS] $label\n";
    } else {
        echo "[FAIL] $label\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\nAll 12 checks passed successfully!\n";
    exit(0);
} else {
    echo "\nSome checks failed.\n";
    exit(1);
}
