<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/notification_preferences.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_template.php';

/**
 * Sends an email notification to the landlord when a student submits a new room booking request.
 */
function send_booking_request_notification(PDO $pdo, int $bookingId): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT l.id AS landlord_id, b.id, b.room_id,
                   p.name AS property_name, p.nearest_uni AS university,
                   l.email AS landlord_email, l.first_name AS landlord_first_name,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   t.email AS tenant_email, t.phone_number AS tenant_phone, t.university AS tenant_school,
                   r.name AS room_name, r.occupancy AS room_type, r.rent AS room_rent
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.id = p.landlord_id
            JOIN tenants t ON t.id = b.tenant_id
            WHERE b.id = ?
            LIMIT 1
        ');
        $stmt->execute([$bookingId]);
        $data = $stmt->fetch();

        if (!$data || empty($data['landlord_email'])) {
            return false;
        }

        if (!notification_delivery_enabled($pdo, (int)$data['landlord_id'], 'landlord', 'booking_alerts')) return false;

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
                   p.name AS property_name, p.address AS property_address, p.nearest_uni AS university,
                   l.first_name AS landlord_first_name, l.last_name AS landlord_last_name,
                   l.phone_number AS landlord_phone, l.email AS landlord_email,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   t.email AS tenant_email,
                   r.name AS room_name, r.occupancy AS room_type, r.rent AS room_rent
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.id = p.landlord_id
            JOIN tenants t ON t.id = b.tenant_id
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
                   l.phone_number AS landlord_phone, l.email AS landlord_email,
                   l.facebook_link AS landlord_facebook,
                   t.email AS tenant_email, t.first_name AS tenant_first_name,
                   r.name AS room_name
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.id = p.landlord_id
            JOIN tenants t ON t.id = b.tenant_id
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
        $landlordPhone = (string)($data['landlord_phone'] ?? '');
        $landlordEmail = (string)($data['landlord_email'] ?? '');
        $landlordFb = trim((string)($data['landlord_facebook'] ?? ''));
        $roomName = !empty($data['room_name']) ? (string)$data['room_name'] : 'Requested Room';

        if ($status === 'active' || $status === 'approved') {
            $subject = "Booking Approved! Welcome to {$propertyName} - UniStay";
            $heading = "Your Booking is Approved! 🎉";
            $contactInfo = "Landlord Contact: " . ($landlordPhone ?: $landlordEmail ?: 'Available in dashboard');
            $paragraphs = [
                "Hello {$tenantName},",
                "Fantastic news! {$landlordName} has accepted and approved your booking request for {$roomName} at {$propertyName}.",
                "{$contactInfo}",
            ];
            if (!empty($landlordFb)) {
                $paragraphs[] = "You can chat directly with your landlord through Facebook: {$landlordFb}";
            }
            $paragraphs[] = "Next Steps: Please visit your tenant dashboard to review your confirmed stay details and coordinate check-in requirements.";
            $buttonText = "View Booking Details";
            $buttonUrl = app_url('tenant/dashboard.php');
        } elseif ($status === 'rejected') {
            $subject = "Booking Request Update: {$propertyName} - UniStay";
            $heading = "Booking Request Update";
            $paragraphs = [
                "Hello {$tenantName},",
                "Thank you for your interest in {$propertyName}. Unfortunately, the landlord is unable to accommodate your reservation for {$roomName} at this time.",
                "Don't worry! There are many other verified student dormitories and available rooms listed near your university."
            ];
            $buttonText = "Browse Other Rooms";
            $buttonUrl = app_url('tenant/dorms.php');
        } else {
            return false;
        }

        $footerNote = "You are receiving this notification because you placed a room booking on UniStay.";
        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nDetails at: " . $buttonUrl;

        return send_mail($tenantEmail, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (booking decision): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends a confirmation email to the landlord when they make a decision on a booking request.
 */
function send_landlord_booking_decision_confirmation(PDO $pdo, int $bookingId, string $status): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT b.id, b.status,
                   p.name AS property_name,
                   l.email AS landlord_email, l.first_name AS landlord_first_name,
                   t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
                   r.name AS room_name
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.id = p.landlord_id
            JOIN tenants t ON t.id = b.tenant_id
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

        if ($status === 'active' || $status === 'approved') {
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
function send_verification_decision_notification(PDO $pdo, int $userId, string $status, ?string $notes = null, string $role = 'tenant'): bool
{
    try {
        $table = ($role === 'landlord') ? 'landlords' : 'tenants';
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, email AS personal_email FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || (empty($user['email']) && empty($user['personal_email']))) {
            return false;
        }

        if ($role === 'landlord' && !notification_delivery_enabled($pdo, $userId, 'landlord', 'verification_alerts')) return false;
        $email = (string)($user['email'] ?? $user['personal_email']);
        $name = trim((string)$user['first_name']) ?: 'User';
        $roleTitle = ($role === 'landlord') ? 'Landlord' : 'Student';

        if ($status === 'verified' || $status === 'approved') {
            $subject = "Account Verified! Welcome to UniStay";
            $heading = "Your Account is Verified! ✓";
            $benefit = ($role === 'landlord')
                ? "Your identity and credentials have been verified. Your dormitory listings are now active and ready to accept student booking requests."
                : "Your student credentials have been verified. You can now request room reservations and secure accommodations near your university.";

            $paragraphs = [
                "Hello {$name},",
                "Great news! Your UniStay {$roleTitle} documents have been reviewed and approved by the administration.",
                $benefit,
                "Log in now to experience all verified community features."
            ];

            $buttonText = ($role === 'landlord') ? "Open Landlord Portal" : "Find a Room";
            $buttonUrl = ($role === 'landlord') ? app_url('landlord/dashboard.php') : app_url('tenant/dorms.php');
        } elseif ($status === 'rejected' || $status === 'declined') {
            $subject = "Action Required: Verification Update - UniStay";
            $heading = "Verification Could Not Be Approved";
            $reason = !empty($notes) ? "Feedback from reviewer: " . $notes : "Please ensure your submitted ID photo is clear, valid, and fully readable.";

            $paragraphs = [
                "Hello {$name},",
                "Thank you for submitting your verification details to UniStay. After reviewing your credentials, we were unable to approve your verification at this time.",
                $reason,
                "Please log in to your account settings to review the requirements and submit updated documentation."
            ];

            $buttonText = ($role === 'landlord') ? "Contact Support" : "Update Credentials";
            $buttonUrl = ($role === 'landlord') ? app_url('pages/contact-us.php') : app_url('tenant/settings.php');
        } else {
            return false;
        }

        $footerNote = "You are receiving this security email because an administrative review occurred on your UniStay account.";
        $htmlBody = mail_html($heading, $paragraphs, $buttonText, $buttonUrl, $footerNote);
        $plainBody = implode("\n\n", $paragraphs) . "\n\nAccess portal at: " . $buttonUrl;

        return send_mail($email, $subject, $plainBody, $htmlBody);
    } catch (\Throwable $e) {
        error_log('UniStay notification error (verification decision): ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends an email notification to the administrator whenever a new prospective landlord submits an application.
 */
function send_landlord_application_admin_email(PDO $pdo, array $application): bool
{
    $settings = mail_settings();
    $recipient = $settings['admin_recipient'] ?? '';
    if ($recipient === '') {
        return false;
    }

    $name = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)($application['personal_email'] ?? $application['email'] ?? 'A prospective landlord');
    }

    $email = (string)($application['personal_email'] ?? $application['email'] ?? '');
    $phone = (string)($application['phone_number'] ?? $application['phone'] ?? '');
    $address = (string)($application['residential_address'] ?? 'Not provided');
    $experience = (string)($application['experience_summary'] ?? 'Not provided');
    $facebook = (string)($application['facebook_link'] ?? 'Not provided');

    $appId = (int)($application['id'] ?? 0);
    $reviewUrl = app_url('admin/verification.php?view=applications&filter=pending');

    if ($appId > 0) {
        $reviewUrl = app_url("admin/application-review.php?id={$appId}&token=review");
    }

    $paragraphs = [
        "A new landlord application has been submitted on UniStay.",
        "Applicant: {$name}",
        "Email: {$email}",
        "Phone: {$phone}",
        "Facebook: {$facebook}",
        "Residential Address: {$address}",
        "Experience Summary: " . (strlen($experience) > 300 ? substr($experience, 0, 297) . '...' : $experience),
        "Log in to the Admin Portal to review their government ID and approve or decline the application."
    ];

    $html = mail_html(
        'New Landlord Application',
        $paragraphs,
        'Review Landlord Application',
        $reviewUrl,
        'You received this email because you are configured as the UniStay administrator.'
    );

    $plain = implode("\n\n", $paragraphs) . "\n\nReview at: " . $reviewUrl;

    return send_mail($recipient, 'New landlord application: ' . $name, $plain, $html);
}

/**
 * Inserts an in-app notification for a given user and role.
 */
function add_in_app_notification(PDO $pdo, int $userId, string $type, string $title, string $message, ?int $bookingId = null, string $role = 'tenant'): int
{
    try {
        $allowed = ['booking', 'approval', 'cancellation', 'system'];
        if (!in_array($type, $allowed, true)) {
            $type = 'system';
        }
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : 'tenant';
        $stmt = $pdo->prepare('INSERT INTO notifications (role, user_id, booking_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$targetRole, $userId, $bookingId, $type, $title, $message]);
        return (int)$pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('UniStay in-app notification error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches recent in-app notifications for a user, sorted descending.
 */
function get_user_in_app_notifications(PDO $pdo, int $userId, int $limit = 50, ?string $role = null): array
{
    try {
        $limit = max(1, min($limit, 100));
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : ($_SESSION['role'] ?? 'tenant');
        $stmt = $pdo->prepare('
            SELECT n.id, n.booking_id, n.type, n.title, n.message, n.is_read AS `read`,
                   UNIX_TIMESTAMP(n.created_at) * 1000 AS timestamp,
                   r.dorm_id AS property_id, r.id AS room_id
            FROM notifications n
            LEFT JOIN bookings b ON b.id = n.booking_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE n.role = ? AND n.user_id = ?
            ORDER BY n.id DESC
            LIMIT ' . (int)$limit . '
        ');
        $stmt->execute([$targetRole, $userId]);
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
function get_user_unread_notification_count(PDO $pdo, int $userId, ?string $role = null): int
{
    try {
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : ($_SESSION['role'] ?? 'tenant');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE role = ? AND user_id = ? AND is_read = 0');
        $stmt->execute([$targetRole, $userId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Marks a single notification as read for a user.
 */
function mark_in_app_notification_read(PDO $pdo, int $userId, int $notifId, ?string $role = null): bool
{
    try {
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : ($_SESSION['role'] ?? 'tenant');
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND role = ? AND user_id = ?');
        return $stmt->execute([$notifId, $targetRole, $userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Marks all notifications as read for a user.
 */
function mark_all_in_app_notifications_read(PDO $pdo, int $userId, ?string $role = null): bool
{
    try {
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : ($_SESSION['role'] ?? 'tenant');
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE role = ? AND user_id = ?');
        return $stmt->execute([$targetRole, $userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Clears/removes all notifications for a user.
 */
function clear_user_in_app_notifications(PDO $pdo, int $userId, ?string $role = null): bool
{
    try {
        $targetRole = in_array($role, ['tenant', 'landlord', 'admin'], true) ? $role : ($_SESSION['role'] ?? 'tenant');
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE role = ? AND user_id = ?');
        return $stmt->execute([$targetRole, $userId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Returns the count of pending booking requests for a landlord's dorms.
 */
function get_landlord_pending_requests_count(PDO $pdo, int $landlordId): int
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            WHERE p.landlord_id = ? AND b.status = 'pending'
        ");
        $stmt->execute([$landlordId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}
