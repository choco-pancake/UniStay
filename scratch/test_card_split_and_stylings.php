<?php
/**
 * Automated Test Suite: Landlord Card Split & Stylings Refinements
 */

$passed = 0;
$failed = 0;

function assertCheck($desc, $cond, $details = '') {
  global $passed, $failed;
  if ($cond) {
    echo "  [PASS] $desc\n";
    $passed++;
  } else {
    echo "  [FAIL] $desc: $details\n";
    $failed++;
  }
}

echo "=== Landlord Card Split & Stylings Verification ===\n\n";

require_once __DIR__ . '/../config/database.php';
$pdo = database();

// 1. Property 105 ("dorm 1") deleted
$p105 = $pdo->query('SELECT id FROM properties WHERE id = 105')->fetch();
assertCheck(
  "Property 105 (dorm 1) removed from database",
  $p105 === false,
  "Property 105 should not exist in properties table"
);

$r33 = $pdo->query('SELECT id FROM rooms WHERE property_id = 105')->fetch();
assertCheck(
  "Rooms for property 105 removed from database",
  $r33 === false,
  "Rooms for property 105 should not exist in rooms table"
);

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath  = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';

$propPhp = file_get_contents($propPhpPath);
$propJs  = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);

// 2. Popover Elevation Only (Topbar & Profile reverted)
assertCheck(
  "Topbar does NOT have z-index: 2000 !important in CSS",
  strpos($propCss, '.landlord-properties-page .topbar {') === false &&
  strpos($propCss, 'z-index: 2000 !important;') === false,
  "topbar z-index 2000 override must be removed"
);

assertCheck(
  "Profile does NOT have z-index: 2001 !important in CSS",
  strpos($propCss, 'z-index: 2001 !important;') === false,
  "profile z-index 2001 override must be removed"
);

assertCheck(
  "Notification & profile popover panels retain z-index: 99999 !important",
  preg_match('/#notification-popover[^{]*\{[^}]*z-index:\s*99999\s*!important/s', $propCss) !== false,
  "popover panels must retain 99999 !important"
);

// 3. Enlarged Modal Split (Login Brown Theme) & Restored Gallery Card
assertCheck(
  "Enlarged modal markup includes .dorm-inspect-col-left and .dorm-inspect-col-right",
  strpos($propJs, 'class="dorm-inspect-col-left"') !== false &&
  strpos($propJs, 'class="dorm-inspect-col-right"') !== false,
  "Enlarged modal must be split into left and right columns"
);

assertCheck(
  "Left column contains dorm title with location and university to the right in meta top",
  strpos($propJs, 'class="dorm-modal-meta-top"') !== false &&
  strpos($propJs, 'class="dorm-modal-main-title"') !== false &&
  (strpos($propJs, 'class="dorm-modal-side-address"') !== false || strpos($propJs, 'class="dorm-modal-meta-location"') !== false),
  "Meta top block must be present inside left column"
);

assertCheck(
  "CSS styles .dorm-inspect-col-left with login card radial-gradient brown background",
  strpos($propCss, '.dorm-inspect-col-left') !== false &&
  strpos($propCss, 'radial-gradient(55% 55% at 78% 8%') !== false &&
  strpos($propCss, 'var(--brown)') !== false,
  "dorm-inspect-col-left must use login panel radial gradient and var(--brown)"
);

assertCheck(
  "CSS styles .dorm-modal-body as 2-column grid in landlord-properties.css",
  preg_match('/\.landlord-properties-page\s+\.dorm-modal-body\s*\{[^}]*grid-template-columns:\s*1fr\s+1fr;/s', $propCss) === 1,
  "dorm-modal-body must have grid-template-columns: 1fr 1fr"
);

// 4. Map Relocation Below Description in Inspection Modal
assertCheck(
  "inspectionModalMap is located inside dorm-inspect-col-left below description",
  strpos($propJs, 'dorm-modal-map-wrap') !== false && strpos($propJs, 'id="inspectionModalMap"') !== false,
  "inspectionModalMap must be in dorm-inspect-col-left below description"
);

assertCheck(
  "dorm-inspect-col-right does not contain inspectionModalMap",
  !preg_match('/class="dorm-inspect-col-right".*id="inspectionModalMap"/s', $propJs),
  "dorm-inspect-col-right should not contain inspectionModalMap"
);

// 5. Dropdown Selection Fix
assertCheck(
  "University dropdown click listener updates aria-selected across all options",
  strpos($propJs, "item.setAttribute('aria-selected', isTarget ? 'true' : 'false');") !== false,
  "aria-selected must be updated across all options on click"
);

assertCheck(
  "University dropdown click listener toggles .is-selected class",
  strpos($propJs, "item.classList.toggle('is-selected', isTarget);") !== false,
  "is-selected class must be toggled on active option"
);

// 6. Cache busters
assertCheck(
  "properties.php has landlord-properties.css?v=16 or newer",
  preg_match('/landlord-properties\.css\?v=(?:1[6-9]|[2-9]\d)/', $propPhp) === 1,
  "CSS version must be v=16 or newer"
);

assertCheck(
  "properties.php has landlord-properties.js?v=17 or newer",
  preg_match('/landlord-properties\.js\?v=(?:1[7-9]|[2-9]\d)/', $propPhp) === 1,
  "JS version must be v=17 or newer"
);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) exit(1);
