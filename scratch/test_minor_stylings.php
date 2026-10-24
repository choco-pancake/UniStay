<?php
/**
 * Automated Test Suite: Minor Stylings Refinements for Landlord Properties
 */

$testsPassed = 0;
$testsFailed = 0;

function assertTest($name, $condition, $msg = '') {
  global $testsPassed, $testsFailed;
  if ($condition) {
    echo "  [PASS] $name\n";
    $testsPassed++;
  } else {
    echo "  [FAIL] $name: $msg\n";
    $testsFailed++;
  }
}

echo "=== Testing Minor Stylings Refinements for Landlord Properties ===\n\n";

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';

assertTest("properties.php exists", file_exists($propPhpPath));
assertTest("landlord-properties.js exists", file_exists($propJsPath));
assertTest("landlord-properties.css exists", file_exists($propCssPath));

$propPhp = file_get_contents($propPhpPath);
$propJs = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);

// 1. Complete University Label
assertTest(
  "Lyceum Northwestern University is fully spelled out in selector button",
  strpos($propPhp, '<span>Lyceum Northwestern University</span>') !== false,
  "Selector button should have full 'Lyceum Northwestern University' label"
);

assertTest(
  "Old truncated 'Lyceum Northwestern Univ' is removed",
  strpos($propPhp, '<span>Lyceum Northwestern Univ</span>') === false,
  "'Lyceum Northwestern Univ' should not exist"
);

// 2. Popover Stacking Context Elevation (Only popovers elevated)
assertTest(
  "Notification popover & profile popover have maximum z-index (99999 !important) in CSS",
  preg_match('/\.landlord-properties-page\s+\.popover[^{]*\{[^}]*z-index:\s*99999\s*!important;/s', $propCss),
  "popovers must have z-index: 99999 !important"
);

assertTest(
  "Topbar icon itself is not elevated with z-index: 2000 !important",
  strpos($propCss, 'z-index: 2000 !important;') === false,
  "topbar should not have 2000 override"
);

assertTest(
  "Card top-right badges have lower z-index (2) in CSS",
  preg_match('/\.dorm-card-top-right-badges\s*\{[^}]*z-index:\s*2;/s', $propCss),
  "dorm-card-top-right-badges should have z-index: 2"
);

// 3. Compact Uncropped Photos (object-fit: contain)
assertTest(
  "Single photo wrap has max-height in CSS",
  preg_match('/\.dorm-single-square-wrap\s*\{[^}]*max-height:\s*(?:190|280)px;/s', $propCss),
  "dorm-single-square-wrap max-height should be valid"
);

assertTest(
  "Single photo image has object-fit: contain in CSS",
  preg_match('/\.dorm-single-square-wrap\s+img\s*\{[^}]*object-fit:\s*contain;/s', $propCss),
  "Single photo img should have object-fit: contain"
);

assertTest(
  "Carousel track has max-height in CSS",
  preg_match('/\.um-carousel-track\s*\{[^}]*max-height:\s*(?:190|280)px;/s', $propCss),
  "Carousel track max-height should be valid"
);

assertTest(
  "Carousel slide images have object-fit: contain in CSS",
  preg_match('/\.um-slide\s*\{[^}]*object-fit:\s*contain;/s', $propCss),
  "um-slide should have object-fit: contain"
);

assertTest(
  "Active carousel slide image has object-fit: contain in CSS",
  preg_match('/\.um-slide\.is-active\s*\{[^}]*object-fit:\s*contain;/s', $propCss),
  "um-slide.is-active should have object-fit: contain"
);

// 4. Inspection Modal Cleanup: Remove 'Viewing details' and status banner
assertTest(
  "'Viewing details' badge is removed from inspection modal header",
  strpos($propPhp, 'Viewing details') === false && strpos($propPhp, 'dorm-modal-badge') === false,
  "Viewing details badge should be completely removed"
);

assertTest(
  "statusBanner is removed from openInspectionModal in JS",
  strpos($propJs, 'statusBanner') === false,
  "statusBanner should not be referenced in landlord-properties.js"
);

assertTest(
  "Inspection modal places dorm title block and meta top at upper-left of left column in JS",
  strpos($propJs, 'dorm-modal-meta-top') !== false && strpos($propJs, 'dorm-modal-main-title') !== false,
  "Modal content should place dorm title and meta at top of left column"
);

// 5. Cache buster queries
assertTest(
  "properties.php has landlord-properties.css?v=12 or newer",
  preg_match('/landlord-properties\.css\?v=(?:1[2-9]|[2-9]\d)/', $propPhp) === 1
);

assertTest(
  "properties.php has landlord-properties.js?v=13 or newer",
  preg_match('/landlord-properties\.js\?v=(?:1[3-9]|[2-9]\d)/', $propPhp) === 1
);

echo "\nSummary: $testsPassed passed, $testsFailed failed.\n";
if ($testsFailed > 0) {
  exit(1);
}
echo "All tests passed successfully!\n";
