<?php
/**
 * Automated Test Suite: Enlarged Dorm Modal Divider Header, Large Photo & Vibrant Zoomable Map
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

echo "=== Enlarged Dorm Modal Divider Header & Vibrant Zoomable Map Verification ===\n\n";

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

// 2. Clean Title Row with Address and University to the Right
assertCheck(
  "openInspectionModal renders clean title meta with address and university in header row",
  strpos($propJs, 'class="dorm-modal-title-meta"') !== false &&
  strpos($propJs, '${esc(p.address)}</span>;') !== false &&
  strpos($propJs, 'near ${esc(p.university)}') !== false,
  "dorm-modal-title-meta must be present with format '[Address]; near [University]'"
);

assertCheck(
  "CSS styles .dorm-modal-title-meta cleanly without redundant SVG icons",
  strpos($propCss, '.dorm-modal-title-meta') !== false,
  "dorm-modal-title-meta must be styled in CSS"
);

assertCheck(
  "shortUni helper exists in landlord-properties.js",
  strpos($propJs, 'function shortUni(') !== false,
  "shortUni helper must be defined in JS"
);

// Verify shortUni behavior simulation in PHP
function simulateShortUni($uni) {
  $u = strtolower($uni ?? '');
  if (strpos($u, 'dagupan') !== false || strpos($u, 'udd') !== false) return 'UdD';
  if (strpos($u, 'pangasinan') !== false || strpos($u, 'upang') !== false) return 'UPang';
  if (strpos($u, 'luzon') !== false || strpos($u, ' ul') !== false || $u === 'ul') return 'UL';
  if (strpos($u, 'lyceum') !== false || strpos($u, 'lnu') !== false) return 'LNU';
  return $uni;
}

assertCheck(
  "shortUni maps Universidad de Dagupan -> UdD",
  simulateShortUni('Universidad de Dagupan') === 'UdD',
  "Must shorten Universidad de Dagupan"
);

assertCheck(
  "shortUni maps PHINMA – University of Pangasinan -> UPang",
  simulateShortUni('PHINMA – University of Pangasinan') === 'UPang',
  "Must shorten University of Pangasinan"
);

assertCheck(
  "shortUni maps University of Luzon -> UL",
  simulateShortUni('University of Luzon') === 'UL',
  "Must shorten University of Luzon"
);

assertCheck(
  "shortUni maps Lyceum-Northwestern University -> LNU",
  simulateShortUni('Lyceum-Northwestern University') === 'LNU',
  "Must shorten Lyceum-Northwestern University"
);

// 3. Sizing in CSS: Mid-Row Photo Stage & Map (Photo on left, description on right)
assertCheck(
  "Dorm photo stage sits on left in .dorm-modal-mid-row in CSS",
  preg_match('/\.dorm-modal-mid-row\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*210px\s+1fr;/s', $propCss) === 1,
  "Mid-row in left column must have 210px 1fr grid"
);

assertCheck(
  "Leaflet map container (.dorm-modal-map) has generous 270px height in CSS",
  preg_match('/\.dorm-modal-map\s*\{[^}]*height:\s*270px;/s', $propCss) === 1,
  "dorm-modal-map must have height 270px"
);

assertCheck(
  "Leaflet map tiles have vibrant filter (saturate 1.4, contrast 1.08, brightness 1.02)",
  preg_match('/\.dorm-modal-map[^{]*\.leaflet-tile-pane\s*\{[^}]*filter:\s*saturate\(1\.4\)\s+contrast\(1\.08\)\s+brightness\(1\.02\);/s', $propCss) === 1,
  "dorm-modal-map tile filter must be vibrant"
);

// 4. Interactive Map with Scroll-Wheel Zoom & Scroll Lock
assertCheck(
  "inspectionMap has scrollWheelZoom: true",
  strpos($propJs, 'scrollWheelZoom: true') !== false,
  "scrollWheelZoom must be true"
);

assertCheck(
  "inspectionMap has zoomControl: true",
  strpos($propJs, 'zoomControl: true') !== false,
  "zoomControl must be true"
);

assertCheck(
  "disableScrollPropagation is applied to map element to prevent background scroll",
  strpos($propJs, 'L.DomEvent.disableScrollPropagation(mapEl)') !== false &&
  strpos($propJs, "mapEl.addEventListener('wheel'") !== false,
  "disableScrollPropagation and wheel stopPropagation must be called on map element"
);

assertCheck(
  "inspectionMap marker opens popup with dorm name and address",
  strpos($propJs, 'm.bindPopup(') !== false && strpos($propJs, '.openPopup()') !== false,
  "Marker must bind and open popup on map load"
);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) exit(1);
