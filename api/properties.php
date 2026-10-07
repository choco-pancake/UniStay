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
      'rent' => (float)$r['rent'], 'deposit' => (float)$r['deposit'], 'occupants' => (int)$r['occupants'],
      'status' => $r['status'], 'amenities' => json_decode($r['amenities'] ?? '[]', true) ?: [],
    ];
  }
  ok(array_map(fn($p) => [
    'id' => (int)$p['id'], 'name' => $p['name'], 'university' => $p['university'], 'address' => $p['address'],
    'description' => $p['description'], 'photo' => $p['photo'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
    'landlordName' => $p['landlordName'], 'rooms' => $byProp[$p['id']] ?? [],
  ], $props));
}

// POST (multipart/form-data): create a property + its rooms
if ($method === 'POST') {
  $name = trim($_POST['name'] ?? ''); $uni = trim($_POST['university'] ?? '');
  $addr = trim($_POST['address'] ?? ''); $desc = trim($_POST['description'] ?? '');
  $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
  if ($name === '' || $uni === '' || $addr === '') fail('Please fill in all required dorm details.');
  if (!$lat || !$lng) fail('Please pin the property on the map.');
  $rooms = json_decode($_POST['rooms'] ?? '[]', true) ?: [];
  if (!$rooms) fail('Add at least one room.');
  $rooms = array_map('clean_room', $rooms);

  $file = $_FILES['photo'] ?? null;
  if (!$file || $file['error'] !== UPLOAD_ERR_OK) fail('Please upload a dorm photo.');
  if ($file['size'] > 5 * 1024 * 1024) fail('Photo must be under 5 MB.');
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][mime_content_type($file['tmp_name'])] ?? null;
  if (!$ext) fail('Photo must be a JPG, PNG, or WebP image.');

  $dir = __DIR__ . '/../uploads/properties';
  is_dir($dir) || mkdir($dir, 0755, true);
  $fname = bin2hex(random_bytes(8)) . '.' . $ext;
  move_uploaded_file($file['tmp_name'], "$dir/$fname") or fail('Could not save photo.', 500);

  $pdo = db(); $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO properties (landlord_id,name,university,address,description,photo,lat,lng) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([landlord_id(), $name, $uni, $addr, $desc, "uploads/properties/$fname", $lat, $lng]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($rooms as $r) insert_room($pid, $r);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); @unlink("$dir/$fname"); fail('Could not save property.', 500); }
  ok(['id' => $pid]);
}

// DELETE ?id=  (rooms are removed by ON DELETE CASCADE)
if ($method === 'DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  $st = db()->prepare('SELECT photo FROM properties WHERE id = ? AND landlord_id = ?'); $st->execute([$id, landlord_id()]);
  $photo = $st->fetchColumn();
  if (!$photo) fail('Property not found.', 404);
  db()->prepare('DELETE FROM properties WHERE id = ?')->execute([$id]);
  @unlink(__DIR__ . '/../' . $photo);
  ok();
}
fail('Method not allowed.', 405);