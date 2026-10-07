<?php
require __DIR__ . '/db.php';
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'POST') {            // JSON body: { property_id, name, type, ... }
  $b = body(); $pid = (int)($b['property_id'] ?? 0);
  $st = db()->prepare('SELECT id FROM properties WHERE id = ? AND landlord_id = ?'); $st->execute([$pid, landlord_id()]);
  if (!$st->fetchColumn()) fail('Property not found.', 404);
  ok(['id' => insert_room($pid, clean_room($b))]);
}
if ($m === 'DELETE') {          // ?id=ROOM_ID (only if the room belongs to this landlord)
  $st = db()->prepare('DELETE r FROM rooms r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.landlord_id = ?');
  $st->execute([(int)($_GET['id'] ?? 0), landlord_id()]);
  $st->rowCount() ? ok() : fail('Room not found.', 404);
}
fail('Method not allowed.', 405);