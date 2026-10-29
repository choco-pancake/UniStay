<?php
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to get logged-in tenant ID
function tenant_id(): int {
    $user = api_user('tenant');
    return (int)$user['id'];
}

function finish_response_early(array $data): void {
    ignore_user_abort(true);
    set_time_limit(90);
    $json = json_encode($data);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('fastcgi_finish_request')) {
        echo $json;
        fastcgi_finish_request();
        return;
    }
    header('Content-Encoding: none');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    flush();
}

// GET /api/book.php?property_id=101 -> get current user's booking status on this property
if ($method === 'GET') {
    $propId = (int)($_GET['property_id'] ?? 0);
    if ($propId <= 0) fail('Invalid property ID.');

    $userId = 0;
    if (isset($_SESSION['user_id'])) {
        $sessionUser = session_user();
        if ($sessionUser === null) fail('Please log in again.', 401);
        $userId = (int)$sessionUser['id'];
    }
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

if ($method === 'POST') {
    $userId = tenant_id();
    session_write_close();   // release the session lock so the tenant's other requests don't queue behind this one
    $pdo = db();
    $data = body();
    if (empty($data)) {
        $data = $_POST;
    }

    $propId = (int)($data['property_id'] ?? 0);
    $roomId = !empty($data['room_id']) ? (int)$data['room_id'] : null;
    $action = trim($data['action'] ?? 'book'); // 'book' or 'cancel'

    if ($propId <= 0) fail('Property ID is required.');

    // Admin verification is not required, but the tenant registration must be complete.
    $stUser = $pdo->prepare("SELECT role, first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $stUser->execute([$userId]);
    $u = $stUser->fetch();
    if (!$u) {
        fail('Account not found.', 404);
    }

    if ($u['role'] !== 'tenant') fail('Tenant sign-in is required.', 403);

    // Keep cancellation available, but never accept a new booking from an incomplete profile.
    if ($action !== 'cancel' && !tenant_profile_complete($pdo, $userId)) {
        fail('Complete your registration information in Settings before submitting a booking request.', 403);
    }

    // Check if property exists and landlord is verified
    $stProp = $pdo->prepare("SELECT p.id, p.name, p.university, p.landlord_id, u.verification_status AS landlord_status
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
        $pending = $pdo->prepare("
            SELECT b.id, b.status, r.name AS room_name
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.user_id = ? AND b.property_id = ? AND b.status IN ('pending', 'upcoming')
            ORDER BY b.id DESC
            LIMIT 1
        ");
        $pending->execute([$userId, $propId]);
        $cancelled = $pending->fetch();
        if (!$cancelled) {
            fail('Only an upcoming stay can be cancelled here. To leave an active stay, use the dashboard.', 409);
        }
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([(int)$cancelled['id'], $userId]);

        require_once __DIR__ . '/../config/notifications.php';
        $tenantName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'A tenant';
        $roomSuffix = !empty($cancelled['room_name']) ? " (Room {$cancelled['room_name']})" : '';
        add_in_app_notification(
            $pdo,
            (int)$prop['landlord_id'],
            'cancellation',
            'Tenant cancelled an upcoming stay',
            "{$tenantName} cancelled their upcoming stay request at {$prop['name']}{$roomSuffix}.",
            (int)$cancelled['id']
        );
        $message = 'Upcoming stay cancelled successfully.';
    } else {
        // Check if user already booked
        $chk = $pdo->prepare("SELECT id, status FROM bookings WHERE user_id = ? AND property_id = ? LIMIT 1");
        $chk->execute([$userId, $propId]);
        $existing = $chk->fetch();

        if ($existing && in_array($existing['status'], ['active', 'upcoming'], true)) {
            fail('You already have a stay in this dormitory. Message your landlord if you need to change rooms.', 409);
        }

        if ($existing) {
            $bookingId = (int)$existing['id'];
            $pdo->prepare("UPDATE bookings SET room_id = ?, status = 'pending', updated_at = NOW() WHERE id = ?")
                ->execute([$roomId, $bookingId]);
            $message = 'Your booking request has been updated and sent to the landlord for review.';
        } else {
            $pdo->prepare("INSERT INTO bookings (user_id, property_id, room_id, status) VALUES (?, ?, ?, 'pending')")
                ->execute([$userId, $propId, $roomId]);
            $bookingId = (int)$pdo->lastInsertId();
            $message = 'Your booking request has been submitted! The landlord has been notified and will review your request.';
        }

        // In-app notifications are quick database inserts, so they stay before the response.
        require_once __DIR__ . '/../config/notifications.php';
        $tName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'Student';
        $pName = $prop['name'] ?: 'Dormitory';
        $rSuffix = '';
        if ($roomId) {
            $stRoom = $pdo->prepare('SELECT name FROM rooms WHERE id = ? AND property_id = ? LIMIT 1');
            $stRoom->execute([$roomId, $propId]);
            $rName = $stRoom->fetchColumn();
            if ($rName) $rSuffix = " (Room {$rName})";
        }
        add_in_app_notification(
            $pdo,
            (int)$prop['landlord_id'],
            'booking',
            'New Room Booking Request',
            "Student {$tName} submitted a booking request for {$pName}{$rSuffix}.",
            $bookingId
        );
        add_in_app_notification(
            $pdo,
            $userId,
            'booking',
            'Booking Request Submitted',
            "Your booking request for {$pName}{$rSuffix} has been sent to the landlord for review.",
            $bookingId
        );

        // The tenant gets their answer now. The slow SMTP emails run after the response is sent.
        finish_response_early([
            'ok' => true,
            'message' => $message,
            'property_id' => $propId,
            'has_booked' => true,
            'booking_status' => 'pending',
        ]);

        send_booking_request_notification($pdo, $bookingId);
        send_tenant_booking_submitted_notification($pdo, $bookingId);
        exit;
    }

    // Cancel only: return updated live stats
    $stCount = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM bookings WHERE property_id = ? AND status IN ('active', 'upcoming')");
    $stCount->execute([$propId]);
    $totalResidents = (int)$stCount->fetchColumn();

    $uni = (string)$prop['university'];
    $stMatch = $pdo->prepare("
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
        'has_booked' => false,
        'booking_status' => $bookingStatus
    ]);
}

fail('Unsupported request method.', 405);

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

fail('Unsupported request method.', 405);
