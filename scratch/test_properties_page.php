<?php
// Test landlord properties page rendering with an active landlord session
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$pdo = database();
$stmt = $pdo->query("SELECT id, first_name, last_name, personal_email, role FROM users WHERE role = 'landlord' LIMIT 1");
$landlord = $stmt->fetch();

if (!$landlord) {
    echo "No landlord found in database.\n";
    exit(1);
}

$landlordName = trim(($landlord['first_name'] ?? '') . ' ' . ($landlord['last_name'] ?? ''));
echo "Found landlord: ID {$landlord['id']} - {$landlordName} ({$landlord['personal_email']})\n";

// Start session pretending to be this landlord
start_app_session();
$_SESSION['user_id'] = (int)$landlord['id'];
$_SESSION['role'] = 'landlord';
$_SESSION['user'] = [
    'id' => (int)$landlord['id'],
    'name' => $landlordName,
    'email' => $landlord['personal_email'],
    'role' => 'landlord'
];

ob_start();
include __DIR__ . '/../landlord/properties.php';
$html = ob_get_clean();

$checks = [
    'UniStay Landlord Portal' => strpos($html, 'UniStay') !== false,
    'Sidebar with Properties & Rooms' => strpos($html, 'href="properties.php"') !== false && strpos($html, 'href="rooms.php"') !== false,
    'Topbar Avatar' => strpos($html, 'class="avatar"') !== false,
    'Properties Toolbar' => strpos($html, 'class="toolbar"') !== false,
    'Filter buttons' => strpos($html, 'data-filter="all"') !== false && strpos($html, 'data-filter="Universidad de Dagupan"') !== false,
    'Search input' => strpos($html, 'id="propSearch"') !== false,
    'Add Property button' => strpos($html, 'id="openAddModalBtn"') !== false,
    'Gallery container' => strpos($html, 'id="gallery"') !== false,
    'Modal Overlay' => strpos($html, 'id="modalOverlay"') !== false,
    '3-Step Progress Indicator' => strpos($html, 'class="property-progress"') !== false,
    'Step 1 Basic Info' => strpos($html, 'data-step="1"') !== false,
    'Step 2 Location & Map' => strpos($html, 'data-step="2"') !== false,
    'Step 3 Photos & Rooms' => strpos($html, 'data-step="3"') !== false,
    'Leaflet pickMap container' => strpos($html, 'id="pickMap"') !== false,
    'Detail Modal propModal' => strpos($html, 'id="propModal"') !== false,
    'Store JS loaded' => strpos($html, 'src="../assets/js/store.js"') !== false,
    'Landlord Properties JS loaded' => strpos($html, 'src="../assets/js/landlord-properties.js') !== false,
];

$allPassed = true;
foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "PASS: $name\n";
    } else {
        echo "FAIL: $name\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\nAll landlord properties UI checks passed successfully!\n";
} else {
    echo "\nSome checks failed.\n";
    exit(1);
}
