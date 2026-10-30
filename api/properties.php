<?php
require __DIR__ . '/db.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (isset($_SESSION['user_id']) && session_user() === null) {
  fail('Please log in again.', 401);
}

function resolve_landlord_photo_url(?string $profilePhoto, ?string $googlePhoto): ?string {
  $url = resolve_user_profile_photo_url($profilePhoto, $googlePhoto, 'landlord');
  return $url !== '' ? $url : null;
}

function parse_landlord_social(?string $raw): array {
  $raw = trim((string)$raw);
  if ($raw === '') {
    return ['facebook' => null, 'messenger' => null, 'username' => null];
  }

  $clean = preg_replace('~^https?://(?:www\.)?facebook\.com/~i', '', $raw);
  $clean = preg_replace('~^(?:www\.)?facebook\.com/~i', '', $clean);
  $clean = ltrim($clean, '/');

  $fbUrl = 'https://www.facebook.com/' . $clean;
  $messengerUrl = null;
  $username = null;

  if (preg_match('~m\.me/([^/?&#]+)~i', $raw, $m)) {
    $username = $m[1];
    $messengerUrl = 'https://m.me/' . $username;
  } elseif (preg_match('~profile\.php\?id=([0-9]+)~i', $raw, $m)) {
    $username = $m[1];
    $messengerUrl = 'https://m.me/' . $username;
  } elseif ($clean !== '') {
    $candidate = explode('/', explode('?', $clean)[0])[0] ?? '';
    if ($candidate !== '' && !in_array(strtolower($candidate), ['pages', 'groups', 'share', 'home', 'messages', 'watch', 'marketplace'])) {
      $username = $candidate;
      $messengerUrl = 'https://m.me/' . $username;
    }
  }

  if (!$messengerUrl && $fbUrl) {
    $messengerUrl = $fbUrl;
  }
  return [
    'facebook' => $fbUrl,
    'messenger' => $messengerUrl,
    'username' => $username,
  ];
}

