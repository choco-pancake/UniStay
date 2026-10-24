<?php
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to get logged-in tenant ID
function tenant_id(): int {
    if (!isset($_SESSION['user_id'])) {
        fail('Please log in to book or reserve a room.', 401);
    }
    return (int)$_SESSION['user_id'];
}

// GET /api/book.php?property_id=101 -> get current user's booking status on this property
if ($method === 'GET') {
    $propId = (int)($_GET['property_id'] ?? 0);
    if ($propId <= 0) fail('Invalid property ID.');

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $hasBooking = false;

    if ($userId > 0) {
        $st = db()->prepare("SELECT id, status, created_at FROM bookings WHERE user_id = ? AND property_id = ? AND status IN ('active', 'upcoming', 'pending') LIMIT 1");
        $st->execute([$userId, $propId]);
        $hasBooking = (bool)$st->fetch();
    }

    // Get live stats for this property
    $stCount = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM bookings WHERE property_id = ? AND status IN ('active', 'upcoming')");
    $stCount->execute([$propId]);
    $totalResidents = (int)$stCount->fetchColumn();

    // Get dorm's university
    $stUni = db()->prepare("SELECT university FROM properties WHERE id = ? LIMIT 1");
    $stUni->execute([$propId]);
    $uni = (string)$stUni->fetchColumn();

    $stMatch = db()->prepare("
        SELECT COUNT(DISTINCT b.user_id)
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        WHERE b.property_id = ?
          AND b.status IN ('active', 'upcoming')
          AND (
            (u.school IS NOT NULL AND TRIM(u.school) <> '' AND LOWER(TRIM(u.school)) = LOWER(TRIM(?)))
            OR (LOWER(?) LIKE '%dagupan%' AND (LOWER(u.school) LIKE '%dagupan%' OR LOWER(u.personal_email) LIKE '%cdd.edu.ph%'))
            OR (LOWER(?) LIKE '%luzon%' AND LOWER(u.school) LIKE '%luzon%')
            OR (LOWER(?) LIKE '%pangasinan%' AND LOWER(u.school) LIKE '%pangasinan%')
          )
    ");
    $stMatch->execute([$propId, $uni, $uni, $uni, $uni]);
    $sameSchool = (int)$stMatch->fetchColumn();

    $livePct = $totalResidents > 0 ? (int)round(($sameSchool / $totalResidents) * 100) : 0;

    ok([
        'property_id' => $propId,
        'has_booked' => $hasBooking,
        'totalResidents' => $totalResidents,
        'sameSchoolResidents' => $sameSchool,
        'livePct' => $livePct,
        'university' => $uni
    ]);
}

// POST /api/book.php -> Book or toggle booking
if ($method === 'POST') {
    $userId = tenant_id();
    $data = body();
    if (empty($data)) {
        $data = $_POST;
    }

    $propId = (int)($data['property_id'] ?? 0);
    $roomId = !empty($data['room_id']) ? (int)$data['room_id'] : null;
    $action = trim($data['action'] ?? 'book'); // 'book' or 'cancel'

    if ($propId <= 0) fail('Property ID is required.');

    // Check if property exists
    $stProp = db()->prepare("SELECT id, name, university FROM properties WHERE id = ? LIMIT 1");
    $stProp->execute([$propId]);
    $prop = $stProp->fetch();
    if (!$prop) fail('Dormitory not found.', 404);

    if ($action === 'cancel') {
        $del = db()->prepare("DELETE FROM bookings WHERE user_id = ? AND property_id = ?");
        $del->execute([$userId, $propId]);
        $message = 'Booking cancelled successfully.';
    } else {
        // Check if user already booked
        $chk = db()->prepare("SELECT id FROM bookings WHERE user_id = ? AND property_id = ? LIMIT 1");
        $chk->execute([$userId, $propId]);
        $existing = $chk->fetch();

        if ($existing) {
            $upd = db()->prepare("UPDATE bookings SET room_id = ?, status = 'active', updated_at = NOW() WHERE id = ?");
            $upd->execute([$roomId, $existing['id']]);
            $message = 'Your booking has been updated!';
        } else {
            $ins = db()->prepare("INSERT INTO bookings (user_id, property_id, room_id, status) VALUES (?, ?, ?, 'active')");
            $ins->execute([$userId, $propId, $roomId]);
            $message = 'You have successfully booked this dorm!';
        }
    }

    // Return updated live stats
    $stCount = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM bookings WHERE property_id = ? AND status IN ('active', 'upcoming')");
    $stCount->execute([$propId]);
    $totalResidents = (int)$stCount->fetchColumn();

    $uni = (string)$prop['university'];
    $stMatch = db()->prepare("
        SELECT COUNT(DISTINCT b.user_id)
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        WHERE b.property_id = ?
          AND b.status IN ('active', 'upcoming')
          AND (
            (u.school IS NOT NULL AND TRIM(u.school) <> '' AND LOWER(TRIM(u.school)) = LOWER(TRIM(?)))
            OR (LOWER(?) LIKE '%dagupan%' AND (LOWER(u.school) LIKE '%dagupan%' OR LOWER(u.personal_email) LIKE '%cdd.edu.ph%'))
            OR (LOWER(?) LIKE '%luzon%' AND LOWER(u.school) LIKE '%luzon%')
            OR (LOWER(?) LIKE '%pangasinan%' AND LOWER(u.school) LIKE '%pangasinan%')
          )
    ");
    $stMatch->execute([$propId, $uni, $uni, $uni, $uni]);
    $sameSchool = (int)$stMatch->fetchColumn();

    $livePct = $totalResidents > 0 ? (int)round(($sameSchool / $totalResidents) * 100) : 0;

    ok([
        'ok' => true,
        'message' => $message,
        'property_id' => $propId,
        'totalResidents' => $totalResidents,
        'sameSchoolResidents' => $sameSchool,
        'livePct' => $livePct,
        'has_booked' => ($action !== 'cancel')
    ]);
}

fail('Unsupported request method.', 405);
