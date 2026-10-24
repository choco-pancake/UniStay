<?php
/**
 * Automated Test Suite: Cleaner Functionality for Landlord Properties
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

echo "=== Testing Cleaner Functionality for Landlord Properties ===\n\n";

$propPhpPath = __DIR__ . '/../landlord/properties.php';
$propJsPath = __DIR__ . '/../assets/js/landlord-properties.js';
$propCssPath = __DIR__ . '/../assets/css/landlord-properties.css';
$apiPath = __DIR__ . '/../api/properties.php';

assertTest("properties.php exists", file_exists($propPhpPath));
assertTest("landlord-properties.js exists", file_exists($propJsPath));
assertTest("landlord-properties.css exists", file_exists($propCssPath));
assertTest("api/properties.php exists", file_exists($apiPath));

$propPhp = file_get_contents($propPhpPath);
$propJs = file_get_contents($propJsPath);
$propCss = file_get_contents($propCssPath);
$apiPhp = file_get_contents($apiPath);

// 1. Gallery Cards: Room Count Removed & Clean Status Badge
assertTest(
  "Room count badge removed from gallery card markup",
  strpos($propJs, 'dorm-card-stay-badge') === false,
  "dorm-card-stay-badge should not be present in gallery cards"
);

assertTest(
  "Top-right badges container only contains clean statusBadge",
  preg_match('/<div class="dorm-card-top-right-badges">\s*\${statusBadge}\s*<\/div>/', $propJs),
  "dorm-card-top-right-badges should cleanly contain only statusBadge"
);

// 2. Tenant Status Filter Bar & Aggregation
assertTest(
  "Tab labels are updated to tenant booking status",
  strpos($propPhp, 'All Dorms') !== false &&
  strpos($propPhp, 'Approved Tenants') !== false &&
  strpos($propPhp, 'Pending Tenants') !== false &&
  strpos($propPhp, 'Rejected Tenants') !== false,
  "Tabs should say All Dorms, Approved Tenants, Pending Tenants, Rejected Tenants"
);

assertTest(
  "API queries bookings for approved, pending, and rejected statuses",
  strpos($apiPhp, "'active', 'upcoming', 'approved', 'pending', 'rejected'") !== false,
  "api/properties.php should query all relevant tenant booking statuses"
);

assertTest(
  "API includes tenantStats in property return payload",
  strpos($apiPhp, "'tenantStats' => \$pStats") !== false,
  "api/properties.php should return tenantStats"
);

assertTest(
  "updateStatusTabCounts computes counts from tenantStats",
  strpos($propJs, 'p.tenantStats?.approved') !== false &&
  strpos($propJs, 'p.tenantStats?.pending') !== false &&
  strpos($propJs, 'p.tenantStats?.rejected') !== false,
  "updateStatusTabCounts should aggregate by tenantStats"
);

assertTest(
  "applyGridFilter filters by tenant booking status",
  strpos($propJs, 'currentStatusFilter === \'approved\'') !== false &&
  strpos($propJs, 'tStats.approved > 0') !== false &&
  strpos($propJs, 'tStats.pending > 0') !== false,
  "applyGridFilter should filter by tenant booking status"
);

// 3. Containerless, Smaller, Infinite Carousel
assertTest(
  "CSS .um-carousel is containerless (border: none, background: transparent, box-shadow: none)",
  preg_match('/\.um-carousel\s*\{[^}]*background:\s*transparent;[^}]*border:\s*none;[^}]*box-shadow:\s*none;/s', $propCss),
  ".um-carousel must have transparent background and no border/box-shadow"
);

assertTest(
  "CSS single photo wrapper has reduced height <= 220px and border: none",
  preg_match('/\.dorm-single-square-wrap\s*\{[^}]*max-height:\s*(?:190|220)px;[^}]*border:\s*none;/s', $propCss),
  ".dorm-single-square-wrap should be compact (<=220px) without heavy border"
);

assertTest(
  "CSS .um-carousel-track has reduced max-height <= 220px",
  preg_match('/\.um-carousel-track\s*\{[^}]*max-height:\s*(?:190|220)px;/s', $propCss),
  ".um-carousel-track max-height should be <= 220px"
);

assertTest(
  "JS carousel buttons are both visible without display:none",
  strpos($propJs, 'id="carouselPrevBtn" aria-label="Previous photo"') !== false &&
  !preg_match('/id="carouselPrevBtn"[^>]*style="display:none;"/', $propJs),
  "carouselPrevBtn should not have style='display:none;' in markup"
);

assertTest(
  "JS updateCarousel uses infinite modulo wrap-around logic",
  strpos($propJs, '(currentIdx - 1 + photos.length) % photos.length') !== false &&
  strpos($propJs, '(currentIdx + 1) % photos.length') !== false,
  "updateCarousel must use modulo photos.length for both prev and next"
);

assertTest(
  "JS carousel navigation buttons maintain display: grid for infinite navigation",
  strpos($propJs, "if (prevBtn) prevBtn.style.display = 'grid';") !== false &&
  strpos($propJs, "if (nextBtn) nextBtn.style.display = 'grid';") !== false,
  "Carousel navigation buttons must always be displayed"
);

// 4. Strict Street & Barangay Location Autocomplete (<= 1 km, NO Specific Places)
assertTest(
  "DAGUPAN_SPOTS contains only clean geographic street/barangay subtitles",
  strpos($propJs, 'Dagupan City · Near') !== false,
  "DAGUPAN_SPOTS subtitles should be clean geographic indications"
);

// Mathematical check on universities proximity
$unis = [
  ['name' => 'UDD',   'lat' => 16.0507, 'lng' => 120.3408],
  ['name' => 'UPang', 'lat' => 16.0471, 'lng' => 120.3425],
  ['name' => 'UL',    'lat' => 16.0398, 'lng' => 120.3359],
  ['name' => 'LNU',   'lat' => 16.0354, 'lng' => 120.3305],
];

function haversine($lat1, $lng1, $lat2, $lng2) {
  $r = pi() / 180;
  $a = sin(($lat2-$lat1)*$r/2)**2 + cos($lat1*$r)*cos($lat2*$r)*sin(($lng2-$lng1)*$r/2)**2;
  return 6371 * 2 * asin(sqrt($a));
}

preg_match('/const\s+DAGUPAN_SPOTS\s*=\s*\[(.*?)\];/s', $propJs, $spotsMatch);
preg_match_all('/\{\s*title:\s*[\'"]([^\'"]+)[\'"].*?lat:\s*([0-9.]+),\s*lng:\s*([0-9.]+)\s*\}/s', $spotsMatch[1], $spotItems, PREG_SET_ORDER);

$allCleanFormat = true;
$allNear = true;
foreach ($spotItems as $item) {
  $title = $item[1];
  $lat = (float)$item[2];
  $lng = (float)$item[3];

  // Must match <Street>, Barangay <Name>, Dagupan City
  if (!preg_match('/^[^,]+,\s*Barangay\s+[^,]+,\s*Dagupan City$/i', $title)) {
    $allCleanFormat = false;
  }

  $minDist = 9999;
  foreach ($unis as $u) {
    $d = haversine($lat, $lng, $u['lat'], $u['lng']);
    if ($d < $minDist) $minDist = $d;
  }
  if ($minDist > 1.0) $allNear = false;
}

assertTest(
  "Every DAGUPAN_SPOTS title strictly follows Street, Barangay, Dagupan City format",
  $allCleanFormat,
  "All curated spots must follow Street, Barangay, Dagupan City"
);

assertTest(
  "Every DAGUPAN_SPOTS spot is strictly <= 1.0 km from a university",
  $allNear,
  "All curated spots must be <= 1.0 km"
);

assertTest(
  "Nominatim search excludes commercial classes and builds clean street/barangay title",
  strpos($propJs, 'commercialClasses') !== false &&
  strpos($propJs, '${road}, ${brgy}, Dagupan City') !== false,
  "Nominatim handler must filter out commercial classes and format clean street/barangay"
);

// 5. Cache buster version tags
assertTest(
  "properties.php has landlord-properties.css?v=12",
  strpos($propPhp, 'landlord-properties.css?v=12') !== false
);

assertTest(
  "properties.php has landlord-properties.js?v=13",
  strpos($propPhp, 'landlord-properties.js?v=13') !== false
);

echo "\nSummary: $testsPassed passed, $testsFailed failed.\n";
if ($testsFailed > 0) {
  exit(1);
}
echo "All tests passed successfully!\n";
