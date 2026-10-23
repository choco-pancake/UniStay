<?php
/**
 * Automated Test Suite: Enlarged Dorm Inspection Modal Redesign & Gallery Restoration
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

echo "=== Enlarged Dorm Inspection Modal Redesign & Gallery Cards Verification ===\n\n";

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath  = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';

$propPhp = file_get_contents($propPhpPath);
$propJs  = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);

// 1. PHP Markup & Cache Busting
assertCheck(
  "properties.php references landlord-properties.css?v=17 or newer",
  preg_match('/landlord-properties\.css\?v=(?:1[7-9]|[2-9]\d)/', $propPhp) === 1,
  "CSS link should have cache-buster v=17 or newer"
);

assertCheck(
  "properties.php references landlord-properties.js?v=18 or newer",
  preg_match('/landlord-properties\.js\?v=(?:1[8-9]|[2-9]\d)/', $propPhp) === 1,
  "JS script should have cache-buster v=18 or newer"
);

assertCheck(
  "dorm-modal does NOT have redundant separate dorm-modal-header bar",
  strpos($propPhp, '<div class="dorm-modal-header">') === false,
  "dorm-modal-header bar should be removed from dialog markup"
);

assertCheck(
  "dorm-modal contains floating close button and content body",
  strpos($propPhp, 'id="dorm-modal-close-btn"') !== false &&
  strpos($propPhp, 'id="dorm-modal-content"') !== false,
  "Dialog must contain close button and dorm-modal-content container"
);

// 2. JavaScript Layout in openInspectionModal
assertCheck(
  "openInspectionModal constructs left column (dorm-inspect-col-left) and right column (dorm-inspect-col-right)",
  strpos($propJs, 'class="dorm-inspect-col-left"') !== false &&
  strpos($propJs, 'class="dorm-inspect-col-right"') !== false,
  "openInspectionModal must render two columns"
);

assertCheck(
  "openInspectionModal places title with location and university to the right in meta top",
  strpos($propJs, 'class="dorm-modal-meta-top"') !== false &&
  strpos($propJs, 'class="dorm-modal-main-title"') !== false &&
  (strpos($propJs, 'class="dorm-modal-side-address"') !== false || strpos($propJs, 'class="dorm-modal-meta-location"') !== false),
  "Meta top with title and address/university must be present"
);

// Verify order in dorm-inspect-col-left: meta top -> photoStageHtml -> description -> map -> deleteBtn
$metaPos = strpos($propJs, 'dorm-modal-meta-top');
$descPos = strpos($propJs, 'dorm-modal-desc-box');
$mapPos  = strpos($propJs, 'id="inspectionModalMap"');
$delPos  = strpos($propJs, 'id="dormModalDeleteBtn"');

assertCheck(
  "In openInspectionModal, meta top is before description and map",
  $metaPos !== false && $descPos !== false && $mapPos !== false && ($metaPos < $descPos) && ($descPos < $mapPos),
  "Order must be: meta top -> description -> map below description"
);

assertCheck(
  "In openInspectionModal, delete property button is at bottom of left column",
  $mapPos !== false && $delPos !== false && ($mapPos < $delPos),
  "Delete button must follow map"
);

// 3. Gallery Dorm Cards Restored in JavaScript
assertCheck(
  "applyGridFilter renders clean vertical dorm-card without dorm-card-left-panel split",
  strpos($propJs, 'class="dorm-card-left-panel"') === false,
  "dorm-card-left-panel should not exist in applyGridFilter"
);

assertCheck(
  "applyGridFilter includes photo wrap, meta top, tenants strip, and starting rent",
  strpos($propJs, 'class="dorm-card-photo-wrap"') !== false &&
  strpos($propJs, 'class="dorm-card-meta-top"') !== false &&
  strpos($propJs, 'class="dorm-card-tenants-wrap"') !== false &&
  strpos($propJs, 'class="dorm-card-footer-rent"') !== false,
  "dorm-card must contain complete gallery info in vertical arrangement"
);

// 4. CSS Styling for Enlarged Modal & Gallery Cards
assertCheck(
  "dorm-modal-body is styled as 2-column grid (1fr 1fr)",
  strpos($propCss, 'grid-template-columns: 1fr 1fr;') !== false,
  "dorm-modal-body must use 1fr 1fr grid"
);

assertCheck(
  "dorm-inspect-col-left has login card brown radial gradient theme",
  strpos($propCss, '.landlord-properties-page .dorm-inspect-col-left {') !== false &&
  strpos($propCss, 'radial-gradient(55% 55% at 78% 8%, rgba(176, 117, 92, 0.85)') !== false &&
  strpos($propCss, 'var(--brown)') !== false,
  "Left column must have login card brown radial gradient"
);

assertCheck(
  "dorm-inspect-col-left is fixed/non-scrollable (overflow: hidden)",
  strpos($propCss, 'overflow: hidden; /* NON-SCROLLABLE fixed panel */') !== false,
  "Left column must have overflow: hidden"
);

assertCheck(
  "dorm-inspect-col-right is the only scrollable half (overflow-y: auto)",
  strpos($propCss, 'overflow-y: auto; /* ONLY SCROLLABLE HALF */') !== false,
  "Right column must have overflow-y: auto"
);

assertCheck(
  "Floating close button (.dorm-modal-close) has position absolute top-right",
  strpos($propCss, '.landlord-properties-page .dorm-modal-close {') !== false &&
  strpos($propCss, 'position: absolute;') !== false,
  "Close button must be floating absolute in top right"
);

assertCheck(
  "Gallery dorm-card is styled as clean vertical flex container",
  strpos($propCss, '.landlord-properties-page .dorm-card {') !== false &&
  strpos($propCss, 'display: flex;') !== false &&
  strpos($propCss, 'flex-direction: column;') !== false,
  "dorm-card in CSS must be flex-direction: column"
);

echo "\nSummary: $passed passed, $failed failed.\n";

if ($failed > 0) {
  exit(1);
}
