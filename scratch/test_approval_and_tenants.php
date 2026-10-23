<?php
require_once __DIR__ . '/../config/database.php';
$pdo = database();

echo "===========================================\n";
echo "LANDLORD APPROVAL FILTERS & TENANTS TEST\n";
echo "===========================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertTest($condition, $message) {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message\n";
    }
}

// 1. Database schema: approval_status column exists in properties
$cols = $pdo->query("SHOW COLUMNS FROM properties LIKE 'approval_status'")->fetchAll();
assertTest(!empty($cols), "approval_status column exists in properties table");

// 2. Query properties with approval_status and tenant bookings directly
$props = $pdo->query("SELECT id, name, approval_status FROM properties")->fetchAll(PDO::FETCH_ASSOC);
assertTest(!empty($props), "Properties retrieved from database");

$statuses = array_unique(array_column($props, 'approval_status'));
assertTest(in_array('approved', $statuses), "At least one property has 'approved' status");
assertTest(in_array('pending', $statuses), "At least one property has 'pending' status");
assertTest(in_array('rejected', $statuses), "At least one property has 'rejected' status");

// 3. Verify tenant bookings associated with rooms
$roomBookings = $pdo->query("
    SELECT b.id, b.room_id, b.property_id, u.first_name, u.last_name, u.school 
    FROM bookings b 
    JOIN users u ON u.id = b.user_id 
    WHERE b.room_id IS NOT NULL AND b.status IN ('active', 'upcoming')
")->fetchAll(PDO::FETCH_ASSOC);
assertTest(!empty($roomBookings), "Room bookings exist with valid tenant details");
if (!empty($roomBookings)) {
    assertTest(!empty($roomBookings[0]['first_name']), "Tenant first_name is populated: " . $roomBookings[0]['first_name']);
    assertTest(!empty($roomBookings[0]['school']), "Tenant school is populated: " . $roomBookings[0]['school']);
}

// 4. Check HTML markup in landlord/properties.php
$html = file_get_contents(__DIR__ . '/../landlord/properties.php');
assertTest(strpos($html, 'prop-status-filter-bar') !== false, "Status filter bar markup exists in properties.php");
assertTest(strpos($html, 'badge-all') !== false, "badge-all element exists");
assertTest(strpos($html, 'badge-approved') !== false, "badge-approved element exists");
assertTest(strpos($html, 'badge-pending') !== false, "badge-pending element exists");
assertTest(strpos($html, 'badge-rejected') !== false, "badge-rejected element exists");

// 5. Check JS logic in assets/js/landlord-properties.js
$js = file_get_contents(__DIR__ . '/../assets/js/landlord-properties.js');
assertTest(strpos($js, 'currentStatusFilter') !== false, "currentStatusFilter variable exists in JS");
assertTest(strpos($js, 'updateStatusTabCounts') !== false, "updateStatusTabCounts function exists in JS");
assertTest(strpos($js, 'prop-card-status-badge') !== false, "prop-card-status-badge rendering exists in JS");
assertTest(strpos($js, 'dorm-card-occupancy-row') !== false, "dorm-card-occupancy-row rendering exists in JS");
assertTest(strpos($js, 'modal-room-tenants-wrap') !== false, "modal-room-tenants-wrap rendering exists in JS");
assertTest(strpos($js, 'pin-context-menu') !== false, "pin-context-menu popup exists in JS");
assertTest(strpos($js, 'Remove this pin') !== false, "Right-click option 'Remove this pin' exists in JS");
assertTest(strpos($js, 'Keep Pin (Cancel)') !== false, "Right-click option 'Keep Pin (Cancel)' exists in JS");

// 6. Check CSS in assets/css/landlord-properties.css
$css = file_get_contents(__DIR__ . '/../assets/css/landlord-properties.css');
assertTest(strpos($css, '.prop-status-filter-bar') !== false, ".prop-status-filter-bar styles exist in CSS");
assertTest(strpos($css, '.prop-card-status-badge.is-approved') !== false, "Approved badge styles exist in CSS");
assertTest(strpos($css, '.dorm-card-avatar-stack') !== false, "Avatar stack styles exist in CSS");
assertTest(strpos($css, '.modal-room-tenants-wrap') !== false, "Modal room tenants styles exist in CSS");

echo "\n-------------------------------------------\n";
echo "SUMMARY: $passCount / $totalTests tests passed.\n";
echo "-------------------------------------------\n";
