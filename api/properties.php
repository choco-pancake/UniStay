<?php
require __DIR__ . '/db.php';
$method = $_SERVER['REQUEST_METHOD'];

// GET /api/properties.php          -> all properties (tenant map)
// GET /api/properties.php?mine=1   -> current landlord's properties
if ($method === 'GET') {
  $sql = 'SELECT p.*, u.name AS landlordName FROM properties p JOIN users u ON u.id = p.landlord_id';
  $args = [];
  if (isset($_GET['mine'])) { $sql .= ' WHERE p.landlord_id = ?'; $args[] = landlord_id(); }
  $st = db()->prepare($sql . ' ORDER BY p.created_at DESC'); $st->execute($args);
  $props = $st->fetchAll();

  $byProp = [];
  foreach (db()->query('SELECT * FROM rooms ORDER BY id')->fetchAll() as $r) {
    $byProp[$r['property_id']][] = [
      'id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => (int)$r['capacity'],
      'rent' => (float)$r['rent'], 'rentMax' => $r['rent_max'] !== null ? (float)$r['rent_max'] : null,
      'deposit' => (float)$r['deposit'], 'depositMax' => $r['deposit_max'] !== null ? (float)$r['deposit_max'] : null,
      'occupants' => (int)$r['occupants'],
      'status' => $r['status'], 'amenities' => json_decode($r['amenities'] ?? '[]', true) ?: [],
      'photo' => photo_list($r['photo'])[0] ?? null, 'photos' => photo_list($r['photo']),
    ];
  }
  ok(array_map(function ($p) use ($byProp) {
    $photos = photo_list($p['photo']);
    return [
      'id' => (int)$p['id'], 'name' => $p['name'], 'university' => $p['university'], 'address' => $p['address'],
      'description' => $p['description'], 'photo' => $photos[0] ?? '', 'photos' => $photos,
      'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
      'landlordName' => $p['landlordName'], 'rooms' => $byProp[$p['id']] ?? [],
    ];
  }, $props));
}

function near_university(float $lat, float $lng, float $maxKm = 1.0): bool {
  foreach ([[16.0507,120.3408],[16.0471,120.3425],[16.0398,120.3359],[16.0354,120.3305]] as [$uLat,$uLng]) {
    $dLat = deg2rad($lat - $uLat); $dLng = deg2rad($lng - $uLng);
    $a = sin($dLat/2)**2 + cos(deg2rad($uLat)) * cos(deg2rad($lat)) * sin($dLng/2)**2;
    if (6371 * 2 * asin(sqrt($a)) <= $maxKm) return true;
  }
  return false;
}

// POST (multipart/form-data): create a property + its rooms
if ($method === 'POST') {
  $name = trim($_POST['name'] ?? ''); $uni = trim($_POST['university'] ?? '');
  $addr = trim($_POST['address'] ?? ''); $desc = trim($_POST['description'] ?? '');
  $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
  if ($name === '' || $uni === '' || $addr === '') fail('Please fill in all required dorm details.');
  if (!preg_match('/^[^,]{3,},\s*[^,]{3,},\s*[^,]{3,}(,\s*[^,]{3,})?$/u', $addr))
  fail('Address must follow the format: Street, Barangay, City.');
  if (!$lat || !$lng) fail('Please pin the property on the map.');
  if (!near_university($lat, $lng)) fail('Property must be within 1 km of one of the supported universities.');
  $rooms = json_decode($_POST['rooms'] ?? '[]', true) ?: [];
  if (!$rooms) fail('Add at least one room.');
  $rooms = array_map('clean_room', $rooms);

  // Dorm photos: at least 3
  $dormFiles = collect_uploads($_FILES['photos'] ?? null);
  foreach ($dormFiles as $f) check_upload($f);
  if (count($dormFiles) < 3) fail('Please upload at least 3 dorm photos.');

   // Room photos (optional, up to MAX_ROOM_PHOTOS per room, matched by room index)
  $roomFiles = [];
  foreach (array_keys($rooms) as $i) {
    $f = check_photos(collect_uploads($_FILES["room_photo_$i"] ?? null));
    if ($f) $roomFiles[$i] = $f;
  }

  $dormPhotos = array_map(fn($f) => store_upload($f, 'properties'), $dormFiles);
  $roomPhotos = [];
  foreach ($roomFiles as $i => $files) $roomPhotos[$i] = store_photos($files);

  $pdo = db(); $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO properties (landlord_id,name,university,address,description,photo,lat,lng) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([landlord_id(), $name, $uni, $addr, $desc, json_encode($dormPhotos), $lat, $lng]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($rooms as $i => $r) insert_room($pid, $r, $roomPhotos[$i] ?? []);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    foreach ($dormPhotos as $p) delete_upload($p);
    foreach ($roomPhotos as $list) delete_photos(json_encode($list));
    fail('Could not save property.', 500);
  }
  ok(['id' => $pid]);
}

// DELETE ?id=  (rooms are removed by ON DELETE CASCADE)
if ($method === 'DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  $st = db()->prepare('SELECT photo FROM properties WHERE id = ? AND landlord_id = ?'); $st->execute([$id, landlord_id()]);
  $photo = $st->fetchColumn();
  if ($photo === false) fail('Property not found.', 404);
  $roomPhotos = db()->prepare('SELECT photo FROM rooms WHERE property_id = ?');
  $roomPhotos->execute([$id]);
  $roomPhotos = $roomPhotos->fetchAll(PDO::FETCH_COLUMN);
  db()->prepare('DELETE FROM properties WHERE id = ?')->execute([$id]);
  delete_photos($photo);
  foreach ($roomPhotos as $raw) delete_photos($raw);
  ok();
}
fail('Method not allowed.', 405);