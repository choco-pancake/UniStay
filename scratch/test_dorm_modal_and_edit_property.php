<?php
/**
 * Automated Test Suite: Dorm Modal Layout, Map Vibrancy & Zoom, and Edit Property Feature
 */

require_once __DIR__ . '/../config/database.php';
$pdo = database();

$propPhp = file_get_contents(__DIR__ . '/../landlord/properties.php');
$propJs = file_get_contents(__DIR__ . '/../assets/js/landlord-properties.js');
$propCss = file_get_contents(__DIR__ . '/../assets/css/landlord-properties.css');
$apiPhp = file_get_contents(__DIR__ . '/../api/properties.php');
$storeJs = file_get_contents(__DIR__ . '/../assets/js/store.js');

$passed = 0;
$failed = 0;

function assertTest($description, $condition, $failMsg = '') {
  global $passed, $failed;
  if ($condition) {
    echo "  [PASS] $description\n";
    $passed++;
  } else {
    echo "  [FAIL] $description: $failMsg\n";
    $failed++;
  }
}

echo "=== Dorm Modal Layout, Map Vibrancy & Zoom, and Edit Property Tests ===\n\n";

// 1. UNISTAY VERIFIED Removal
echo "-- 1. UNISTAY VERIFIED Removal --\n";
assertTest(
  "UNISTAY VERIFIED is NOT rendered on dorm gallery cards in JS",
  strpos($propJs, 'UNISTAY VERIFIED') === false,
  "Found 'UNISTAY VERIFIED' in landlord-properties.js"
);

// 2. Dorm Inspection Modal Title Row & Removal of Pin/School Icons
echo "\n-- 2. Clean Title Meta Without Pin/School Icons --\n";
assertTest(
  "openInspectionModal does NOT have SVG pin/school icons in dorm-modal-title-row",
  !preg_match('/dorm-modal-title-row[^\<]*\<h2[^\>]*\>[^\<]*\<\/h2\>[^\<]*\<span class="dorm-modal-title-meta"\>[^\<]*\<span class="dorm-modal-side-address"[^\>]*\>\s*\<svg/s', $propJs),
  "SVG icons should be removed from title meta"
);
assertTest(
  "Title row formats address and university as '[Address]; near [University]'",
  strpos($propJs, '${esc(p.address)}</span>;') !== false && strpos($propJs, 'near ${esc(p.university)}') !== false,
  "Title row must use format '[Address]; near [University]'"
);
assertTest(
  "No pipe divider lines in dorm-modal-title-row in openInspectionModal",
  strpos($propJs, 'dorm-modal-title-divider') === false,
  "dorm-modal-title-divider should not be rendered in openInspectionModal"
);

// 3. Mid-Row Layout (Photo Left, Description Right)
echo "\n-- 3. Mid-Row Layout (Photo Left, Description Right) --\n";
assertTest(
  "openInspectionModal constructs .dorm-modal-mid-row with photo on left and desc on right",
  strpos($propJs, 'class="dorm-modal-mid-row"') !== false &&
  strpos($propJs, 'class="dorm-modal-mid-photo"') !== false &&
  strpos($propJs, 'class="dorm-modal-mid-desc"') !== false,
  "dorm-modal-mid-row, dorm-modal-mid-photo, and dorm-modal-mid-desc must be present"
);
assertTest(
  "CSS styles .dorm-modal-mid-row as 2-column grid (210px 1fr)",
  preg_match('/\.dorm-modal-mid-row\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*210px\s+1fr;/s', $propCss) === 1,
  ".dorm-modal-mid-row must be styled as grid with 210px 1fr"
);
assertTest(
  "Leaflet map is placed below mid-row with generous height (270px)",
  preg_match('/\.dorm-modal-map\s*\{[^}]*height:\s*270px;/s', $propCss) === 1,
  ".dorm-modal-map must have height: 270px"
);

// 4. Vibrant Maps & 1km MinZoom Lock
echo "\n-- 4. Vibrant Maps & 1km MinZoom Lock --\n";
assertTest(
  "#pickMap has vibrant tile filter in CSS",
  preg_match('/#pickMap[^{]*\.leaflet-tile-pane\s*\{[^}]*filter:\s*saturate\(1\.4\)\s+contrast\(1\.08\)\s+brightness\(1\.02\);/s', $propCss) === 1,
  "#pickMap must have vibrant tile filter"
);
assertTest(
  "pickMap is initialized with scrollWheelZoom: true and locked minZoom",
  strpos($propJs, "const map = L.map('pickMap'") !== false && strpos($propJs, "map.setMinZoom(HOME_VIEW.zoom);") !== false,
  "pickMap must have minZoom set to HOME_VIEW.zoom"
);
assertTest(
  "inspectionMap has minZoom: HOME_VIEW.zoom and maxBounds: BOUNDS",
  strpos($propJs, "minZoom: HOME_VIEW.zoom") !== false && strpos($propJs, "maxBounds: BOUNDS") !== false,
  "inspectionMap must enforce minZoom: HOME_VIEW.zoom"
);

// 5. Edit Property Functionality
echo "\n-- 5. Edit Property Functionality --\n";
assertTest(
  "Dorm cards render .dorm-card-edit-btn",
  strpos($propJs, 'class="dorm-card-edit-btn"') !== false,
  "Dorm card template must include dorm-card-edit-btn"
);
assertTest(
  "Dorm inspection modal renders .dorm-inspect-edit-btn (#dormModalEditBtn)",
  strpos($propJs, 'id="dormModalEditBtn"') !== false && strpos($propJs, 'class="dorm-inspect-edit-btn"') !== false,
  "dormModalEditBtn must be present in inspection modal"
);
assertTest(
  "openEditModal function is defined in landlord-properties.js",
  strpos($propJs, 'function openEditModal(p)') !== false,
  "openEditModal function must be defined"
);
assertTest(
  "api/properties.php supports updating property when property_id or id is in POST",
  strpos($apiPhp, '$editId = (int)($_POST[\'id\'] ?? $_POST[\'property_id\'] ?? 0);') !== false &&
  strpos($apiPhp, 'UPDATE properties SET') !== false,
  "api/properties.php must handle updates via POST"
);
assertTest(
  "properties.php includes editPropId hidden input and modalMainHeading ID",
  strpos($propPhp, 'id="editPropId"') !== false && strpos($propPhp, 'id="modalMainHeading"') !== false,
  "properties.php must have editPropId and modalMainHeading"
);

// 6. Cache Busters Bumped
echo "\n-- 6. Cache Busters Bumped --\n";
assertTest(
  "properties.php links landlord-properties.css?v=19",
  strpos($propPhp, 'landlord-properties.css?v=19') !== false,
  "CSS link must be v=19"
);
assertTest(
  "properties.php links landlord-properties.js?v=20",
  strpos($propPhp, 'landlord-properties.js?v=20') !== false,
  "JS script must be v=20"
);

echo "\n-------------------------------------------------\n";
echo "Total Tests: " . ($passed + $failed) . " | Passed: $passed | Failed: $failed\n";

if ($failed > 0) {
  exit(1);
}