// GET /api/properties.php          -> all approved properties (tenant map)
// GET /api/properties.php?mine=1   -> current landlord's properties (all approval statuses)
if ($method === 'GET') {
  $sql = "SELECT p.*,
                 u.id AS landlord_user_id,
                 CONCAT_WS(' ', u.first_name, u.last_name) AS landlordName,
                 u.photo AS landlordPhoto,
                 u.profile_photo AS landlordProfilePhoto,
                 u.phone_number AS landlordPhone,
                 u.facebook_link AS landlordFacebook,
                 u.personal_email AS landlordEmail,
                 u.verification_status AS landlordVerification,
                 u.created_at AS landlordCreatedAt
          FROM properties p
          JOIN users u ON u.id = p.landlord_id";
  $args = [];
  if (isset($_GET['mine'])) {
    $sql .= ' WHERE p.landlord_id = ?';
    $args[] = landlord_id();
  } else {
    $sql .= " WHERE p.approval_status = 'approved'";
  }
  $st = db()->prepare($sql . ' ORDER BY p.created_at DESC'); $st->execute($args);
  $props = $st->fetchAll();

  // Fetch tenants per room and property categorized by status (approved, pending, rejected)
  $occupantsByRoom = [];
  $occupantsByProp = [];
  $tenantStatsByProp = [];
  try {
    $occStmt = db()->query("
      SELECT b.property_id, b.room_id, b.user_id, b.status AS booking_status,
             CONCAT_WS(' ', u.first_name, u.last_name) AS full_name,
             u.first_name, u.last_name, u.school, u.personal_email, u.photo, u.profile_photo, u.role
      FROM bookings b
      JOIN users u ON u.id = b.user_id
      WHERE b.status IN ('active', 'upcoming', 'approved', 'pending', 'rejected')
    ");
    foreach ($occStmt->fetchAll() as $occ) {
      $name = trim((string)$occ['full_name']);
      if (!$name && !empty($occ['personal_email'])) {
        $name = explode('@', $occ['personal_email'])[0];
      }
      $rawStatus = (string)($occ['booking_status'] ?? 'pending');
      $category = in_array($rawStatus, ['active', 'upcoming', 'approved']) ? 'approved' : ($rawStatus === 'rejected' ? 'rejected' : 'pending');

      $occData = [
        'userId' => (int)$occ['user_id'],
        'name' => $name ?: 'Student Resident',
        'school' => $occ['school'] ?: 'Student',
        'photo' => resolve_user_profile_photo_url(
          $occ['profile_photo'] ?? null,
          $occ['photo'] ?? null,
          (string)($occ['role'] ?? 'tenant')
        ) ?: null,
        'status' => $category,
        'rawStatus' => $rawStatus,
      ];

      $propId = (int)$occ['property_id'];
      if (!isset($tenantStatsByProp[$propId])) {
        $tenantStatsByProp[$propId] = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'total' => 0];
      }
      $tenantStatsByProp[$propId][$category]++;
      $tenantStatsByProp[$propId]['total']++;

      if (!empty($occ['room_id'])) {
        $occupantsByRoom[(int)$occ['room_id']][] = $occData;
      }
      $occupantsByProp[$propId][] = $occData;
    }
  } catch (Exception $e) {}

  $byProp = [];
  foreach (db()->query('SELECT * FROM rooms ORDER BY id')->fetchAll() as $r) {
    $roomId = (int)$r['id'];
    $allRoomTenants = $occupantsByRoom[$roomId] ?? [];
    $activeOccupants = array_filter($allRoomTenants, fn($t) => $t['status'] === 'approved');
    $byProp[$r['property_id']][] = [
      'id' => $roomId, 'name' => $r['name'], 'type' => $r['type'], 'capacity' => (int)$r['capacity'],
      'rent' => (float)$r['rent'], 'rentMax' => $r['rent_max'] !== null ? (float)$r['rent_max'] : null,
      'deposit' => (float)$r['deposit'], 'depositMax' => $r['deposit_max'] !== null ? (float)$r['deposit_max'] : null,
      'occupants' => count($activeOccupants) > 0 ? count($activeOccupants) : (int)$r['occupants'],
      'status' => $r['status'], 'amenities' => json_decode($r['amenities'] ?? '[]', true) ?: [],
      'photo' => photo_list($r['photo'])[0] ?? null, 'photos' => photo_list($r['photo']),
      'tenants' => array_values($activeOccupants),
      'allTenants' => array_values($allRoomTenants),
    ];
  }

  // Fetch live bookings per property to compute real resident percentages
  $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
  $bookingsByProp = [];
  try {
    $bkStmt = db()->query("
      SELECT b.property_id, b.user_id, u.school, u.personal_email
      FROM bookings b
      JOIN users u ON u.id = b.user_id
      WHERE b.status IN ('active', 'upcoming')
    ");
    foreach ($bkStmt->fetchAll() as $bk) {
      $bookingsByProp[$bk['property_id']][] = $bk;
    }
  } catch (Exception $e) {
    // Bookings table fallback
    $bookingsByProp = [];
  }

  // Fetch current user's bookings across all properties to get stay status
  $userBookings = [];
  if ($currentUserId > 0) {
    try {
      $uStmt = db()->prepare("SELECT property_id, status FROM bookings WHERE user_id = ? ORDER BY COALESCE(updated_at, created_at) ASC, id ASC");
      $uStmt->execute([$currentUserId]);
      foreach ($uStmt->fetchAll() as $ub) {
        $userBookings[(int)$ub['property_id']] = (string)$ub['status'];
      }
    } catch (Exception $e) {}
  }

  ok(array_map(function ($p) use ($byProp, $bookingsByProp, $userBookings, $occupantsByProp, $currentUserId) {
    $photos = photo_list($p['photo']);
    $propBookings = $bookingsByProp[$p['id']] ?? [];
    $totalResidents = count($propBookings);
    $uni = (string)$p['university'];
    $sameSchool = 0;
    $rawUserStayStatus = $userBookings[(int)$p['id']] ?? null;
    $hasBooked = in_array($rawUserStayStatus, ['active', 'upcoming', 'pending'], true);
    // Tenant-facing filters treat a request awaiting landlord review as an upcoming stay.
    $userStayStatus = $rawUserStayStatus === 'pending' ? 'upcoming' : $rawUserStayStatus;

    foreach ($propBookings as $b) {
      $userSchool = trim((string)($b['school'] ?? ''));
      $userEmail = trim((string)($b['personal_email'] ?? ''));

      $match = false;
      if ($userSchool !== '' && strcasecmp($userSchool, $uni) === 0) {
        $match = true;
      } elseif (stripos($uni, 'dagupan') !== false && (stripos($userSchool, 'dagupan') !== false || stripos($userEmail, 'cdd.edu.ph') !== false)) {
        $match = true;
      } elseif (stripos($uni, 'luzon') !== false && stripos($userSchool, 'luzon') !== false) {
        $match = true;
      } elseif (stripos($uni, 'pangasinan') !== false && stripos($userSchool, 'pangasinan') !== false) {
        $match = true;
      }

      if ($match) {
        $sameSchool++;
      }
    }

    $livePct = $totalResidents > 0 ? (int)round(($sameSchool / $totalResidents) * 100) : 0;
    $propTenants = $occupantsByProp[(int)$p['id']] ?? [];
    $pStats = $tenantStatsByProp[(int)$p['id']] ?? ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'total' => 0];
    $landlordPhoto = resolve_landlord_photo_url($p['landlordProfilePhoto'] ?? null, $p['landlordPhoto'] ?? null);
    $social = parse_landlord_social($p['landlordFacebook'] ?? null);
    $memberSince = !empty($p['landlordCreatedAt']) ? date('F Y', strtotime($p['landlordCreatedAt'])) : '2024';

    $landlordVerified = ($p['landlordVerification'] ?? '') === 'verified';

    $landlordInfo = [
      'id' => (int)($p['landlord_id'] ?? $p['landlord_user_id'] ?? 0),
      'name' => $p['landlordName'] ?: 'Landlord',
      'photo' => $landlordPhoto,
      'phone' => $p['landlordPhone'] ?: null,
      'email' => $p['landlordEmail'] ?: null,
      'facebook' => $social['facebook'],
      'messenger' => $social['messenger'],
      'facebookUsername' => $social['username'],
      'verification' => $p['landlordVerification'] ?: 'unverified',
      'verified' => $landlordVerified,
      'memberSince' => $memberSince,
    ];

    return [
      'id' => (int)$p['id'], 'landlord_id' => (int)$p['landlord_id'], 'name' => $p['name'], 'university' => $p['university'], 'address' => $p['address'],
      'description' => $p['description'], 'photo' => $photos[0] ?? '', 'photos' => $photos,
      'proof_of_ownership' => $p['proof_of_ownership'] ?? null,
      'proofOfOwnership' => !empty($p['proof_of_ownership']) ? (strpos($p['proof_of_ownership'], 'http') === 0 ? $p['proof_of_ownership'] : '../' . ltrim($p['proof_of_ownership'], '/.')) : null,
      'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
      'approvalStatus' => $p['approval_status'] ?? 'approved',
      'tenantStats' => $pStats,
      'commonSchoolPct' => $totalResidents > 0 ? $livePct : (int)($p['common_school_pct'] ?? 0),
      'livePct' => $livePct,
      'totalResidents' => $totalResidents,
      'sameSchoolResidents' => $sameSchool,
      'hasBooked' => $hasBooked,
      'userStayStatus' => $userStayStatus,
      'landlordName' => $p['landlordName'],
      'landlordVerified' => $landlordVerified,
      'landlordPhoto' => $landlordPhoto,
      'landlord' => $landlordInfo,
      'tenants' => $propTenants,
      'rooms' => $byProp[$p['id']] ?? [],
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

// POST (multipart/form-data): create or update a property + its rooms
if ($method === 'POST') {
  $editId = (int)($_POST['id'] ?? $_POST['property_id'] ?? 0);
  $landlordId = landlord_id();

  // Check landlord verification status
  $stLandlord = db()->prepare('SELECT verification_status FROM users WHERE id = ?');
  $stLandlord->execute([$landlordId]);
  $lStatus = (string)$stLandlord->fetchColumn();
  if ($lStatus !== 'verified') {
    fail('Landlord Verification Required: You must be verified with a valid Government ID in Settings before publishing or updating dorm listings.', 403);
  }

  $name = trim($_POST['name'] ?? ''); $uni = trim($_POST['university'] ?? '');
  $addr = trim($_POST['address'] ?? ''); $desc = trim($_POST['description'] ?? '');
  $lat = (float)($_POST['lat'] ?? 0); $lng = (float)($_POST['lng'] ?? 0);
  $commonPct = max(0, min(100, (int)($_POST['common_school_pct'] ?? 0)));
  if ($name === '' || $uni === '' || $addr === '') fail('Please fill in all required dorm details.');
  if (!preg_match('/^[^,]{3,},\s*[^,]{3,},\s*[^,]{3,}(,\s*[^,]{3,})?$/u', $addr))
  fail('Address must follow the format: Street, Barangay, City.');
  if (!$lat || !$lng) fail('Please pin the property on the map.');
  if (!near_university($lat, $lng)) fail('Property must be within 1 km of one of the supported universities.');
  $rooms = json_decode($_POST['rooms'] ?? '[]', true) ?: [];
  if (!$rooms) fail('Add at least one room.');
  $rooms = array_map('clean_room', $rooms);

  // Dorm photos: 1 to 3 photos
  $dormFiles = collect_uploads($_FILES['photos'] ?? null);
  foreach ($dormFiles as $f) check_upload($f);

  // Proof of property ownership (1 file: JPG, PNG, WebP, or PDF up to 10 MB)
  $ownershipFile = $_FILES['proof_of_ownership'] ?? null;
  $ownershipPath = null;
  if ($ownershipFile && ($ownershipFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    if ($ownershipFile['size'] > 10 * 1024 * 1024) fail('Proof of property ownership must be under 10 MB.');
    $fName = $ownershipFile['name'];
    $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
      fail('Proof of property ownership must be a JPG, PNG, WebP image or PDF document.');
    }
    $dir = __DIR__ . '/../uploads/ownership';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $savedName = 'ownership_' . landlord_id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($ownershipFile['tmp_name'], "$dir/$savedName")) {
      fail('Could not save proof of ownership document.', 500);
    }
    $ownershipPath = "uploads/ownership/$savedName";
  } elseif (!empty($_POST['existing_proof_of_ownership'])) {
    $ownershipPath = preg_replace('#^(\.\./)+#', '', trim($_POST['existing_proof_of_ownership']));
  }

  if ($editId > 0) {
    $existing = db()->prepare('SELECT id, photo, proof_of_ownership FROM properties WHERE id = ? AND landlord_id = ?');
    $existing->execute([$editId, landlord_id()]);
    $existingProp = $existing->fetch();
    if (!$existingProp) fail('Property not found or unauthorized.', 404);

    if (!$ownershipPath) {
      $ownershipPath = $existingProp['proof_of_ownership'] ?? null;
    }

    $storedDormPhotos = photo_list($existingProp['photo'] ?? null);
    $retainedDormPhotos = $storedDormPhotos;
    if (array_key_exists('existing_photos', $_POST)) {
      $requestedDormPhotos = json_decode((string)$_POST['existing_photos'], true);
      if (!is_array($requestedDormPhotos)) fail('Existing dorm photos are invalid.');

      $allowedDormPhotos = [];
      foreach ($storedDormPhotos as $storedDormPhoto) {
        $normalized = preg_replace('#^(?:\.\./)+#', '', ltrim((string)$storedDormPhoto, '/'));
        $allowedDormPhotos[$normalized] = $storedDormPhoto;
      }

      $retainedDormPhotos = [];
      foreach ($requestedDormPhotos as $requestedDormPhoto) {
        if (!is_string($requestedDormPhoto)) continue;
        $normalized = preg_replace('#^(?:\.\./)+#', '', ltrim($requestedDormPhoto, '/'));
        if (isset($allowedDormPhotos[$normalized]) && !in_array($allowedDormPhotos[$normalized], $retainedDormPhotos, true)) {
          $retainedDormPhotos[] = $allowedDormPhotos[$normalized];
        }
      }
    }

    $dormPhotoCount = count($retainedDormPhotos) + count($dormFiles);
    if ($dormPhotoCount < 1 || $dormPhotoCount > 3) fail('Please keep or upload 1 to 3 dorm photos.');
    $dormPhotos = array_merge(
      $retainedDormPhotos,
      array_map(fn($f) => store_upload($f, 'properties'), $dormFiles)
    );
  } else {
    if (count($dormFiles) < 1 || count($dormFiles) > 3) fail('Please upload 1 to 3 dorm photos.');
    $dormPhotos = array_map(fn($f) => store_upload($f, 'properties'), $dormFiles);
    if (!$ownershipPath) {
      fail('Please upload proof of property ownership (Land Title, Deed of Sale, Tax Declaration, or Notarized Master Lease).');
    }
  }

  // Room photos (optional, up to MAX_ROOM_PHOTOS per room, matched by room index)
  $roomFiles = [];
  foreach (array_keys($rooms) as $i) {
    $f = check_photos(collect_uploads($_FILES["room_photo_$i"] ?? null));
    if ($f) $roomFiles[$i] = $f;
  }

  $roomPhotos = [];
  foreach ($roomFiles as $i => $files) $roomPhotos[$i] = store_photos($files);

  $pdo = db(); $pdo->beginTransaction();
  try {
    $existingRoomPhotoMap = [];
    if ($editId > 0) {
      $existingRoomsStmt = $pdo->prepare('SELECT id, photo FROM rooms WHERE property_id = ?');
      $existingRoomsStmt->execute([$editId]);
      foreach ($existingRoomsStmt->fetchAll() as $existingRoom) {
        $existingRoomPhotoMap[(int)$existingRoom['id']] = photo_list($existingRoom['photo']);
      }

      $pdo->prepare('UPDATE properties SET name=?, university=?, address=?, description=?, photo=?, proof_of_ownership=?, lat=?, lng=?, common_school_pct=? WHERE id=? AND landlord_id=?')
          ->execute([$name, $uni, $addr, $desc, json_encode($dormPhotos), $ownershipPath, $lat, $lng, $commonPct, $editId, landlord_id()]);
      $pid = $editId;
      $pdo->prepare('DELETE FROM rooms WHERE property_id = ?')->execute([$editId]);
    } else {
      $nextPid = next_table_id($pdo, 'properties', 101);
      $pdo->prepare('INSERT INTO properties (id, landlord_id, name, university, address, description, photo, proof_of_ownership, lat, lng, common_school_pct, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$nextPid, landlord_id(), $name, $uni, $addr, $desc, json_encode($dormPhotos), $ownershipPath, $lat, $lng, $commonPct, 'pending']);
      $pid = $nextPid;
    }

    foreach ($rooms as $i => $r) {
      $storedPhotos = $existingRoomPhotoMap[(int)($r['id'] ?? 0)] ?? [];
      $allowedPhotos = [];
      foreach ($storedPhotos as $storedPhoto) {
        $normalized = preg_replace('#^(?:\.\./)+#', '', ltrim((string)$storedPhoto, '/'));
        $allowedPhotos[$normalized] = $storedPhoto;
      }

      $retainedPhotos = [];
      foreach ($r['existingPhotos'] ?? [] as $requestedPhoto) {
        $normalized = preg_replace('#^(?:\.\./)+#', '', ltrim((string)$requestedPhoto, '/'));
        if (isset($allowedPhotos[$normalized]) && !in_array($allowedPhotos[$normalized], $retainedPhotos, true)) {
          $retainedPhotos[] = $allowedPhotos[$normalized];
        }
      }

      $finalRoomPhotos = array_merge($retainedPhotos, $roomPhotos[$i] ?? []);
      if (count($finalRoomPhotos) > MAX_ROOM_PHOTOS) {
        throw new InvalidArgumentException('Each room can have at most ' . MAX_ROOM_PHOTOS . ' photos.');
      }
      insert_room($pid, $r, $finalRoomPhotos);
    }
    $pdo->commit();
    reset_table_auto_increment($pdo, 'properties', 101);
    reset_table_auto_increment($pdo, 'rooms', 1);
  } catch (Throwable $e) {
    $pdo->rollBack();
    if (count($dormFiles) > 0) {
      foreach ($dormPhotos as $p) delete_upload($p);
    }
    foreach ($roomPhotos as $list) delete_photos(json_encode($list));
    fail('Could not save property: ' . $e->getMessage(), 500);
  }
  ok(['id' => $pid, 'updated' => $editId > 0]);
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
  reset_table_auto_increment(db(), 'properties', 101);
  reset_table_auto_increment(db(), 'rooms', 1);
  ok();
}
fail('Method not allowed.', 405);
