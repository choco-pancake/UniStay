<?php
require_once __DIR__ . '/../config/session.php';
start_app_session();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

const AMENITIES = [
  'WiFi',
  'Aircon',
  'Private CR',
  'Study desk',
  'Laundry',
  'Kitchen',
  'Refrigerator',
  'Water heater',
  'CCTV & Security',
  'Own electric submeter',
  'Balcony',
  'Bed with mattress',
  'Shelf'
];
const STATUSES  = ['Available', 'Occupied', 'Under Maintenance'];
const TYPES     = ['SINGLE', 'TWIN', 'QUAD', 'QUINTUPLE', 'SEXTUPLE', 'OCTUPLE', 'DECUPLE'];
const MAX_ROOM_PHOTOS = 3;
const CAPACITY_BY_TYPE = ['SINGLE' => 1, 'TWIN' => 2, 'QUAD' => 4, 'QUINTUPLE' => 5, 'SEXTUPLE' => 6, 'OCTUPLE' => 8, 'DECUPLE' => 10];

function db(): PDO { return database(); }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok($data = ['ok' => true]) { echo json_encode($data); exit; }
function body() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function landlord_id(): int {
  $user = session_user_with_role('landlord');
  if ($user === null) {
    fail('Landlord sign-in is required.', 401);
  }
  return (int)$user['id'];
}

function api_user(?string $requiredRole = null): array {
  $user = $requiredRole ? session_user_with_role($requiredRole) : session_user();
  if ($user === null) fail('Please log in again.', 401);
  return $user;
}

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
  $existingPhotos = array_key_exists('existingPhotos', $r)
    ? (array)$r['existingPhotos']
    : (array)($r['photos'] ?? []);
  $rawAm = is_array($r['amenities'] ?? null) ? $r['amenities'] : [];
  $cleanAm = [];
  foreach ($rawAm as $a) {
    $item = trim((string)$a);
    if ($item !== '' && !in_array($item, $cleanAm, true)) {
      $cleanAm[] = $item;
    }
  }
  return [
    'id' => max(0, (int)($r['id'] ?? 0)),
    'name' => $name,
    'type' => $type,
    'capacity' => $cap, 'rent' => $rent, 'rentMax' => $rentMax, 'deposit' => $deposit, 'depositMax' => $depositMax,
    'occupants' => $occ,
    'status' => in_array($r['status'] ?? '', STATUSES, true) ? $r['status'] : 'Available',
    'amenities' => json_encode(array_values($cleanAm)),
    'existingPhotos' => array_values(array_filter($existingPhotos, 'is_string')),
  ];
}

if (!function_exists('next_table_id')) {
  function next_table_id(PDO $pdo, string $table, int $minStart = 1): int {
    $st = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`");
    $maxId = (int)$st->fetchColumn();
    return max($minStart, $maxId + 1);
  }
}

if (!function_exists('reset_table_auto_increment')) {
  function reset_table_auto_increment(PDO $pdo, string $table, int $minStart = 1): void {
    try {
      $st = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`");
      $maxId = (int)$st->fetchColumn();
      $nextId = max($minStart, $maxId + 1);
      $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = $nextId");
    } catch (Throwable $e) {
      error_log("Failed to reset AUTO_INCREMENT for $table: " . $e->getMessage());
    }
  }
}

function insert_room($pid, $r, $photos = []) {
  $photos = array_values(array_filter((array)$photos));
  $pdo = db();
  $nextRoomId = next_table_id($pdo, 'rooms', 1);
  $pdo->prepare('INSERT INTO rooms (id,property_id,name,type,capacity,rent,rent_max,deposit,deposit_max,occupants,status,amenities,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$nextRoomId, $pid, $r['name'], $r['type'], $r['capacity'], $r['rent'], $r['rentMax'], $r['deposit'], $r['depositMax'], $r['occupants'], $r['status'], $r['amenities'], $photos ? json_encode($photos) : null]);
  reset_table_auto_increment($pdo, 'rooms', 1);
  return $nextRoomId;
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
  return ($raw !== null && $raw !== false && $raw !== '') ? [$raw] : [];
}

// Validates a list of uploads for one room (fails if too many or any is invalid); returns the list
function check_photos(array $files): array {
  if (count($files) > MAX_ROOM_PHOTOS) fail('Each room can have at most ' . MAX_ROOM_PHOTOS . ' photos.');
  foreach ($files as $f) check_upload($f);
  return $files;
}

// Stores a list of validated room photos; returns the array of saved paths
function store_photos(array $files, string $subdir = 'rooms'): array {
  $saved = [];
  foreach ($files as $f) {
    try {
      $saved[] = store_upload($f, $subdir);
    } catch (Throwable $e) {
      foreach ($saved as $p) delete_upload($p);   // roll back partial saves
      throw $e;
    }
  }
  return $saved;
}

// Deletes every file referenced by a photo column (JSON array, or an older single path)
function delete_photos($raw): void {
  foreach (photo_list($raw) as $path) delete_upload($path);
}
