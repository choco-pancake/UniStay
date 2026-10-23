<?php
/**
 * Automated Test Suite: Notifications Blank State & Strict 1km University Proximity
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

echo "=== Testing Landlord Notifications Empty State & 1km Filter ===\n\n";

// 1. Inspect assets/js/landlord-notifications.js
$notifJsPath = __DIR__ . '/../assets/js/landlord-notifications.js';
assertTest("landlord-notifications.js exists", file_exists($notifJsPath));
$notifJs = file_get_contents($notifJsPath);

assertTest(
  "INITIAL_LANDLORD_NOTIFS is empty array",
  preg_match('/const\s+INITIAL_LANDLORD_NOTIFS\s*=\s*\[\s*\];/', $notifJs),
  "INITIAL_LANDLORD_NOTIFS should be empty []"
);

assertTest(
  "Legacy mock items starting with notif-ll- are purged during init",
  strpos($notifJs, '!item.id.startsWith("notif-ll-")') !== false || strpos($notifJs, "!item.id.startsWith('notif-ll-')") !== false,
  "init() should filter out any legacy notif-ll-* items"
);

assertTest(
  "Notification badge is hidden when unread count is 0",
  strpos($notifJs, 'badge.hidden = unreadCount === 0') !== false,
  "badge.hidden must be true when unreadCount === 0"
);

// 2. Inspect assets/js/landlord-properties.js
$propJsPath = __DIR__ . '/../assets/js/landlord-properties.js';
assertTest("landlord-properties.js exists", file_exists($propJsPath));
$propJs = file_get_contents($propJsPath);

assertTest(
  "NEAR_KM is set to 1.0",
  preg_match('/const\s+NEAR_KM\s*=\s*1\.0;/', $propJs),
  "NEAR_KM constant must be 1.0"
);

assertTest(
  "localMatches filters by nearestUni <= NEAR_KM",
  preg_match('/localMatches\s*=\s*DAGUPAN_SPOTS\.filter\(.*?\bnearestUni\([^)]+\)\.km\s*<=\s*NEAR_KM/s', $propJs),
  "localMatches must verify distance <= NEAR_KM"
);

assertTest(
  "osmMatches filters Nominatim items by nearestUni <= NEAR_KM",
  strpos($propJs, 'nearestUni(lat, lon).km > NEAR_KM') !== false ||
  preg_match('/osmMatches\s*=\s*data\s*\.filter\(.*?\bnearestUni\(lat,\s*lon\)\.km\s*<=\s*NEAR_KM/s', $propJs),
  "osmMatches must filter by nearestUni <= NEAR_KM before mapping"
);

assertTest(
  "combined list guards additions with nearestUni <= NEAR_KM",
  strpos($propJs, 'nearestUni(om.lat, om.lng).km <= NEAR_KM') !== false,
  "combined list must verify <= NEAR_KM"
);

// 3. Mathematical Verification of Universities & DAGUPAN_SPOTS Proximity
$unis = [
  ['name' => 'Universidad de Dagupan',         'short' => 'UDD',   'lat' => 16.0507, 'lng' => 120.3408],
  ['name' => 'University of Pangasinan',       'short' => 'UPang', 'lat' => 16.0471, 'lng' => 120.3425],
  ['name' => 'University of Luzon',            'short' => 'UL',    'lat' => 16.0398, 'lng' => 120.3359],
  ['name' => 'Lyceum Northwestern University', 'short' => 'LNU',   'lat' => 16.0354, 'lng' => 120.3305],
];

function haversineDist($lat1, $lng1, $lat2, $lng2) {
  $r = pi() / 180;
  $a = sin(($lat2-$lat1)*$r/2)**2 + cos($lat1*$r)*cos($lat2*$r)*sin(($lng2-$lng1)*$r/2)**2;
  return 6371 * 2 * asin(sqrt($a));
}

function nearestUniKm($lat, $lng, $unis) {
  $min = 9999;
  foreach ($unis as $u) {
    $d = haversineDist($lat, $lng, $u['lat'], $u['lng']);
    if ($d < $min) $min = $d;
  }
  return $min;
}

// Extract DAGUPAN_SPOTS from JS file
preg_match('/const\s+DAGUPAN_SPOTS\s*=\s*\[(.*?)\];/s', $propJs, $spotsMatch);
assertTest("DAGUPAN_SPOTS extracted from landlord-properties.js", !empty($spotsMatch[1]));

preg_match_all('/\{\s*title:\s*[\'"]([^\'"]+)[\'"].*?lat:\s*([0-9.]+),\s*lng:\s*([0-9.]+)\s*\}/s', $spotsMatch[1], $spotItems, PREG_SET_ORDER);
assertTest("DAGUPAN_SPOTS parsed successfully (count >= 10)", count($spotItems) >= 10, "Found " . count($spotItems) . " spots");

$allSpotsWithin1km = true;
$failingSpots = [];
foreach ($spotItems as $item) {
  $title = $item[1];
  $lat = (float)$item[2];
  $lng = (float)$item[3];
  $km = nearestUniKm($lat, $lng, $unis);
  if ($km > 1.0) {
    $allSpotsWithin1km = false;
    $failingSpots[] = "$title ($km km)";
  }
}

assertTest(
  "Every DAGUPAN_SPOTS entry is strictly <= 1.0 km from a university",
  $allSpotsWithin1km,
  "Failing spots: " . implode(', ', $failingSpots)
);

// 4. Test simulated Out-of-Bounds and In-Bounds Coordinates
$testCoords = [
  ['name' => 'Perez Blvd (near UPang)', 'lat' => 16.0465, 'lng' => 120.3418, 'expectedIn' => true],
  ['name' => 'Tapuac Rd (near LNU)', 'lat' => 16.0360, 'lng' => 120.3310, 'expectedIn' => true],
  ['name' => 'Arellano St (near UL)', 'lat' => 16.0435, 'lng' => 120.3340, 'expectedIn' => true],
  ['name' => 'Burgos St (near UDD)', 'lat' => 16.0485, 'lng' => 120.3390, 'expectedIn' => true],
  ['name' => 'Lingayen Capitol (far away)', 'lat' => 16.0225, 'lng' => 120.2315, 'expectedIn' => false],
  ['name' => 'Calasiao Plaza (far away)', 'lat' => 16.0145, 'lng' => 120.3582, 'expectedIn' => false],
  ['name' => 'San Fabian Beach (far away)', 'lat' => 16.1230, 'lng' => 120.4020, 'expectedIn' => false],
];

foreach ($testCoords as $tc) {
  $km = nearestUniKm($tc['lat'], $tc['lng'], $unis);
  $isIn = ($km <= 1.0);
  assertTest(
    "Coordinate filter correctly evaluates '{$tc['name']}' ({$km}km)",
    $isIn === $tc['expectedIn'],
    "Expected " . ($tc['expectedIn'] ? 'inside' : 'outside') . " but got $km km"
  );
}

// 5. Check cache-busting version tags in PHP files
$propPhp = file_get_contents(__DIR__ . '/../landlord/properties.php');
assertTest(
  "properties.php has landlord-properties.js?v=13 or newer",
  preg_match('/landlord-properties\.js\?v=(?:1[3-9]|[2-9]\d)/', $propPhp) === 1
);
assertTest(
  "properties.php has landlord-notifications.js?v=2",
  strpos($propPhp, 'landlord-notifications.js?v=2') !== false
);

$notifPhp = file_get_contents(__DIR__ . '/../landlord/notifications.php');
assertTest(
  "notifications.php has landlord-notifications.js?v=2",
  strpos($notifPhp, 'landlord-notifications.js?v=2') !== false
);

echo "\nSummary: $testsPassed passed, $testsFailed failed.\n";
if ($testsFailed > 0) {
  exit(1);
}
echo "All tests passed successfully!\n";
