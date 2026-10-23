<?php
require __DIR__ . '/db.php';
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'POST') {            // multipart/form-data: property_id, room (JSON), optional photos[]
  $b = isset($_POST['room']) ? (json_decode($_POST['room'], true) ?: []) : body();
  $pid = (int)($_POST['property_id'] ?? $b['property_id'] ?? 0);
  $st = db()->prepare('SELECT id FROM properties WHERE id = ? AND landlord_id = ?'); $st->execute([$pid, landlord_id()]);
  if (!$st->fetchColumn()) fail('Property not found.', 404);

  $room = clean_room($b);       // validates first, so nothing is written when input is bad
  $files = check_photos(collect_uploads($_FILES['photos'] ?? $_FILES['photo'] ?? null));
  $photos = [];
  try {
    $photos = store_photos($files);
    ok(['id' => insert_room($pid, $room, $photos)]);
  } catch (Throwable $e) {
    delete_photos(json_encode($photos));
    fail('Could not save room.', 500);
  }
}
if ($m === 'DELETE') {          // ?id=ROOM_ID (only if the room belongs to this landlord)
  $id = (int)($_GET['id'] ?? 0);
  $st = db()->prepare('SELECT r.photo FROM rooms r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.landlord_id = ?');
  $st->execute([$id, landlord_id()]);
  $photo = $st->fetchColumn();
  $st = db()->prepare('DELETE r FROM rooms r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.landlord_id = ?');
  $st->execute([$id, landlord_id()]);
  if (!$st->rowCount()) fail('Room not found.', 404);
  delete_photos($photo);
  ok();
}
fail('Method not allowed.', 405);
