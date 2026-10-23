<?php
/**
 * Automated Test Suite: Landlord Properties Tab Refinements
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

echo "=== Landlord Properties Tab Refinements Verification ===\n\n";

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath  = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';

$propPhp = file_get_contents($propPhpPath);
$propJs  = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);

// 1. Toolbar Cleanup
assertCheck(
  "Status filter bar (.prop-status-filter-bar) removed from properties.php",
  strpos($propPhp, 'prop-status-filter-bar') === false,
  "prop-status-filter-bar should be completely absent"
);

assertCheck(
  "Status tabs removed from properties.php",
  strpos($propPhp, 'data-status="approved"') === false &&
  strpos($propPhp, 'data-status="pending"') === false,
  "Approved/Pending tabs should be removed"
);

assertCheck(
  "Clean toolbar intact in properties.php",
  strpos($propPhp, 'id="prop-search"') !== false &&
  strpos($propPhp, 'id="uni-select"') !== false &&
  strpos($propPhp, 'id="openAddModalBtn"') !== false,
  "Search, university filter, and Add button must be present"
);

assertCheck(
  "Cache buster updated to css?v=13+ and js?v=14+",
  preg_match('/landlord-properties\.css\?v=(?:1[3-9]|[2-9]\d)/', $propPhp) === 1 &&
  preg_match('/landlord-properties\.js\?v=(?:1[4-9]|[2-9]\d)/', $propPhp) === 1,
  "Asset versions must match"
);

// 2. Add Property Modal Defaults
assertCheck(
  "Initial selectUniversity call removed from startup in JS",
  !preg_match('/selectUniversity\(\s*UNIVERSITIES\[0\]\.name/', $propJs),
  "Initial selectUniversity must not be called on script load"
);

assertCheck(
  "selectUniversity does NOT auto-place a pin",
  !preg_match('/function selectUniversity[^{]*\{[^}]*placePin\(u\.lat,\s*u\.lng\)/s', $propJs),
  "selectUniversity must not call placePin"
);

assertCheck(
  "openAddModal resets pin, university input, and suggests box",
  strpos($propJs, 'clearPin()') !== false &&
  strpos($propJs, "if (uniInput) uniInput.value = '';") !== false &&
  strpos($propJs, "document.querySelectorAll('.uni-picker-btn').forEach(b => b.classList.remove('is-selected'));") !== false,
  "openAddModal must reset university and clear pin"
);

assertCheck(
  "Map click places pin regardless of mode and triggers reverse geocode",
  strpos($propJs, "map.on('click', (e) => {") !== false &&
  strpos($propJs, "placePin(e.latlng.lat, e.latlng.lng);") !== false &&
  strpos($propJs, "reverseFill();") !== false,
  "Map click should drop pin and reverse fill"
);

// 3. Card All-Tenants Display
assertCheck(
  "Status filter variable and tab counts removed from JS",
  strpos($propJs, 'currentStatusFilter') === false &&
  strpos($propJs, 'updateStatusTabCounts') === false,
  "currentStatusFilter and updateStatusTabCounts must be removed"
);

assertCheck(
  "Card photo top right status badge removed",
  strpos($propJs, '${statusBadge}') === false &&
  strpos($propJs, 'prop-card-status-badge') === false,
  "Photo should not have status sticker badge"
);

assertCheck(
  "Card displays all tenants in .dorm-card-all-tenants",
  strpos($propJs, 'class="dorm-card-all-tenants"') !== false &&
  strpos($propJs, 'class="dorm-card-tenant-badge"') !== false &&
  strpos($propJs, 'p.tenants.map(') !== false,
  "All tenants must be rendered in dorm-card-all-tenants"
);

assertCheck(
  "Vacant dorm fallback note present on card",
  strpos($propJs, 'dorm-card-tenants-vacant') !== false &&
  strpos($propJs, 'beds vacant') !== false,
  "Vacant dorm note should be present"
);

// 4. Natural Aspect Ratio & Larger Carousel Photos in CSS
assertCheck(
  "um-carousel-track has increased height of 280px in CSS",
  preg_match('/\.um-carousel-track\s*\{[^}]*max-height:\s*280px;/s', $propCss),
  "Track max-height should be 280px"
);

assertCheck(
  "um-carousel-track has transparent background and no rectangular aspect ratio",
  preg_match('/\.um-carousel-track\s*\{[^}]*background:\s*transparent;/s', $propCss) &&
  !preg_match('/\.um-carousel-track\s*\{[^}]*aspect-ratio:\s*16\s*\/\s*9;/s', $propCss),
  "Track must be transparent and not locked to 16/9"
);

assertCheck(
  "um-slide has transparent background (no colored rectangular boxes)",
  preg_match('/\.um-slide\s*\{[^}]*background:\s*transparent;/s', $propCss) &&
  preg_match('/\.um-slide\.is-active\s*\{[^}]*background:\s*transparent;/s', $propCss),
  "um-slide must have transparent background"
);

assertCheck(
  "Single square wrap has increased max-height of 280px and transparent background",
  preg_match('/\.dorm-single-square-wrap\s*\{[^}]*max-height:\s*280px;/s', $propCss) &&
  preg_match('/\.dorm-single-square-wrap\s*\{[^}]*background:\s*transparent;/s', $propCss),
  "Single photo wrap should be 280px with transparent background"
);

assertCheck(
  "CSS defines .dorm-card-all-tenants and .dorm-card-tenant-badge",
  strpos($propCss, '.dorm-card-all-tenants') !== false &&
  strpos($propCss, '.dorm-card-tenant-badge') !== false,
  "Tenant badge styles must exist"
);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) exit(1);
