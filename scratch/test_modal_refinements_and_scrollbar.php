<?php
/**
 * Automated Test Suite: Enlarged Dorm Modal Refinements & Landing Page Scrollbar Styling
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

echo "=== Enlarged Dorm Modal Refinements & Scrollbars Verification ===\n\n";

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath  = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';

$propPhp = file_get_contents($propPhpPath);
$propJs  = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);

// 1. PHP Cache Busting
assertCheck(
  "properties.php references landlord-properties.css?v=18 or newer",
  preg_match('/landlord-properties\.css\?v=(?:1[8-9]|[2-9]\d)/', $propPhp) === 1,
  "CSS link must be v=18 or newer"
);

assertCheck(
  "properties.php references landlord-properties.js?v=19 or newer",
  preg_match('/landlord-properties\.js\?v=(?:19|[2-9]\d)/', $propPhp) === 1,
  "JS script must be v=19 or newer"
);

// 2. JavaScript Template Refinements
assertCheck(
  "openInspectionModal has side-by-side title row with location and university to the right",
  strpos($propJs, 'class="dorm-modal-title-row"') !== false &&
  strpos($propJs, 'dorm-modal-title-side-meta') !== false &&
  strpos($propJs, 'class="dorm-modal-side-address"') !== false &&
  strpos($propJs, 'class="dorm-modal-side-uni"') !== false,
  "Title row must place address and near university to the right of dorm name"
);

assertCheck(
  "openInspectionModal does NOT have UNISTAY VERIFIED badge inside dorm-modal",
  !preg_match('/dorm-modal-meta-top[^<]*<span[^>]*UNISTAY VERIFIED/s', $propJs),
  "UNISTAY VERIFIED badge should be removed from inspection modal"
);

assertCheck(
  "dorm-modal-map-header does NOT contain redundant near university label",
  !preg_match('/dorm-modal-map-header[^<]*<strong>Property Location on Map<\/strong>\s*<span>Near/s', $propJs),
  "Redundant university label should be removed from map header"
);

// 3. Photo Stage & Expanded Map in CSS
assertCheck(
  "Single photo wrap in inspection modal is in mid-row layout",
  strpos($propCss, '.dorm-modal-mid-photo .dorm-single-square-wrap') !== false,
  "dorm-single-square-wrap in left column must be styled in mid-row"
);

assertCheck(
  "Carousel track in inspection modal is in mid-row layout",
  strpos($propCss, '.dorm-modal-mid-photo .um-carousel-track') !== false,
  "um-carousel-track in left column must be styled in mid-row"
);

assertCheck(
  "Leaflet map container (.dorm-modal-map) is 270px height below mid-row",
  preg_match('/\.dorm-modal-map\s*\{[^}]*height:\s*270px;/s', $propCss) === 1,
  "dorm-modal-map must have height 270px for enhanced visibility"
);

// 4. Landing Page Themed Scrollbars
assertCheck(
  "Right column has landing page scrollbar-color (var(--brown) #F3E9DF)",
  preg_match('/\.landlord-properties-page\s+\.dorm-inspect-col-right\s*\{[^}]*scrollbar-color:\s*var\(--brown\)\s*#F3E9DF;/s', $propCss) === 1,
  "dorm-inspect-col-right must have scrollbar-color: var(--brown) #F3E9DF"
);

assertCheck(
  "Right column has scrollbar-width: thin",
  preg_match('/\.landlord-properties-page\s+\.dorm-inspect-col-right\s*\{[^}]*scrollbar-width:\s*thin;/s', $propCss) === 1,
  "dorm-inspect-col-right must have scrollbar-width: thin"
);

assertCheck(
  "Webkit scrollbar track is styled with landing page cream background (#F3E9DF)",
  preg_match('/\.dorm-inspect-col-right::-webkit-scrollbar-track[^{]*\{[^}]*background:\s*#F3E9DF;/s', $propCss) === 1,
  "Scrollbar track must have background #F3E9DF"
);

assertCheck(
  "Webkit scrollbar thumb is styled with landing page brown background and border",
  preg_match('/\.dorm-inspect-col-right::-webkit-scrollbar-thumb[^{]*\{[^}]*background:\s*var\(--brown\);[^}]*border:\s*2px\s+solid\s+#F3E9DF;/s', $propCss) === 1,
  "Scrollbar thumb must have background var(--brown) and border 2px solid #F3E9DF"
);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) exit(1);
