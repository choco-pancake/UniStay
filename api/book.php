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

    // Check tenant verification status
    $stUser = db()->prepare("SELECT verification_status, role FROM users WHERE id = ? LIMIT 1");
    $stUser->execute([$userId]);
    $u = $stUser->fetch();
    if (!$u || $u['verification_status'] !== 'verified') {
        fail('Student Verification Required: You must complete your registration form and have your School ID and Government ID verified by an administrator before submitting room booking requests.', 403);
    }

    // Check if property exists and landlord is verified
    $stProp = db()->prepare("SELECT p.id, p.name, p.university, p.landlord_id, u.verification_status AS landlord_status 
                             FROM properties p 
                             JOIN users u ON u.id = p.landlord_id 
                             WHERE p.id = ? LIMIT 1");
    $stProp->execute([$propId]);
    $prop = $stProp->fetch();
    if (!$prop) fail('Dormitory not found.', 404);

    if ($prop['landlord_status'] !== 'verified') {
        fail('This dormitory listing is currently pending landlord verification and cannot accept booking requests yet.', 403);
    }

    $bookingStatus = 'none';
    if ($action === 'cancel') {
        $del = db()->prepare("DELETE FROM bookings WHERE user_id = ? AND property_id = ?");
        $del->execute([$userId, $propId]);
        $message = 'Booking request cancelled successfully.';
    } else {
        // Check if user already booked
        $chk = db()->prepare("SELECT id, status FROM bookings WHERE user_id = ? AND property_id = ? LIMIT 1");
        $chk->execute([$userId, $propId]);
        $existing = $chk->fetch();

        if ($existing) {
            $bookingId = (int)$existing['id'];
            $upd = db()->prepare("UPDATE bookings SET room_id = ?, status = 'pending', updated_at = NOW() WHERE id = ?");
            $upd->execute([$roomId, $bookingId]);
            $bookingStatus = 'pending';
            $message = 'Your booking request has been updated and sent to the landlord for review.';
        } else {
            $ins = db()->prepare("INSERT INTO bookings (user_id, property_id, room_id, status) VALUES (?, ?, ?, 'pending')");
            $ins->execute([$userId, $propId, $roomId]);
            $bookingId = (int)db()->lastInsertId();
            $bookingStatus = 'pending';
            $message = 'Your booking request has been submitted! The landlord has been notified and will review your request.';
        }

        // Send email notifications to both landlord and tenant
        require_once __DIR__ . '/../config/notifications.php';
        send_booking_request_notification(db(), $bookingId);
        send_tenant_booking_submitted_notification(db(), $bookingId);

        // Send in-app notifications to landlord and tenant
        $stInfo = db()->prepare("
            SELECT u.first_name, u.last_name, p.name AS prop_name, p.landlord_id, r.name AS room_name
            FROM bookings b
            JOIN users u ON u.id = b.user_id
            JOIN properties p ON p.id = b.property_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
            LIMIT 1
        ");
        $stInfo->execute([$bookingId]);
        $info = $stInfo->fetch();
        if ($info) {
            $tName = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? '')) ?: 'Student';
            $pName = $info['prop_name'] ?? 'Dormitory';
            $rSuffix = !empty($info['room_name']) ? " (Room {$info['room_name']})" : "";
            $landlordUserId = (int)$info['landlord_id'];

            // In-app notification for landlord
            add_in_app_notification(
                db(),
                $landlordUserId,
                'booking',
                'New Room Booking Request',
                "Student {$tName} submitted a booking request for {$pName}{$rSuffix}.",
                $bookingId
            );

            // In-app confirmation for tenant
            add_in_app_notification(
                db(),
                $userId,
                'booking',
                'Booking Request Submitted',
                "Your booking request for {$pName}{$rSuffix} has been sent to the landlord for review.",
                $bookingId
            );
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
        'has_booked' => ($action !== 'cancel'),
        'booking_status' => $bookingStatus
    ]);
}

fail('Unsupported request method.', 405);
