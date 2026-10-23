<?php
// Test landlord properties v2 redesign layout and elements
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
    'Uniform Dorms stylesheet dorms.css included' => strpos($html, 'dorms.css') !== false,
    'Uniform toolbar .um-toolbar' => strpos($html, 'class="um-toolbar"') !== false,
    'Search input #prop-search' => strpos($html, 'id="prop-search"') !== false,
    'University filter .um-select' => strpos($html, 'id="uni-select"') !== false,
    'Add Property button' => strpos($html, 'id="openAddModalBtn"') !== false,
    'Dorms grid #dorms-grid' => strpos($html, 'id="dorms-grid"') !== false,
    'Expanded modal #modalOverlay' => strpos($html, 'id="modalOverlay"') !== false,
    'Page 1 2-column grid #page1Grid' => strpos($html, 'id="page1Grid"') !== false,
    'Left: Dorm name input' => strpos($html, 'id="propName"') !== false,
    'Left: 4 Dagupan University buttons' => strpos($html, 'data-uni="Universidad de Dagupan"') !== false && strpos($html, 'data-uni="Lyceum Northwestern University"') !== false,
    'Dropdown removed from university selection' => strpos($html, '<select id="university"') === false,
    'Left: Description textarea' => strpos($html, 'id="propDesc"') !== false,
    'Left: 1-3 Photos upload dropzone' => strpos($html, 'id="photoZone"') !== false && strpos($html, '1–3 photos') !== false,
    'Right: Leaflet pickMap' => strpos($html, 'id="pickMap"') !== false,
    'Right: Location mode switcher' => strpos($html, 'class="loc-mode-btn') !== false,
    'Right: Location input' => strpos($html, 'id="addressInput"') !== false,
    'Right: Live suggestions dropdown #locSuggestions' => strpos($html, 'id="locSuggestions"') !== false,
    'Page 1: Continue button #btnPage1Continue' => strpos($html, 'id="btnPage1Continue"') !== false,
    'Page 2: Rooms grid #page2Grid' => strpos($html, 'id="page2Grid"') !== false,
    'Page 2: Back button #btnPage2Back' => strpos($html, 'id="btnPage2Back"') !== false,
    'Page 2: Save property button #savePropBtn' => strpos($html, 'id="savePropBtn"') !== false,
    'Enlarged dorm inspection modal #dorm-modal' => strpos($html, 'id="dorm-modal"') !== false,
];

$allPassed = true;
foreach ($checks as $name => $passed) {
    echo ($passed ? "PASS" : "FAIL") . ": $name\n";
    if (!$passed) $allPassed = false;
}

if ($allPassed) {
    echo "\nAll 22 landlord properties redesign checks passed successfully!\n";
    exit(0);
} else {
    echo "\nSome checks failed.\n";
    exit(1);
}
