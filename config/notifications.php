<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_template.php';

/**
 * Sends an email notification to the landlord when a student submits a new room booking request.
 */
function send_booking_request_notification(PDO $pdo, int $bookingId): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT b.id, b.room_id,
                   p.name AS property_name, p.university,
                   l.personal_email AS landlord_email, l.first_name AS landlord_first_name,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   t.personal_email AS tenant_email, t.phone_number AS tenant_phone, t.school AS tenant_school,
                   r.name AS room_name, r.type AS room_type, r.rent AS room_rent
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users l ON l.id = p.landlord_id
            JOIN users t ON t.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
            LIMIT 1
        ');
        $stmt->execute([$bookingId]);
        $data = $stmt->fetch();

        if (!$data || empty($data['landlord_email'])) {
            return false;
        }

        $landlordEmail = (string)$data['landlord_email'];
        $landlordName = trim((string)$data['landlord_first_name']) ?: 'Landlord';
        $tenantName = trim(($data['tenant_first_name'] ?? '') . ' ' . ($data['tenant_last_name'] ?? '')) ?: 'A student';
        $propertyName = (string)($data['property_name'] ?? 'Your Dormitory');
        $school = (string)($data['tenant_school'] ?? 'University');
        $roomInfo = !empty($data['room_name']) ? ($data['room_name'] . (!empty($data['room_type']) ? ' (' . ucfirst($data['room_type']) . ')' : '')) : 'Room inquiry';

        $subject = "New Booking Request: {$propertyName} - UniStay";
        $heading = "New Booking Request Received";
        $paragraphs = [
            "Hello {$landlordName},",
            "Great news! {$tenantName} from {$school} has submitted a room booking request for {$propertyName}.",
            "Room: {$roomInfo}" . (!empty($data['room_rent']) ? " · ₱" . number_format((float)$data['room_rent'], 2) . "/month" : ""),
            "Tenant Contact: " . ($data['tenant_phone'] ?: $data['tenant_email'] ?: 'Available in portal'),
            "Please log in to your UniStay Landlord Portal to review their credentials and approve or decline the reservation."
        ];

        $buttonText = "Review Booking Request";
        $buttonUrl = app_url('landlord/tenants.php');
        $footerNote = "You are receiving this email because a student requested a booking for your dormitory listed on UniStay.";

        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nReview at: " . $buttonUrl;

        return send_mail($landlordEmail, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (booking request): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends an email acknowledgement to the tenant when they submit a new room booking request.
 */
function send_tenant_booking_submitted_notification(PDO $pdo, int $bookingId): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT b.id, b.room_id, b.created_at,
                   p.name AS property_name, p.address AS property_address, p.university,
                   l.first_name AS landlord_first_name, l.last_name AS landlord_last_name,
                   l.phone_number AS landlord_phone, l.personal_email AS landlord_email,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   t.personal_email AS tenant_email,
                   r.name AS room_name, r.type AS room_type, r.rent AS room_rent
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users l ON l.id = p.landlord_id
            JOIN users t ON t.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
            LIMIT 1
        ');
        $stmt->execute([$bookingId]);
        $data = $stmt->fetch();

        if (!$data || empty($data['tenant_email'])) {
            return false;
        }

        $tenantEmail = (string)$data['tenant_email'];
        $tenantName = trim((string)$data['tenant_first_name']) ?: 'Student';
        $propertyName = (string)($data['property_name'] ?? 'Dormitory');
        $landlordName = trim(($data['landlord_first_name'] ?? '') . ' ' . ($data['landlord_last_name'] ?? '')) ?: 'The Landlord';
        $roomInfo = !empty($data['room_name']) ? ($data['room_name'] . (!empty($data['room_type']) ? ' (' . ucfirst($data['room_type']) . ')' : '')) : 'Room reservation';

        $subject = "Booking Request Submitted: {$propertyName} - UniStay";
        $heading = "Booking Request Submitted 📋";
        $paragraphs = [
            "Hello {$tenantName},",
            "Your room booking request for {$propertyName} has been received and forwarded directly to {$landlordName} for review.",
            "Room: {$roomInfo}" . (!empty($data['room_rent']) ? " · ₱" . number_format((float)$data['room_rent'], 2) . "/month" : ""),
            "Location: " . ($data['property_address'] ?: $data['university']),
            "Next Steps: The landlord will review your request and student verification. As soon as the landlord approves or declines your reservation, you will immediately receive an email notification update."
        ];

        $buttonText = "View My Bookings";
        $buttonUrl = app_url('tenant/dashboard.php');
        $footerNote = "You are receiving this confirmation email because you submitted a room reservation request on UniStay.";

        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nTrack booking at: " . $buttonUrl;

        return send_mail($tenantEmail, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (tenant booking submitted): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends an email notification to the student when their booking request is approved or rejected by the landlord.
 */
function send_booking_decision_notification(PDO $pdo, int $bookingId, string $status): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT b.id, b.status,
                   p.name AS property_name, p.address AS property_address,
                   l.first_name AS landlord_first_name, l.last_name AS landlord_last_name,
                   l.phone_number AS landlord_phone, l.personal_email AS landlord_email,
                   t.personal_email AS tenant_email, t.first_name AS tenant_first_name,
                   r.name AS room_name
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users l ON l.id = p.landlord_id
            JOIN users t ON t.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
            LIMIT 1
        ');
        $stmt->execute([$bookingId]);
        $data = $stmt->fetch();

        if (!$data || empty($data['tenant_email'])) {
            return false;
        }

        $tenantEmail = (string)$data['tenant_email'];
        $tenantName = trim((string)$data['tenant_first_name']) ?: 'Student';
        $propertyName = (string)($data['property_name'] ?? 'Dormitory');
        $landlordName = trim(($data['landlord_first_name'] ?? '') . ' ' . ($data['landlord_last_name'] ?? '')) ?: 'The Landlord';
        $landlordContact = $data['landlord_phone'] ?: $data['landlord_email'] ?: 'Available in portal';

        if ($status === 'active') {
            // Approved
            $subject = "Booking Approved! {$propertyName} - UniStay";
            $heading = "Your Booking is Approved! 🎉";
            $paragraphs = [
                "Hello {$tenantName},",
                "Congratulations! Your room reservation for {$propertyName} has been officially approved by {$landlordName}.",
                "You are now registered as an active resident. You can view your stay details, room assignment, and dormitory guidelines anytime in your dashboard.",
                "Landlord Contact: {$landlordContact}",
                "Feel free to reach out to your landlord regarding move-in schedules and check-in procedures."
            ];
            $buttonText = "View My Active Stay";
            $buttonUrl = app_url('tenant/dashboard.php');
        } elseif ($status === 'rejected') {
            // Rejected
            $subject = "Booking Update: {$propertyName} - UniStay";
            $heading = "Booking Request Update";
            $paragraphs = [
                "Hello {$tenantName},",
                "Thank you for your interest in {$propertyName}.",
                "The landlord was unable to accept your room booking request at this time, possibly due to room capacity or scheduling.",
                "Don't worry! There are many other verified student dormitories and bedspaces ready for you on UniStay."
            ];
            $buttonText = "Explore Available Dorms";
            $buttonUrl = app_url('tenant/dorms.php');
        } else {
            return false;
        }

        $footerNote = "You are receiving this transactional update regarding your dormitory booking request on UniStay.";
        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nView details: " . $buttonUrl;

        return send_mail($tenantEmail, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (booking decision): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends a confirmation email to the landlord when they make a decision (approve or reject) on a booking request.
 */
function send_landlord_booking_decision_confirmation(PDO $pdo, int $bookingId, string $status): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT b.id, b.status,
                   p.name AS property_name,
                   l.personal_email AS landlord_email, l.first_name AS landlord_first_name,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   r.name AS room_name
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users l ON l.id = p.landlord_id
            JOIN users t ON t.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ?
            LIMIT 1
        ');
        $stmt->execute([$bookingId]);
        $data = $stmt->fetch();

        if (!$data || empty($data['landlord_email'])) {
            return false;
        }

        $landlordEmail = (string)$data['landlord_email'];
        $landlordName = trim((string)$data['landlord_first_name']) ?: 'Landlord';
        $tenantName = trim(($data['tenant_first_name'] ?? '') . ' ' . ($data['tenant_last_name'] ?? '')) ?: 'The student';
        $propertyName = (string)($data['property_name'] ?? 'Your Property');
        $roomName = !empty($data['room_name']) ? (string)$data['room_name'] : 'Room inquiry';

        if ($status === 'active') {
            $subject = "Booking Decision Confirmed: {$tenantName} - UniStay";
            $heading = "Booking Approved Confirmed ✓";
            $paragraphs = [
                "Hello {$landlordName},",
                "You have successfully approved the booking request from {$tenantName} for {$roomName} at {$propertyName}.",
                "{$tenantName} has been sent an approval notification email and is now reflected under your active residents list."
            ];
            $buttonText = "View Active Tenants";
            $buttonUrl = app_url('landlord/tenants.php');
        } elseif ($status === 'rejected') {
            $subject = "Booking Request Declined: {$tenantName} - UniStay";
            $heading = "Booking Request Declined";
            $paragraphs = [
                "Hello {$landlordName},",
                "You have declined the booking request from {$tenantName} for {$roomName} at {$propertyName}.",
                "{$tenantName} has been notified accordingly via email so they may explore other room options."
            ];
            $buttonText = "Manage Tenant Requests";
            $buttonUrl = app_url('landlord/tenants.php?status=rejected');
        } else {
            return false;
        }

        $footerNote = "You are receiving this confirmation email because you managed a booking request for your property on UniStay.";
        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nManage tenants at: " . $buttonUrl;

        return send_mail($landlordEmail, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (landlord decision confirmation): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends an email notification when an admin approves or rejects identity verification credentials.
 */
function send_verification_decision_notification(PDO $pdo, int $userId, string $status, ?string $notes = null): bool
{
    try {
        $stmt = $pdo->prepare('SELECT id, role, first_name, last_name, personal_email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['personal_email'])) {
            return false;
        }

        $email = (string)$user['personal_email'];
        $name = trim((string)$user['first_name']) ?: 'User';
        $role = (string)($user['role'] ?? 'tenant');
        $roleTitle = ($role === 'landlord') ? 'Landlord' : 'Student';

        if ($status === 'verified') {
            $subject = "Account Verified! Welcome to UniStay";
            $heading = "Your Account is Verified! ✓";
            $benefit = ($role === 'landlord')
                ? "Your identity and property credentials have been verified. Your dormitory listings are now active and ready to accept student booking requests."
                : "Your student credentials have been verified. You can now request room reservations and secure accommodations near your university.";

            $paragraphs = [
                "Hello {$name},",
                "Great news! Your UniStay {$roleTitle} verification documents have been reviewed and approved by the administration.",
                $benefit,
                "Thank you for helping keep the UniStay community safe and trustworthy!"
            ];
            $buttonText = ($role === 'landlord') ? "Open Landlord Dashboard" : "Browse Dormitories";
            $buttonUrl = ($role === 'landlord') ? app_url('landlord/dashboard.php') : app_url('tenant/dorms.php');
        } elseif ($status === 'rejected') {
            $subject = "Verification Update - UniStay";
            $heading = "Document Verification Update";
            $reason = !empty($notes) ? "Feedback from admin: {$notes}" : "The submitted identification documents could not be verified. Please ensure your uploads are clear, well-lit, and uncropped.";

            $paragraphs = [
                "Hello {$name},",
                "Our administrative team reviewed your recent {$roleTitle} verification submission.",
                $reason,
                "You can easily re-upload valid identification documents from your settings to request another review."
            ];
            $buttonText = "Update Verification Documents";
            $buttonUrl = ($role === 'landlord') ? app_url('landlord/settings.php') : app_url('tenant/settings.php');
        } else {
            return false;
        }

        $footerNote = "You are receiving this transactional email regarding your UniStay account verification status.";
        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nTake action at: " . $buttonUrl;

        return send_mail($email, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (verification decision): ' . $e->getMessage());
        return false;
    }
}

/**
 * Inserts an in-app notification for a given user.
 */
function add_in_app_notification(PDO $pdo, int $userId, string $type, string $title, string $message, ?int $bookingId = null): int
{
    try {
        $allowed = ['booking', 'approval', 'cancellation', 'message', 'system'];
        if (!in_array($type, $allowed, true)) {
            $type = 'system';
        }
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, booking_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$userId, $bookingId, $type, $title, $message]);
        return (int)$pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('UniStay in-app notification error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches recent in-app notifications for a user, sorted descending.
 */
function get_user_in_app_notifications(PDO $pdo, int $userId, int $limit = 50): array
{
    try {
        $limit = max(1, min($limit, 100));
        $stmt = $pdo->prepare('
            SELECT id, booking_id, type, title, message, is_read AS `read`,
                   UNIX_TIMESTAMP(created_at) * 1000 AS timestamp
            FROM notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ' . (int)$limit . '
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (string)$r['id'];
            $r['read'] = (bool)$r['read'];
            $r['timestamp'] = (int)$r['timestamp'];
        }
        unset($r);
        return $rows ?: [];
    } catch (\Throwable $e) {
        error_log('UniStay in-app notification fetch error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Gets count of unread notifications for a user.
 */
function get_user_unread_notification_count(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Marks a single notification as read for a user.
 */
function mark_in_app_notification_read(PDO $pdo, int $userId, int $notifId): bool
{
    try {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        return $stmt->execute([$notifId, $userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Marks all notifications as read for a user.
 */
function mark_all_in_app_notifications_read(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        return $stmt->execute([$userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Clears/removes all notifications for a user.
 */
function clear_user_in_app_notifications(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
        return $stmt->execute([$userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

