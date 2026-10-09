<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

const DB_HOST = 'localhost', DB_NAME = 'unistay', DB_USER = 'root', DB_PASS = '';
const AMENITIES = ['WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen'];
const STATUSES  = ['Available', 'Occupied', 'Under Maintenance'];
const TYPES     = ['SINGLE', 'TWIN', 'QUAD', 'QUINTUPLE', 'SEXTUPLE', 'OCTUPLE', 'DECUPLE'];
// Capacity is derived from the room type (the "max occupants" field no longer exists)
const CAPACITY_BY_TYPE = ['SINGLE' => 1, 'TWIN' => 2, 'QUAD' => 4, 'QUINTUPLE' => 5, 'SEXTUPLE' => 6, 'OCTUPLE' => 8, 'DECUPLE' => 10];

function db() {
  static $pdo;
  return $pdo ??= new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok($data = ['ok' => true]) { echo json_encode($data); exit; }
function body() { return json_decode(file_get_contents('php://input'), true) ?? []; }
// TODO: replace with your real login. Until then everything runs as seeded landlord #1.
function landlord_id() { return (int)($_SESSION['user_id'] ?? 1); }

// Validates + normalizes one room (calls fail() on bad input). capacity comes from the room type.
// rent_max / deposit_max: null = fixed price, set = price range (must be >= the minimum).
function clean_room($r) {
  $name = trim($r['name'] ?? '');
  $rent = (float)($r['rent'] ?? 0);
  $occ = (int)($r['occupants'] ?? 0);
  $type = in_array($r['type'] ?? '', TYPES, true) ? $r['type'] : 'SINGLE';
  $cap = CAPACITY_BY_TYPE[$type];
  $rentMax = ($r['rentMax'] ?? null) === null || $r['rentMax'] === '' ? null : (float)$r['rentMax'];
  $deposit = max(0, (float)($r['deposit'] ?? 0));
  $depositMax = ($r['depositMax'] ?? null) === null || $r['depositMax'] === '' ? null : (float)$r['depositMax'];
  if ($name === '' || $rent <= 0) fail('Each room needs a name and rent.');
  if ($rentMax !== null && $rentMax < $rent) fail('The maximum monthly payment must not be lower than the minimum.');
  if ($depositMax !== null && $depositMax < $deposit) fail('The maximum deposit must not be lower than the minimum.');
  if ($occ < 0 || $occ > $cap) fail('Current occupants exceed the capacity for this room type.');
  return [
    'name' => $name,
    'type' => $type,
    'capacity' => $cap, 'rent' => $rent, 'rentMax' => $rentMax, 'deposit' => $deposit, 'depositMax' => $depositMax,
    'occupants' => $occ,
    'status' => in_array($r['status'] ?? '', STATUSES, true) ? $r['status'] : 'Available',
    'amenities' => json_encode(array_values(array_intersect(AMENITIES, (array)($r['amenities'] ?? [])))),
  ];
}
function insert_room($pid, $r, $photo = null) {
  db()->prepare('INSERT INTO rooms (property_id,name,type,capacity,rent,rent_max,deposit,deposit_max,occupants,status,amenities,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$pid, $r['name'], $r['type'], $r['capacity'], $r['rent'], $r['rentMax'], $r['deposit'], $r['depositMax'], $r['occupants'], $r['status'], $r['amenities'], $photo]);
  return (int)db()->lastInsertId();
}

// ---- Upload helpers ----
// Flattens $_FILES entries into a plain list, skipping "no file" placeholders
function collect_uploads($files): array {
  if (!$files || ($files['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_NO_FILE) return [];
  if (!is_array($files['name'])) return [$files];
  $out = [];
  foreach ($files['name'] as $i => $n) {
    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
    $out[] = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
              'error' => $files['error'][$i], 'size' => $files['size'][$i]];
  }
  return $out;
}
// Validates one upload (fail() exits before anything is written to disk)
function check_upload($file) {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('A photo could not be uploaded.');
  if ($file['size'] > 5 * 1024 * 1024) fail('Photos must be under 5 MB each.');
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][mime_content_type($file['tmp_name'])] ?? null;
  if (!$ext) fail('Photos must be JPG, PNG, or WebP images.');
  return $ext;
}
// Moves a validated upload; returns the path stored in the DB (relative to the project root)
function store_upload($file, string $subdir): string {
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][mime_content_type($file['tmp_name'])] ?? 'jpg';
  $dir = __DIR__ . '/../uploads/' . $subdir;
  is_dir($dir) || mkdir($dir, 0755, true);
  $fname = bin2hex(random_bytes(8)) . '.' . $ext;
  move_uploaded_file($file['tmp_name'], "$dir/$fname") or fail('Could not save photo.', 500);
  return "uploads/$subdir/$fname";
}
function delete_upload(?string $path) {
  if ($path) @unlink(__DIR__ . '/../' . ltrim($path, '/'));
}
// The properties.photo column stores a JSON array of paths (older rows may hold a single path)
function photo_list($raw): array {
  $decoded = json_decode((string)$raw, true);
  if (is_array($decoded)) return array_values(array_filter($decoded, 'is_string'));
  return $raw !== '' ? [$raw] : [];
}