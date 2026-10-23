<?php
$unis = [
  ['name' => 'UDD',   'lat' => 16.0507, 'lng' => 120.3408],
  ['name' => 'UPang', 'lat' => 16.0471, 'lng' => 120.3425],
  ['name' => 'UL',    'lat' => 16.0398, 'lng' => 120.3359],
  ['name' => 'LNU',   'lat' => 16.0354, 'lng' => 120.3305],
];

$spots = [
  ['title' => 'Arellano Street, Barangay Pantal, Dagupan City', 'sub' => 'Near Universidad de Dagupan & UL', 'lat' => 16.0435, 'lng' => 120.3340],
  ['title' => 'Perez Boulevard, Barangay Pogo Chico, Dagupan City', 'sub' => 'Near University of Pangasinan', 'lat' => 16.0465, 'lng' => 120.3418],
  ['title' => 'Burgos Street, Barangay Poblacion Oeste, Dagupan City', 'sub' => 'Central Dagupan near UDD', 'lat' => 16.0485, 'lng' => 120.3390],
  ['title' => 'Tapuac Road, Barangay Tapuac, Dagupan City', 'sub' => 'Near Lyceum Northwestern University', 'lat' => 16.0360, 'lng' => 120.3310],
  ['title' => 'De Venecia Highway, Barangay Tapuac, Dagupan City', 'sub' => 'De Venecia Highway / LNU Area', 'lat' => 16.0320, 'lng' => 120.3280],
  ['title' => 'M.H. Del Pilar Street, Barangay Herrero-Perez, Dagupan City', 'sub' => 'Commercial center near UPang', 'lat' => 16.0475, 'lng' => 120.3440],
  ['title' => 'Rizal Extension, Barangay Dagupan Centro, Dagupan City', 'sub' => 'Near University of Luzon', 'lat' => 16.0405, 'lng' => 120.3365],
  ['title' => 'MacArthur Highway, Barangay Mayombo, Dagupan City', 'sub' => 'Along McArthur Highway near UPang', 'lat' => 16.0450, 'lng' => 120.3470],
  ['title' => 'Zamora Street, Barangay Pantal, Dagupan City', 'sub' => 'Riverside district Dagupan', 'lat' => 16.0520, 'lng' => 120.3350],
  ['title' => 'Malued Road, Barangay Malued, Dagupan City', 'sub' => 'Residential area near UPang & UL', 'lat' => 16.0410, 'lng' => 120.3410],
  ['title' => 'Fernandez Avenue, Barangay Downtown, Dagupan City', 'sub' => 'Downtown commercial corridor', 'lat' => 16.0450, 'lng' => 120.3380],
  ['title' => 'Guilig Street, Barangay Poblacion, Dagupan City', 'sub' => 'Near University of Luzon', 'lat' => 16.0420, 'lng' => 120.3348]
];

function dist($lat1, $lng1, $lat2, $lng2) {
  $r = pi() / 180;
  $a = sin(($lat2-$lat1)*$r/2)**2 + cos($lat1*$r)*cos($lat2*$r)*sin(($lng2-$lng1)*$r/2)**2;
  return 6371 * 2 * asin(sqrt($a));
}

foreach ($spots as $s) {
  $min = 9999; $uName = '';
  foreach ($unis as $u) {
    $d = dist($s['lat'], $s['lng'], $u['lat'], $u['lng']);
    if ($d < $min) { $min = $d; $uName = $u['name']; }
  }
  printf("%-65s -> nearest %s: %.3f km %s\n", $s['title'], $uName, $min, ($min <= 1.0 ? '✓' : '✗ OUT'));
}
