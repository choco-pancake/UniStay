<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (string)($_SESSION['user_id'] ?? '');
$landlordProfile = get_authenticated_profile($pdo, $userId);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);
$landlordVerif = get_landlord_verification($pdo, $userId);
$isLandlordVerified = ($landlordVerif['status'] ?? '') === 'verified';
require_once __DIR__ . '/../config/notifications.php';
$landlordNotifications = get_user_in_app_notifications($pdo, $userId, 100);

$actionMsg = null;
$actionErr = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['booking_action'], $_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $action = trim((string)$_POST['booking_action']);

    if (!$isLandlordVerified) {
        $actionErr = 'Landlord Verification Required: You must submit a valid government ID in Settings and have your account verified by an administrator before managing booking requests.';
    } else {
        $chkStmt = $pdo->prepare('
            SELECT b.id, b.room_id, b.tenant_id, b.status, u.first_name, u.last_name, p.name AS prop_name, r.name AS room_name,
                   l.facebook_link AS landlord_fb
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.id = p.landlord_id
            JOIN tenants u ON u.id = b.tenant_id
            WHERE b.id = ? AND p.landlord_id = ?
            LIMIT 1
        ');
        $chkStmt->execute([$bookingId, $userId]);
        $targetBooking = $chkStmt->fetch();

        if (!$targetBooking) {
            $actionErr = 'Booking record not found or does not belong to your dorms.';
        } else {
            $tenantName = trim(($targetBooking['first_name'] ?? '') . ' ' . ($targetBooking['last_name'] ?? '')) ?: 'Student';
            $propName = (string)($targetBooking['prop_name'] ?? 'Dormitory');
            $roomSuffix = !empty($targetBooking['room_name']) ? " (Room {$targetBooking['room_name']})" : "";
            $tenantUserId = (string)$targetBooking['tenant_id'];

            if ($action === 'approve') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'approved', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                if (!empty($targetBooking['room_id'])) {
                    $rId = (int)$targetBooking['room_id'];
                    $stCap = $pdo->prepare("SELECT occupancy, (SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status = 'approved') AS active_count FROM rooms WHERE id = ?");
                    $stCap->execute([$rId, $rId]);
                    $capRow = $stCap->fetch(PDO::FETCH_ASSOC);
                    $isFull = $capRow && ((int)$capRow['active_count'] >= (int)$capRow['occupancy']);
                    $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")
                        ->execute([$isFull ? 'occupied' : 'available', $rId]);
                }
                $actionMsg = "Booking request from {$tenantName} has been approved!";
                require_once __DIR__ . '/../config/notifications.php';

                // Send in-app notification to tenant immediately
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'approval',
                    'Booking Request Approved! 🎉',
                    "Great news! Your booking request for {$propName}{$roomSuffix} has been approved by the landlord. You can now chat directly with your landlord to coordinate check-in requirements.",
                    $bookingId,
                    'tenant'
                );

                // Asynchronously dispatch SMTP notification emails in the background
                async_send_booking_decision_emails($bookingId, 'approved');
            } elseif ($action === 'reject' || $action === 'decline') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'declined', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                if ($targetBooking['status'] === 'approved' && !empty($targetBooking['room_id'])) {
                    $rId = (int)$targetBooking['room_id'];
                    $stCap = $pdo->prepare("SELECT occupancy, (SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status = 'approved') AS active_count FROM rooms WHERE id = ?");
                    $stCap->execute([$rId, $rId]);
                    $capRow = $stCap->fetch(PDO::FETCH_ASSOC);
                    $isFull = $capRow && ((int)$capRow['active_count'] >= (int)$capRow['occupancy']);
                    $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")
                        ->execute([$isFull ? 'occupied' : 'available', $rId]);
                }
                $actionMsg = "Booking request from {$tenantName} has been declined.";
                require_once __DIR__ . '/../config/notifications.php';

                // Send in-app notification to tenant immediately
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'cancellation',
                    'Booking Request Declined',
                    "Your booking request for {$propName}{$roomSuffix} was declined by the landlord.",
                    $bookingId,
                    'tenant'
                );

                // Asynchronously dispatch SMTP notification emails in the background
                async_send_booking_decision_emails($bookingId, 'declined');
            } elseif ($action === 'end_tenancy') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                if (!empty($targetBooking['room_id'])) {
                    $rId = (int)$targetBooking['room_id'];
                    $stCap = $pdo->prepare("SELECT occupancy, (SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status = 'approved') AS active_count FROM rooms WHERE id = ?");
                    $stCap->execute([$rId, $rId]);
                    $capRow = $stCap->fetch(PDO::FETCH_ASSOC);
                    $isFull = $capRow && ((int)$capRow['active_count'] >= (int)$capRow['occupancy']);
                    $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")
                        ->execute([$isFull ? 'occupied' : 'available', $rId]);
                }
                $actionMsg = "Tenancy for {$tenantName} has ended.";
                require_once __DIR__ . '/../config/notifications.php';
                add_in_app_notification(
                    $pdo,
                    (string)$targetBooking['tenant_id'],
                    'booking',
                    'Tenancy Ended',
                    "Your tenancy for {$propName}{$roomSuffix} has ended.",
                    $bookingId,
                    'tenant'
                );
            }
        }
    }

    // Return instant JSON response if request was initiated via AJAX / fetch
    $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => empty($actionErr),
            'action' => $action,
            'booking_id' => $bookingId,
            'message' => $actionMsg ?: $actionErr,
            'status' => ($action === 'approve' ? 'approved' : ($action === 'reject' || $action === 'decline' ? 'declined' : 'cancelled'))
        ]);
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if ($statusFilter === 'rejected') $statusFilter = 'declined';
$validStatuses = ['all', 'pending', 'approved', 'active', 'declined', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}
$highlightBookingId = (int)($_GET['highlight'] ?? $_GET['booking_id'] ?? 0);

$sql = '
    SELECT b.id, b.status, b.created_at, b.room_id, b.tenant_id,
           u.code AS tenant_code, u.first_name, u.middle_name, u.last_name, u.email, u.email AS personal_email, u.phone_number, u.facebook_link,
           u.university, u.university AS school, u.emergency_name, u.emergency_relationship, u.emergency_phone,
           u.profile_photo AS user_photo, u.profile_photo, u.school_id, u.gov_id,
           u.verification_status AS tenant_verification, u.feedback AS tenant_feedback,
           p.name AS property_name, p.nearest_uni AS prop_university, p.address AS prop_address,
           r.name AS room_name, r.occupancy AS room_type, r.occupancy AS capacity, r.total_rent, r.rent_per_head, r.deposit, r.amenities
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN dorms p ON p.id = r.dorm_id
    JOIN tenants u ON u.id = b.tenant_id
    WHERE p.landlord_id = ?
';
$sql .= ' ORDER BY b.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll() ?: [];

// Pre-calculate active/approved occupants per room for dynamic rent per head
$occStmt = $pdo->prepare('
    SELECT room_id, COUNT(*) AS occ_count
    FROM bookings
    WHERE status IN ("approved", "active")
    GROUP BY room_id
');
$occStmt->execute();
$roomOccupants = [];
foreach ($occStmt->fetchAll() as $row) {
    $roomOccupants[(int)$row['room_id']] = (int)$row['occ_count'];
}

foreach ($bookings as &$b) {
    $rId = (int)($b['room_id'] ?? 0);
    $activeOccupants = $roomOccupants[$rId] ?? 0;
    $b['active_occupants'] = $activeOccupants;

    $rawCap = (int)preg_replace('/[^\d]/', '', (string)($b['capacity'] ?? $b['room_type'] ?? '1'));
    $cap = $rawCap > 0 ? $rawCap : 1;
    $b['parsed_capacity'] = $cap;

    $totRent = (float)($b['total_rent'] ?? 0);
    $rentHead = (float)($b['rent_per_head'] ?? 0);
    if ($totRent <= 0 && $rentHead > 0) {
        $totRent = $rentHead * $cap;
    }
    $b['computed_total_rent'] = $totRent;

    // Dynamic rent per head formula: total room rent divided by occupants (or capacity if 0 occupants)
    $divisor = $activeOccupants > 0 ? $activeOccupants : $cap;
    $b['dynamic_rent_per_head'] = $divisor > 0 ? round($totRent / $divisor, 2) : $rentHead;
    $b['rent'] = $b['dynamic_rent_per_head'];

    // Resolve tenant avatar and document paths
    $tAvatar = tenant_card_avatar($b);
    $b['resolved_avatar'] = $tAvatar['photo'];
    $b['avatar_initial'] = $tAvatar['initial'];
    $b['full_name'] = $tAvatar['name'];
    $b['resolved_school_id'] = !empty($b['school_id']) ? resolve_user_profile_photo_url($b['school_id'], null, 'tenant') : '';
    $b['resolved_gov_id'] = !empty($b['gov_id']) ? resolve_user_profile_photo_url($b['gov_id'], null, 'tenant') : '';
}
unset($b);

$matchingBookingsCount = 0;
foreach ($bookings as $b) {
    $st = strtolower((string)$b['status']);
    if ($statusFilter === 'all' || $statusFilter === $st || ($statusFilter === 'approved' && $st === 'active') || ($statusFilter === 'active' && $st === 'approved')) {
        $matchingBookingsCount++;
    }
}

// Status counts
$countsStmt = $pdo->prepare('
    SELECT b.status, COUNT(*) AS cnt
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN dorms p ON p.id = r.dorm_id
    WHERE p.landlord_id = ?
    GROUP BY b.status
');
$countsStmt->execute([$userId]);
$counts = ['all' => 0, 'approved' => 0, 'active' => 0, 'pending' => 0, 'declined' => 0, 'cancelled' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $s = strtolower((string)$row['status']);
    if ($s === 'active') {
        $s = 'approved';
    } elseif ($s === 'rejected') {
        $s = 'declined';
    }
    if (isset($counts[$s])) {
        $counts[$s] += (int)$row['cnt'];
    }
    $counts['all'] += (int)$row['cnt'];
}
$counts['active'] = $counts['approved'];

function tenant_card_avatar(array $b): array
{
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)($b['email'] ?? ($b['personal_email'] ?? 'Tenant'));
    }
    $initial = strtoupper(substr($name, 0, 1));
    $photo = resolve_user_profile_photo_url(
        $b['profile_photo'] ?? null,
        $b['user_photo'] ?? null,
        'tenant'
    );
    return ['name' => $name, 'initial' => $initial, 'photo' => $photo];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requests · UniStay Landlord</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=41">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<link rel="stylesheet" href="../assets/css/settings.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/settings.css'); ?>">
<link rel="stylesheet" href="../assets/css/confirm-modal.css?v=1">
<script src="../assets/js/theme.js"></script>
<script>
function showBottomToast(titleText, msgText, isError = false) {
    let toast = document.getElementById('notification-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'notification-toast';
        toast.className = 'notification-toast';
        toast.setAttribute('role', 'status');
        toast.style.cursor = 'pointer';
        toast.addEventListener('click', () => {
            toast.classList.remove('is-visible');
        });
        document.body.append(toast);
    }
    toast.style.borderLeft = isError ? '4px solid #dc2626' : (titleText.includes('Declined') ? '4px solid #dc2626' : '4px solid #16a34a');
    toast.style.pointerEvents = 'auto';

    const titleEl = document.createElement('strong');
    titleEl.textContent = titleText;
    const msgEl = document.createElement('span');
    msgEl.textContent = msgText;

    toast.replaceChildren(titleEl, msgEl);
    requestAnimationFrame(() => {
        toast.classList.add('is-visible');
    });

    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, 4500);
}
</script>
<style>
.topbar h1 {
    font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
    font-weight: 700 !important;
    color: #5f3425 !important;
    letter-spacing: -0.6px;
}

.requests-page-title {
    font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
    font-weight: 700 !important;
    color: #5f3425 !important;
    font-size: 1.6rem;
    margin: 0 0 4px;
}

.shell-orbs {
    position: absolute;
    inset: 0;
    overflow: hidden;
    border-radius: inherit;
    pointer-events: none;
    z-index: 0;
}
.shell-orbs i {
    position: absolute;
    display: block;
    border-radius: 50%;
    filter: blur(64px);
}
.shell-orbs i:nth-child(1) { width: 360px; height: 360px; background: #F3E2AC; opacity: .3; top: -110px; left: 14%; }
.shell-orbs i:nth-child(2) { width: 430px; height: 430px; background: #E4CDAF; opacity: .3; bottom: -150px; right: 6%; }
.shell-orbs i:nth-child(3) { width: 320px; height: 320px; background: #FFBA95; opacity: .2; top: 36%; left: 50%; }

.tenants-container {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding-bottom: 40px;
}

.topbar {
    position: relative;
    z-index: 1000;
    height: auto;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    border-bottom: 1px solid #ebd9cb;
    padding: 0;
    gap: 0;
}

.topbar-main-row {
    height: 76px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 0 4px;
}

/* Full-Width Categories/Filter Bar matching Dorms */
.dorms-stay-filters,
.tenants-tabs {
    position: relative;
    display: flex;
    width: 100%;
    margin: 0;
    padding: 0;
    border-top: 1px solid #ebd9cb;
    border-bottom: 1px solid #ebd9cb;
    border-radius: 0;
    background: rgba(253, 250, 245, 0.7);
    overflow-x: auto;
    scrollbar-width: none;
    z-index: 2;
}

.dorms-stay-filters::-webkit-scrollbar,
.tenants-tabs::-webkit-scrollbar {
    display: none;
}

.stay-filter-btn,
.tab-btn {
    flex: 1 1 0;
    min-width: 0;
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: auto;
    min-height: 48px;
    padding: 14px 12px;
    border: 0;
    border-bottom: 3px solid transparent;
    border-top: none;
    border-radius: 0;
    background: transparent;
    color: #7a4b3a;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.94rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    transition: color 0.2s ease, background-color 0.2s ease;
}

.stay-pill {
    position: absolute;
    bottom: 0;
    top: auto;
    left: 0;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: #7a4b3a;
    box-shadow: none;
    z-index: 3;
    pointer-events: none;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.stay-filter-btn:hover:not(.is-active):not(.active),
.tab-btn:hover:not(.active):not(.is-active) {
    color: #5f3425;
    background: rgba(122, 75, 58, 0.04);
}

.stay-filter-btn.is-active,
.stay-filter-btn.active,
.tab-btn.active,
.tab-btn.is-active {
    background: rgba(122, 75, 58, 0.02) !important;
    color: #5f3425 !important;
    font-weight: 700;
    border-bottom-color: transparent;
    border-top-color: transparent;
    box-shadow: none !important;
}

@media (prefers-reduced-motion: reduce) {
    .stay-pill {
        transition: none !important;
    }
}

.stay-filter-count,
.tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    background: #ebd9cb;
    color: #5f3425;
    transition: background 0.2s ease, color 0.2s ease;
}

.stay-filter-btn.is-active .stay-filter-count,
.stay-filter-btn.active .stay-filter-count,
.tab-btn.active .tab-count,
.tab-btn.is-active .tab-count {
    background: #7a4b3a;
    color: #ffffff;
}

/* View Listed Properties button: enlarges on hover and click */
.btn-view-properties,
.btn-booking-details {
    font: 600 0.88rem 'DM Sans', sans-serif;
    padding: 0 22px;
    height: 42px;
    border-radius: 8px;
    border: 1px solid var(--brown, #7a4b3a);
    background: var(--brown, #7a4b3a);
    color: #ffffff !important;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(122, 75, 58, 0.18);
    transform: scale(1);
    transform-origin: center;
    transition: transform 0.18s cubic-bezier(0.2, 0, 0, 1), background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    margin-top: 14px;
}

.btn-view-properties:hover,
.btn-view-properties:focus-visible,
.btn-booking-details:hover,
.btn-booking-details:focus-visible {
    color: #ffffff !important;
    background: var(--brown-dark, #5f3425);
    border-color: var(--brown-dark, #5f3425);
    transform: scale(1.08);
    box-shadow: 0 8px 22px rgba(122, 75, 58, 0.24);
}

.btn-view-properties:active,
.btn-booking-details:active {
    color: #ffffff !important;
    background: var(--brown-darker, #432419);
    border-color: var(--brown-darker, #432419);
    transform: scale(1.12);
    box-shadow: 0 10px 28px rgba(122, 75, 58, 0.3);
}

/* Plain Empty State (No white card container) */
.plain-empty-state {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 60px 24px 80px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #8c7569;
}

.plain-empty-icon {
    font-size: 3rem;
    margin-bottom: 12px;
    display: block;
}

.plain-empty-title {
    margin: 0 0 6px;
    color: #292524;
    font-family: 'Fraunces', Georgia, serif;
    font-size: 20px;
    font-weight: 700;
}

.plain-empty-desc {
    margin: 0 0 16px;
    font-size: 0.92rem;
    color: #8c7569;
    max-width: 420px;
}

.tenants-table-card {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: 0 6px 18px rgba(92, 62, 48, 0.08);
    overflow: hidden;
}

.tenants-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.tenants-table th {
    background: #fbf5ec;
    padding: 16px 20px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--line);
}

.tenants-table td {
    padding: 18px 20px;
    border-bottom: 1px solid #f3ece4;
    font-size: 0.92rem;
    color: var(--text-dark);
}

.tenants-table tr:last-child td {
    border-bottom: none;
}

.tenants-table tr:hover td {
    background: #fdfaf6;
}

.tenant-cell-user {
    display: flex;
    align-items: center;
    gap: 12px;
}

.tenant-avatar-sm {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #fbf4ee;
    border: 1px solid var(--line);
    color: var(--head);
    font-weight: 700;
    font-size: 14px;
    display: grid;
    place-items: center;
    overflow: hidden;
    flex-shrink: 0;
}

.tenant-avatar-sm img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tenant-meta-name {
    font-weight: 700;
    color: var(--head);
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.tenant-meta-email {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.btn-action-approve {
    padding: 8px 18px;
    background: #16a34a;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s, transform 0.18s, opacity 0.18s;
}

.btn-action-approve:hover:not(.is-loading) {
    background: #15803d;
    transform: translateY(-1px);
}

.btn-action-reject {
    padding: 8px 18px;
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s, transform 0.18s, opacity 0.18s;
}

.btn-action-reject:hover:not(.is-loading) {
    background: #b91c1c;
    transform: translateY(-1px);
}

.btn-action-approve.is-loading,
.btn-action-reject.is-loading,
.btn-action-end.is-loading {
    opacity: 0.85;
    cursor: wait;
    pointer-events: none;
    transform: none !important;
}

.btn-action-end {
    padding: 8px 18px;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    transform-origin: center;
}

.btn-action-end:hover:not(.is-loading) {
    background: #e5e7eb;
    color: #111827;
    transform: scale(1.06);
    box-shadow: 0 4px 12px rgba(55, 65, 81, 0.12);
}

.btn-action-end:active:not(.is-loading) {
    transform: scale(1.10);
    background: #d1d5db;
    box-shadow: 0 6px 16px rgba(55, 65, 81, 0.18);
}

.booking-status-tag.rejected,
.booking-status-tag.declined {
    background: #fee2e2;
    color: #991b1b;
}

.nav-badge-pill {
    background: #dc2626;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 999px;
    margin-left: auto;
}

.highlight-row {
    /* Clean highlight with smooth scroll into view - no yellow pulse or orange border */
}

.badge-verified-student {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #dcfce7;
    color: #15803d;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}

/* Action View Details Button */
.btn-action-details {
    padding: 7px 14px;
    background: #ffffff;
    color: #7a4b3a;
    border: 1px solid #d8c2b4;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.18s ease;
}
.btn-action-details:hover {
    background: #fdf8f4;
    border-color: #7a4b3a;
    color: #5c3527;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(122, 75, 58, 0.12);
}

.tenant-clickable-name {
    cursor: pointer;
    transition: color 0.15s ease;
}
.tenant-clickable-name:hover {
    color: #7a4b3a;
    text-decoration: underline;
}

.btn-tenant-view-details {
    background: #fbf5ef;
    color: #7a4b3a;
    border: 1px solid #ebd9cb;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 0.74rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.16s ease;
    line-height: 1.3;
}
.btn-tenant-view-details:hover {
    background: #7a4b3a;
    color: #ffffff;
    border-color: #7a4b3a;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(122, 75, 58, 0.15);
}

/* Slide-in Tenant Profile Drawer */
.tenant-drawer-overlay {
    position: fixed;
    inset: 0;
    background: rgba(41, 37, 36, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 9998;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.28s ease, visibility 0.28s ease;
}
.tenant-drawer-overlay.is-open {
    opacity: 1;
    visibility: visible;
}

.tenant-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 620px;
    max-width: 95vw;
    background: #ffffff;
    box-shadow: -10px 0 40px rgba(0, 0, 0, 0.18);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    border-left: 1px solid #ebd9cb;
}
.tenant-drawer.is-open {
    transform: translateX(0);
}

.tenant-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 28px;
    border-bottom: 1px solid #f0e6e0;
    background: #fdfaf7;
    flex-shrink: 0;
}
.tenant-drawer-heading {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.25rem;
    font-weight: 700;
    color: #3b2820;
    margin: 0;
}
.tenant-drawer-close {
    background: #f4ece6;
    border: none;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #5c3527;
    cursor: pointer;
    transition: background 0.18s, color 0.18s, transform 0.18s;
}
.tenant-drawer-close:hover {
    background: #ebd9cb;
    color: #292524;
    transform: rotate(90deg);
}

.tenant-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px 28px 40px;
}
</style>
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js?v=11"></script>
<script src="../assets/js/tenant/profile-menu.js?v=31"></script>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>

<!-- Landlord Sidebar -->
<aside class="sidebar" aria-label="UniStay Landlord Portal">
<a href="dashboard.php" class="brand" title="UniStay Home"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo"><span class="brand-name">UniStay</span></a>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="dorms.php" title="Dorms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
<a href="tenants.php" title="Requests" aria-current="page">
  <svg aria-hidden="true"><use href="#user-icon"/></svg>
  <span class="nav-label">Requests</span>
  <?php if ($counts['pending'] > 0): ?>
    <span class="nav-badge-pill"><?= $counts['pending'] ?></span>
  <?php endif; ?>
</a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
<div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
<header class="topbar">
  <div class="topbar-main-row">
    <h1>Requests</h1>
    <div class="profile">
      <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme"></button>
      <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
      <button class="avatar" type="button" aria-label="<?= htmlspecialchars($landlordProfile['name'], ENT_QUOTES, 'UTF-8') ?> — open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($landlordProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
        <?php if (!empty($landlordProfile['photo'])): ?>
          <img src="<?= htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
          <span style="display:none;"><?= htmlspecialchars($landlordProfile['initials'] ?? 'L', ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
          <?= htmlspecialchars($landlordProfile['initials'] ?? 'L', ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </button>
      <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
        <h2 id="popover-title">Notifications</h2>
        <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
        <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
        <a class="view-all" id="view-all" href="notifications.php?from=tenants" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
      </section>
    </div>
  </div>

  <!-- Filter Tabs: Rectangular Pills matching Dorms -->
  <div class="dorms-stay-filters tenants-tabs" role="tablist" aria-label="Filter by request status">
      <span class="stay-pill" aria-hidden="true"></span>
      <a href="tenants.php?status=all" data-status="all" class="stay-filter-btn tab-btn <?= $statusFilter === 'all' ? 'is-active active' : '' ?>">
          <span>All Bookings</span>
          <span class="stay-filter-count tab-count"><?= $counts['all'] ?></span>
      </a>
      <a href="tenants.php?status=pending" data-status="pending" class="stay-filter-btn tab-btn <?= $statusFilter === 'pending' ? 'is-active active' : '' ?>">
          <span>Pending Requests</span>
          <span class="stay-filter-count tab-count"><?= $counts['pending'] ?></span>
      </a>
      <a href="tenants.php?status=approved" data-status="approved" class="stay-filter-btn tab-btn <?= ($statusFilter === 'approved' || $statusFilter === 'active') ? 'is-active active' : '' ?>">
          <span>Approved Residents</span>
          <span class="stay-filter-count tab-count"><?= $counts['approved'] ?></span>
      </a>
      <a href="tenants.php?status=declined" data-status="declined" class="stay-filter-btn tab-btn <?= ($statusFilter === 'declined' || $statusFilter === 'rejected') ? 'is-active active' : '' ?>">
          <span>Declined Residents</span>
          <span class="stay-filter-count tab-count"><?= $counts['declined'] ?></span>
      </a>
      <a href="tenants.php?status=cancelled" data-status="cancelled" class="stay-filter-btn tab-btn <?= $statusFilter === 'cancelled' ? 'is-active active' : '' ?>">
          <span>Ended Tenancies</span>
          <span class="stay-filter-count tab-count"><?= $counts['cancelled'] ?></span>
      </a>
  </div>
</header>

<main id="dashboard-page" tabindex="-1" style="padding-top:20px;">
    <div class="tenants-container">
        <?php if (!$isLandlordVerified): ?>
        <div class="verification-notice-banner" style="background:#fffbeb; border:1px solid #fde68a; border-radius:14px; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:24px;">⚠️</span>
                <div>
                    <strong style="color:#92400e; font-size:0.96rem; display:block;">Landlord Verification Required (Status: <?= htmlspecialchars(ucfirst($landlordVerif['status'] ?? 'Unverified'), ENT_QUOTES, 'UTF-8') ?>)</strong>
                    <span style="color:#78350f; font-size:0.88rem;">You must complete your registration form and submit a valid government ID in Settings to be verified before approving or rejecting booking requests.</span>
                </div>
            </div>
            <a href="settings.php" style="background:#b45309; color:#fff; font-weight:700; font-size:0.88rem; padding:8px 16px; border-radius:8px; text-decoration:none;">Go to Settings</a>
        </div>
        <?php endif; ?>

        <?php if ($actionMsg || $actionErr): 
            $tTitle = $actionErr ? 'Notice' : (stripos($actionMsg, 'approved') !== false ? 'Booking Approved! 🎉' : (stripos($actionMsg, 'declined') !== false ? 'Booking Request Declined' : 'Booking Update'));
            $isErr = (bool)$actionErr || (stripos($actionMsg, 'declined') !== false);
        ?>
        <script>
        window.addEventListener('DOMContentLoaded', () => {
            showBottomToast(
                <?= json_encode($tTitle) ?>,
                <?= json_encode($actionMsg ?: $actionErr) ?>,
                <?= $isErr ? 'true' : 'false' ?>
            );
        });
        </script>
        <?php endif; ?>

        <!-- Plain Empty State (No white card container, rendered directly on cream card) -->
        <div id="tenants-empty-state" class="plain-empty-state" style="<?= ($matchingBookingsCount > 0 ? 'display:none;' : 'display:flex;') ?>">
            <span class="plain-empty-icon">&#128203;</span>
            <h3 class="plain-empty-title">No requests or bookings found</h3>
            <p class="plain-empty-desc">There are no student booking requests or tenancies matching this filter.</p>
            <a href="dorms.php" class="btn-view-properties">View Listed Dorms</a>
        </div>

        <!-- Table Card (Only visible when records exist) -->
        <div class="tenants-table-card" id="tenants-table-card" style="<?= ($matchingBookingsCount === 0 ? 'display:none;' : '') ?>">
            <table class="tenants-table" id="tenants-table">
                <thead>
                    <tr>
                        <th>Student Tenant</th>
                        <th>Dorm</th>
                        <th>Room &amp; Rent</th>
                        <th>University / School</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tenants-table-body">
                    <?php foreach ($bookings as $b):
                        $t = tenant_card_avatar($b);
                        $st = strtolower((string)$b['status']);
                        $bId = (int)$b['id'];
                        $isHighlighted = ($highlightBookingId === $bId);
                        $propLabel = (string)$b['property_name'];
                        $declineMsg = $t['name'] . ' will be notified that their request for ' . $propLabel . ' was declined. This action cannot be undone.';
                        $endMsg = 'The tenancy of ' . $t['name'] . ' at ' . $propLabel . ' will be marked as ended and moved to Ended Tenancies.';
                        $isVisible = ($statusFilter === 'all' || $statusFilter === $st || ($statusFilter === 'approved' && $st === 'active') || ($statusFilter === 'active' && $st === 'approved'));
                    ?>
                        <tr id="booking-row-<?= $bId ?>" class="<?= $isHighlighted ? 'highlight-row' : '' ?>" data-status="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" style="<?= $isVisible ? '' : 'display:none;' ?>">
                                <td>
                                    <div class="tenant-cell-user">
                                        <div class="tenant-avatar-sm" onclick="openTenantDrawer(<?= $bId ?>)" style="cursor:pointer;" title="Click to view tenant details">
                                            <?php if ($t['photo'] !== ''): ?>
                                                <img src="<?= htmlspecialchars($t['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <span><?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="tenant-meta-name">
                                                <span role="button" tabindex="0" onclick="openTenantDrawer(<?= $bId ?>)" class="tenant-clickable-name" title="Click to view tenant details"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span style="font-size:0.75rem; color:#7a4b3a; font-weight:700;">#<?= htmlspecialchars($b['tenant_code'] ?? ('T' . $b['tenant_id']), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (($b['tenant_verification'] ?? '') === 'verified'): ?>
                                                    <span class="badge-verified-student" title="Verified Student Credentials">✓ Verified</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="tenant-meta-email">
                                                <?= htmlspecialchars((string)($b['email'] ?? ($b['personal_email'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($b['phone_number'])): ?>
                                                    · <?= htmlspecialchars((string)$b['phone_number'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="tenant-meta-action" style="margin-top: 5px;">
                                                <button type="button" class="btn-tenant-view-details" onclick="openTenantDrawer(<?= $bId ?>)" title="View full student profile and application">View Details</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$b['property_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['room_name'] ?? 'Room'), ENT_QUOTES, 'UTF-8') ?>
                                    <span style="display:block; font-size:0.78rem; color:var(--text-muted);">
                                        <?= htmlspecialchars((string)($b['room_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($b['rent'])): ?>
                                            · ₱<?= number_format((float)$b['rent'], 2) ?>/mo
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['school'] ?: $b['university']), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="booking-status-tag <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <div class="booking-actions-group" style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">
                                        <?php if ($st === 'pending'): ?>
                                            <?php if ($isLandlordVerified): ?>
                                                <form method="POST" style="display:inline-flex; gap:6px; margin:0;">
                                                    <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                                    <button type="submit" name="booking_action" value="approve" class="btn-action-approve" title="Approve booking">Approve</button>
                                                    <button type="submit" name="booking_action" value="reject" class="btn-action-reject" title="Decline booking"
                                                        data-confirm-crumb="UniStay &bull; Booking Request"
                                                        data-confirm-title="Decline this booking request?"
                                                        data-confirm-message="<?= htmlspecialchars($declineMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-confirm-ok="Yes, decline"
                                                        data-confirm-cancel="Keep request">Decline</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#b45309; font-size:0.8rem; font-weight:600;">Verification Required</span>
                                            <?php endif; ?>
                                        <?php elseif ($st === 'active' || $st === 'approved'): ?>
                                            <?php if ($isLandlordVerified): ?>
                                                <form method="POST" style="display:inline-flex; margin:0;">
                                                    <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                                    <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" title="End Tenancy"
                                                        data-confirm-crumb="UniStay &bull; Tenancy"
                                                        data-confirm-title="End this student tenancy?"
                                                        data-confirm-message="<?= htmlspecialchars($endMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-confirm-ok="Yes, end tenancy"
                                                        data-confirm-cancel="Keep tenancy">End Tenancy</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#166534; font-size:0.8rem; font-weight:600;">Active Resident</span>
                                            <?php endif; ?>
                                        <?php elseif ($st === 'declined' || $st === 'rejected'): ?>
                                            <span style="color:#991b1b; font-size:0.8rem; font-weight:600;">Declined</span>
                                        <?php elseif ($st === 'cancelled'): ?>
                                            <span style="color:#6b7280; font-size:0.8rem;">Tenancy Ended</span>
                                        <?php else: ?>
                                            <span style="color:#9c8577; font-size:0.8rem;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
        </div>
    </div>
</main>
</div>

<!-- Slide-in Drawer for Tenant Profile & Booking Details -->
<div id="tenant-drawer-overlay" class="tenant-drawer-overlay" onclick="closeTenantDrawer()"></div>
<aside id="tenant-drawer" class="tenant-drawer" aria-labelledby="tenant-drawer-title" aria-hidden="true">
    <div class="tenant-drawer-header">
        <div style="display:flex; align-items:center; gap:10px;">
            <h2 id="tenant-drawer-title" class="tenant-drawer-heading">Student Tenant Details</h2>
            <span id="drawer-status-pill" class="booking-status-tag"></span>
        </div>
        <button type="button" class="tenant-drawer-close" onclick="closeTenantDrawer()" aria-label="Close drawer">✕</button>
    </div>
    <div id="tenant-drawer-body" class="tenant-drawer-body">
        <!-- Rendered dynamically via openTenantDrawer(bookingId) -->
    </div>
</aside>

<!-- Lightbox Modal for ID Preview -->
<div id="idLightbox" class="id-lightbox-overlay" onclick="closeLightbox(event)">
    <div class="id-lightbox-content">
        <button type="button" class="id-lightbox-close" onclick="closeLightboxDirect()" aria-label="Close full view">&times;</button>
        <img id="lightboxImg" class="id-lightbox-img" src="" alt="Full ID Preview">
        <p id="lightboxCaption" style="color: #ffffff; margin-top: 12px; font-weight: 600; font-size: 1rem;"></p>
    </div>
</div>

<script id="bookings-profile-data" type="application/json">
<?= json_encode($bookings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script id="notification-data" type="application/json">
<?= json_encode($landlordNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (string)($_SESSION['user_id'] ?? ''), 'role' => 'landlord']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/landlord-notifications.js?v=6"></script>
<script src="../assets/js/confirm-modal.js?v=1"></script>
<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Lightbox Modal Functions
function openLightbox(src, caption) {
    const overlay = document.getElementById('idLightbox');
    const img = document.getElementById('lightboxImg');
    const cap = document.getElementById('lightboxCaption');
    if (overlay && img) {
        img.src = src;
        if (cap) cap.innerText = caption || 'ID Preview';
        overlay.classList.remove('is-closing');
        void overlay.offsetWidth;
        overlay.classList.add('is-open');
    }
}

function closeLightboxDirect() {
    const overlay = document.getElementById('idLightbox');
    if (!overlay || !overlay.classList.contains('is-open')) return;
    overlay.classList.add('is-closing');
    setTimeout(() => {
        overlay.classList.remove('is-open', 'is-closing');
    }, 240);
}

function closeLightbox(e) {
    if (e.target && e.target.id === 'idLightbox') {
        closeLightboxDirect();
    }
}

// Tenant Profile Drawer Functions
let currentBookingsData = [];
try {
    const dataEl = document.getElementById('bookings-profile-data');
    if (dataEl) currentBookingsData = JSON.parse(dataEl.textContent || '[]');
} catch (e) {
    console.error('Failed to parse bookings profile data', e);
}

function openTenantDrawer(bookingId) {
    const booking = currentBookingsData.find(b => parseInt(b.id, 10) === parseInt(bookingId, 10));
    if (!booking) return;

    const drawer = document.getElementById('tenant-drawer');
    const overlay = document.getElementById('tenant-drawer-overlay');
    const body = document.getElementById('tenant-drawer-body');
    const statusPill = document.getElementById('drawer-status-pill');

    const st = (booking.status || 'pending').toLowerCase();
    if (statusPill) {
        statusPill.className = 'booking-status-tag ' + st;
        statusPill.textContent = st.charAt(0).toUpperCase() + st.slice(1);
    }

    const escapeHtml = (str) => {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const formatPhp = (num) => '₱' + (parseFloat(num) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    let createdDate = booking.created_at || 'Recently';
    try {
        const d = new Date(booking.created_at);
        if (!isNaN(d.getTime())) {
            createdDate = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
    } catch (e) {}

    let avatarInner = '';
    if (booking.resolved_avatar) {
        avatarInner = `<img src="${escapeHtml(booking.resolved_avatar)}" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                       <span style="display:none;">${escapeHtml(booking.avatar_initial || 'U')}</span>`;
    } else {
        avatarInner = `<span>${escapeHtml(booking.avatar_initial || 'U')}</span>`;
    }

    const vStatus = (booking.tenant_verification || 'unverified').toLowerCase();
    let verifBadgeHtml = '';
    let verifFeedbackBanner = '';

    if (vStatus === 'verified') {
        verifBadgeHtml = '<span class="badge-verified-student" style="font-size:0.75rem;">✓ Verified Student</span>';
        verifFeedbackBanner = `
            <div class="tenant-verification-feedback" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:12px 16px; margin-bottom:20px; color:#166534; display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;">✓</span>
                <div>
                    <strong style="display:block; font-size:0.92rem;">Student Identity Verified</strong>
                    <span style="font-size:0.84rem;">Identification credentials have been approved by the administrator.</span>
                </div>
            </div>`;
    } else if (vStatus === 'pending' || (vStatus === 'unverified' && (booking.school_id || booking.gov_id))) {
        verifBadgeHtml = '<span style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:700;">⏳ Verification Pending</span>';
        verifFeedbackBanner = `
            <div class="tenant-verification-feedback" style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;">⏳</span>
                <div>
                    <strong style="color:#92400e; font-size:0.92rem; display:block;">Verification Pending Review</strong>
                    <span style="color:#78350f; font-size:0.84rem;">Documents submitted; administrator review in progress.</span>
                </div>
            </div>`;
    } else if (vStatus === 'declined' || vStatus === 'rejected') {
        verifBadgeHtml = '<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:700;">✕ Needs Attention</span>';
        verifFeedbackBanner = `
            <div class="tenant-verification-feedback" style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px;">
                <span style="font-size:20px; line-height:1; color:#dc2626;">✕</span>
                <div>
                    <strong style="color:#991b1b; font-size:0.92rem; display:block;">Verification Needs Attention</strong>
                    <span style="color:#b91c1c; font-size:0.84rem;">Feedback: ${escapeHtml(booking.tenant_feedback || 'Your documents could not be verified.')}</span>
                </div>
            </div>`;
    } else {
        verifBadgeHtml = '<span style="background:#f3f4f6; color:#6b7280; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:700;">Unverified</span>';
        verifFeedbackBanner = `
            <div class="tenant-verification-feedback" style="background:#fcf8f5; border:1px dashed #ebd9cb; border-radius:12px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;">ℹ</span>
                <div>
                    <strong style="color:#63331b; font-size:0.92rem; display:block;">No Verification Documents</strong>
                    <span style="color:#78665c; font-size:0.84rem;">Student has not uploaded verification identification documents.</span>
                </div>
            </div>`;
    }

    let doc1Html = '';
    if (booking.resolved_school_id) {
        const fn1 = booking.school_id.split('/').pop();
        doc1Html = `
            <div class="id-document-card">
                <div class="id-document-meta">
                    <span class="id-document-title">School ID</span>
                    <button type="button" class="id-document-view-btn" onclick="openLightbox('${escapeHtml(booking.resolved_school_id)}', 'School ID - ${escapeHtml(booking.full_name)}')">View full photo ↗</button>
                </div>
                <div class="id-document-thumb" onclick="openLightbox('${escapeHtml(booking.resolved_school_id)}', 'School ID - ${escapeHtml(booking.full_name)}')">
                    <img src="${escapeHtml(booking.resolved_school_id)}" alt="School ID Preview">
                </div>
                <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all; margin-top: 6px; display: block;">${escapeHtml(fn1)}</span>
            </div>`;
    } else {
        doc1Html = `
            <div class="id-document-card">
                <div class="id-document-meta">
                    <span class="id-document-title">School ID</span>
                </div>
                <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem; min-height: 140px; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 28px;">📷</span>
                    <span>No School ID uploaded</span>
                </div>
            </div>`;
    }

    let doc2Html = '';
    if (booking.resolved_gov_id) {
        const fn2 = booking.gov_id.split('/').pop();
        doc2Html = `
            <div class="id-document-card">
                <div class="id-document-meta">
                    <span class="id-document-title">Government ID</span>
                    <button type="button" class="id-document-view-btn" onclick="openLightbox('${escapeHtml(booking.resolved_gov_id)}', 'Government ID - ${escapeHtml(booking.full_name)}')">View full photo ↗</button>
                </div>
                <div class="id-document-thumb" onclick="openLightbox('${escapeHtml(booking.resolved_gov_id)}', 'Government ID - ${escapeHtml(booking.full_name)}')">
                    <img src="${escapeHtml(booking.resolved_gov_id)}" alt="Government ID Preview">
                </div>
                <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all; margin-top: 6px; display: block;">${escapeHtml(fn2)}</span>
            </div>`;
    } else {
        doc2Html = `
            <div class="id-document-card">
                <div class="id-document-meta">
                    <span class="id-document-title">Government ID</span>
                </div>
                <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem; min-height: 140px; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 28px;">📷</span>
                    <span>No Government ID uploaded</span>
                </div>
            </div>`;
    }

    let fbDisplay = '<span style="color: #9a8c84;">Not specified</span>';
    if (booking.facebook_link) {
        const cleanFb = booking.facebook_link.replace(/^https?:\/\//i, '');
        fbDisplay = `<a href="${escapeHtml(booking.facebook_link)}" target="_blank" rel="noopener noreferrer" style="color: #7a4b3a; font-weight: 600; text-decoration: underline; word-break: break-all;">${escapeHtml(cleanFb)} ↗</a>`;
    }

    const activeOcc = parseInt(booking.active_occupants || 0, 10);
    const capacity = parseInt(booking.parsed_capacity || 1, 10);
    const occNote = activeOcc > 0
        ? `${activeOcc} active occupant${activeOcc > 1 ? 's' : ''} (Room Capacity: ${capacity})`
        : `0 current occupants (Capacity: ${capacity})`;

    let actionsFooter = '';
    const declineMsg = `${escapeHtml(booking.full_name)} will be notified that their request for ${escapeHtml(booking.property_name)} was declined. This action cannot be undone.`;
    const endMsg = `The tenancy of ${escapeHtml(booking.full_name)} at ${escapeHtml(booking.property_name)} will be marked as ended and moved to Ended Tenancies.`;

    if (st === 'pending') {
        actionsFooter = `
            <div style="margin-top:28px; padding-top:20px; border-top:1px solid #ebd9cb; display:flex; gap:10px; justify-content:flex-end;">
                <form method="POST" style="display:inline-flex; gap:10px; margin:0;">
                    <input type="hidden" name="booking_id" value="${booking.id}">
                    <button type="submit" name="booking_action" value="approve" class="btn-action-approve" style="padding:10px 22px; font-size:0.9rem;">Approve Booking</button>
                    <button type="submit" name="booking_action" value="reject" class="btn-action-reject" style="padding:10px 22px; font-size:0.9rem;"
                        data-confirm-crumb="UniStay &bull; Booking Request"
                        data-confirm-title="Decline this booking request?"
                        data-confirm-message="${escapeHtml(declineMsg)}"
                        data-confirm-ok="Yes, decline"
                        data-confirm-cancel="Keep request">Decline</button>
                </form>
            </div>`;
    } else if (st === 'approved' || st === 'active') {
        actionsFooter = `
            <div style="margin-top:28px; padding-top:20px; border-top:1px solid #ebd9cb; display:flex; gap:10px; justify-content:flex-end;">
                <form method="POST" style="display:inline-flex; margin:0;">
                    <input type="hidden" name="booking_id" value="${booking.id}">
                    <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" style="padding:10px 22px; font-size:0.9rem;"
                        data-confirm-crumb="UniStay &bull; Tenancy"
                        data-confirm-title="End this student tenancy?"
                        data-confirm-message="${escapeHtml(endMsg)}"
                        data-confirm-ok="Yes, end tenancy"
                        data-confirm-cancel="Keep tenancy">End Tenancy</button>
                </form>
            </div>`;
    }

    body.innerHTML = `
        <div class="profile-view-header" style="margin-bottom: 20px; padding-bottom: 18px;">
            <div class="profile-view-identity">
                <div class="profile-view-avatar">
                    ${avatarInner}
                </div>
                <div>
                    <h2 class="profile-view-name">${escapeHtml(booking.full_name)}</h2>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                        <span class="profile-role-badge">TENANT #${escapeHtml(booking.tenant_code || ('T' + booking.tenant_id))}</span>
                        ${verifBadgeHtml}
                    </div>
                </div>
            </div>
        </div>

        <h3 class="settings-section-title">Personal Information</h3>
        <div class="settings-grid-3">
            <div class="info-display-group">
                <span class="info-display-label">First Name</span>
                <div class="info-display-val">${escapeHtml(booking.first_name || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Middle Name</span>
                <div class="info-display-val">${escapeHtml(booking.middle_name || 'None')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Last Name</span>
                <div class="info-display-val">${escapeHtml(booking.last_name || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Phone Number</span>
                <div class="info-display-val">${escapeHtml(booking.phone_number || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Facebook Profile</span>
                <div class="info-display-val">${fbDisplay}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Email Address</span>
                <div class="info-display-val">${escapeHtml(booking.email || booking.personal_email || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">University / College</span>
                <div class="info-display-val">${escapeHtml(booking.university || booking.school || 'Not specified')}</div>
            </div>
        </div>

        <h3 class="settings-section-title">Emergency Contact</h3>
        <div class="settings-grid-3">
            <div class="info-display-group">
                <span class="info-display-label">Contact Name</span>
                <div class="info-display-val">${escapeHtml(booking.emergency_name || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Relationship</span>
                <div class="info-display-val">${escapeHtml(booking.emergency_relationship || 'Not specified')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Emergency Phone</span>
                <div class="info-display-val">${escapeHtml(booking.emergency_phone || 'Not specified')}</div>
            </div>
        </div>

        <h3 class="settings-section-title">Accommodation &amp; Stay Details</h3>
        <div class="settings-grid-3">
            <div class="info-display-group">
                <span class="info-display-label">Dorm Property</span>
                <div class="info-display-val">${escapeHtml(booking.property_name || 'Dorm')}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Room &amp; Occupancy</span>
                <div class="info-display-val">${escapeHtml(booking.room_name || 'Room')} (${escapeHtml(booking.room_type || '')})</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Active Occupants</span>
                <div class="info-display-val">${escapeHtml(occNote)}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Dynamic Rent / Head</span>
                <div class="info-display-val" style="color:#16a34a; font-weight:700;">${formatPhp(booking.dynamic_rent_per_head)} / mo</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Total Room Rent</span>
                <div class="info-display-val">${formatPhp(booking.computed_total_rent)} / mo</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Security Deposit</span>
                <div class="info-display-val">${formatPhp(booking.deposit || 0)}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Requested On</span>
                <div class="info-display-val">${escapeHtml(createdDate)}</div>
            </div>
            <div class="info-display-group">
                <span class="info-display-label">Booking Status</span>
                <div class="info-display-val" style="text-transform:capitalize;">${escapeHtml(st)}</div>
            </div>
        </div>

        <h3 class="settings-section-title">Uploaded Identification</h3>
        ${verifFeedbackBanner}
        <div class="settings-grid-2">
            ${doc1Html}
            ${doc2Html}
        </div>

        ${actionsFooter}
    `;

    if (overlay) overlay.classList.add('is-open');
    if (drawer) {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
    }
    document.body.style.overflow = 'hidden';
}

function closeTenantDrawer() {
    const drawer = document.getElementById('tenant-drawer');
    const overlay = document.getElementById('tenant-drawer-overlay');
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (overlay) overlay.classList.remove('is-open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const lb = document.getElementById('idLightbox');
        if (lb && lb.classList.contains('is-open')) {
            closeLightboxDirect();
        } else {
            closeTenantDrawer();
        }
    }
});

// Moving rectangular pill indicator matching dorms
const stayTabs = document.querySelector('.dorms-stay-filters');
const stayPill = stayTabs ? stayTabs.querySelector('.stay-pill') : null;
let stayPillReady = false;

function placeStayPill(instant) {
    const active = stayTabs ? stayTabs.querySelector('.stay-filter-btn.is-active, .stay-filter-btn.active') : null;
    if (!stayPill || !active) return;
    const noAnim = instant || !stayPillReady;
    if (noAnim) stayPill.style.transition = 'none';
    stayPill.style.width = active.offsetWidth + 'px';
    stayPill.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    if (noAnim) {
        void stayPill.offsetWidth;
        stayPill.style.transition = '';
        stayPillReady = true;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const hl = document.querySelector('.highlight-row');
    if (hl) {
        setTimeout(() => {
            hl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
    }
    placeStayPill(true);
});

window.addEventListener('load', () => placeStayPill(true));
window.addEventListener('resize', () => placeStayPill(true));
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => placeStayPill(true));
}

function applyStatusFilter(status) {
    const rows = document.querySelectorAll('#tenants-table-body tr');
    let visibleCount = 0;
    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        const isMatch = (status === 'all' || rowStatus === status || (status === 'approved' && rowStatus === 'active') || (status === 'active' && rowStatus === 'approved'));
        if (isMatch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const tableCard = document.getElementById('tenants-table-card');
    const table = document.getElementById('tenants-table');
    const emptyState = document.getElementById('tenants-empty-state');
    if (emptyState) {
        if (visibleCount === 0) {
            if (tableCard) tableCard.style.display = 'none';
            if (table) table.style.display = 'none';
            emptyState.style.display = 'flex';
        } else {
            if (tableCard) tableCard.style.display = '';
            if (table) table.style.display = '';
            emptyState.style.display = 'none';
        }
    }
}

document.querySelectorAll('.stay-filter-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('.stay-filter-btn').forEach(b => {
            b.classList.remove('is-active', 'active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active', 'active');
        btn.setAttribute('aria-selected', 'true');
        
        placeStayPill();
        
        const status = btn.dataset.status || 'all';
        applyStatusFilter(status);

        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.history.replaceState(null, '', url.toString());
        }
    });
});

placeStayPill(true);
window.addEventListener('load', () => placeStayPill(true));
window.addEventListener('resize', () => placeStayPill(true));
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => placeStayPill(true));
}

function showBottomToast(titleText, msgText, isError = false) {
    let toast = document.getElementById('notification-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'notification-toast';
        toast.className = 'notification-toast';
        toast.setAttribute('role', 'status');
        toast.style.cursor = 'pointer';
        toast.addEventListener('click', () => {
            toast.classList.remove('is-visible');
        });
        document.body.append(toast);
    }
    toast.style.borderLeft = isError ? '4px solid #dc2626' : '4px solid #16a34a';
    toast.style.pointerEvents = 'auto';

    const titleEl = document.createElement('strong');
    titleEl.textContent = titleText;
    const msgEl = document.createElement('span');
    msgEl.textContent = msgText;

    toast.replaceChildren(titleEl, msgEl);
    requestAnimationFrame(() => {
        toast.classList.add('is-visible');
    });

    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, 4500);
}

// Instant AJAX Approve Handler
document.addEventListener('click', async (e) => {
    const approveBtn = e.target.closest('.btn-action-approve');
    if (!approveBtn || approveBtn.classList.contains('is-loading')) return;
    
    const form = approveBtn.closest('form');
    if (!form) return;

    const bookingIdInput = form.querySelector('input[name="booking_id"]');
    if (!bookingIdInput) return;
    const bookingId = bookingIdInput.value;

    e.preventDefault();
    approveBtn.textContent = 'Approving...';
    approveBtn.classList.add('is-loading');

    const formData = new FormData();
    formData.append('booking_id', bookingId);
    formData.append('booking_action', 'approve');
    formData.append('ajax', '1');

    try {
        const res = await fetch('tenants.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data && data.ok) {
            approveBtn.textContent = '✓ Approved!';
            approveBtn.classList.remove('is-loading');
            approveBtn.style.background = '#15803d';
            approveBtn.style.color = '#fff';
            approveBtn.style.borderColor = '#15803d';

            // 1. Update in-memory data
            const bIndex = currentBookingsData.findIndex(b => String(b.id) === String(bookingId));
            if (bIndex !== -1) {
                currentBookingsData[bIndex].status = 'approved';
            }

            // 2. Update table row
            const row = document.getElementById(`booking-row-${bookingId}`);
            if (row) {
                row.dataset.status = 'approved';
                const statusTag = row.querySelector('.booking-status-tag');
                if (statusTag) {
                    statusTag.className = 'booking-status-tag approved';
                    statusTag.textContent = 'approved';
                }
                const actionsGroup = row.querySelector('.booking-actions-group');
                if (actionsGroup) {
                    actionsGroup.innerHTML = `
                        <form method="POST" style="display:inline-flex; margin:0;">
                            <input type="hidden" name="booking_id" value="${bookingId}">
                            <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" title="End Tenancy"
                                data-confirm-crumb="UniStay &bull; Tenancy"
                                data-confirm-title="End this student tenancy?"
                                data-confirm-message="The tenancy will be marked as ended and moved to Ended Tenancies."
                                data-confirm-ok="Yes, end tenancy"
                                data-confirm-cancel="Keep tenancy">End Tenancy</button>
                        </form>
                    `;
                }

                // If currently filtered by pending requests, fade row out smoothly after visual feedback
                const currentTab = new URLSearchParams(window.location.search).get('status') || 'all';
                if (currentTab === 'pending') {
                    setTimeout(() => {
                        row.style.transition = 'all 0.35s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(10px)';
                        setTimeout(() => {
                            row.style.display = 'none';
                            placeStayPill(true);
                        }, 350);
                    }, 1100);
                }
            }

            // 3. Update drawer pill and footer if open
            const statusPill = document.getElementById('drawer-status-pill');
            if (statusPill) {
                statusPill.className = 'booking-status-tag approved';
                statusPill.textContent = 'Approved';
            }
            const drawerFooter = document.querySelector('.tenant-drawer-footer');
            if (drawerFooter) {
                drawerFooter.innerHTML = `
                    <form method="POST" style="display:flex; gap:10px; width:100%; justify-content:flex-end; margin:0;">
                        <input type="hidden" name="booking_id" value="${bookingId}">
                        <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" style="padding:10px 22px; font-size:0.9rem;"
                            data-confirm-crumb="UniStay &bull; Tenancy"
                            data-confirm-title="End this student tenancy?"
                            data-confirm-message="The tenancy will be marked as ended and moved to Ended Tenancies."
                            data-confirm-ok="Yes, end tenancy"
                            data-confirm-cancel="Keep tenancy">End Tenancy</button>
                    </form>
                `;
            }

            // 4. Update tab counts
            const pendingCountEl = document.querySelector('a[data-status="pending"] .tab-count');
            if (pendingCountEl) {
                const count = parseInt(pendingCountEl.textContent, 10);
                if (count > 0) pendingCountEl.textContent = count - 1;
            }
            const approvedCountEl = document.querySelector('a[data-status="approved"] .tab-count');
            if (approvedCountEl) {
                const count = parseInt(approvedCountEl.textContent, 10) || 0;
                approvedCountEl.textContent = count + 1;
            }

            showBottomToast('Booking Approved! 🎉', data.message || 'Booking request from student has been approved!');
        } else {
            showBottomToast('Notice', data.message || 'Could not approve booking.', true);
            approveBtn.textContent = 'Approve';
            approveBtn.classList.remove('is-loading');
        }
    } catch (err) {
        console.error('AJAX approve error, submitting normally', err);
        form.submit();
    }
});

// Styled confirmation modal for Decline / End Tenancy (replaces the browser alert)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-confirm-title]');
    if (!btn) return;
    e.preventDefault();

    const ok = await UniStayConfirm.open({
        crumb: btn.dataset.confirmCrumb,
        title: btn.dataset.confirmTitle,
        message: btn.dataset.confirmMessage,
        confirmText: btn.dataset.confirmOk,
        cancelText: btn.dataset.confirmCancel,
        danger: true
    });
    if (!ok) return;

    const form = btn.form;
    if (!form) return;

    const isDecline = btn.classList.contains('btn-action-reject');
    const isEnd = !isDecline;
    const actionVal = btn.value || (isDecline ? 'reject' : 'end_tenancy');
    const bookingIdInput = form.querySelector('input[name="booking_id"]');
    const bookingId = bookingIdInput ? bookingIdInput.value : null;

    btn.textContent = isDecline ? 'Declining...' : 'Ending...';
    btn.classList.add('is-loading');

    if (bookingId) {
        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('booking_action', actionVal);
        formData.append('ajax', '1');

        try {
            const res = await fetch('tenants.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data && data.ok) {
                // Update in memory
                const bIndex = currentBookingsData.findIndex(b => String(b.id) === String(bookingId));
                if (bIndex !== -1) {
                    currentBookingsData[bIndex].status = isDecline ? 'declined' : 'cancelled';
                }

                // Update table row
                const row = document.getElementById(`booking-row-${bookingId}`);
                if (row) {
                    row.dataset.status = isDecline ? 'declined' : 'cancelled';
                    const statusTag = row.querySelector('.booking-status-tag');
                    if (statusTag) {
                        statusTag.className = 'booking-status-tag ' + (isDecline ? 'declined' : 'cancelled');
                        statusTag.textContent = isDecline ? 'declined' : 'cancelled';
                    }
                    const actionsGroup = row.querySelector('.booking-actions-group');
                    if (actionsGroup) {
                        actionsGroup.innerHTML = isDecline
                            ? '<span style="color:#991b1b; font-size:0.8rem; font-weight:600;">Declined</span>'
                            : '<span style="color:#6b7280; font-size:0.8rem;">Tenancy Ended</span>';
                    }

                    // If filtered by pending or active, fade out
                    const currentTab = new URLSearchParams(window.location.search).get('status') || 'all';
                    if ((isDecline && currentTab === 'pending') || (isEnd && (currentTab === 'approved' || currentTab === 'active'))) {
                        setTimeout(() => {
                            row.style.transition = 'all 0.35s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'translateX(10px)';
                            setTimeout(() => {
                                row.style.display = 'none';
                                placeStayPill(true);
                            }, 350);
                        }, 900);
                    }
                }

                // Update drawer if open
                const statusPill = document.getElementById('drawer-status-pill');
                if (statusPill) {
                    statusPill.className = 'booking-status-tag ' + (isDecline ? 'declined' : 'cancelled');
                    statusPill.textContent = isDecline ? 'Declined' : 'Ended';
                }
                const drawerFooter = document.querySelector('.tenant-drawer-footer');
                if (drawerFooter) {
                    drawerFooter.innerHTML = '';
                }

                // Update tab counts
                if (isDecline) {
                    const pendingCountEl = document.querySelector('a[data-status="pending"] .tab-count');
                    if (pendingCountEl) {
                        const count = parseInt(pendingCountEl.textContent, 10);
                        if (count > 0) pendingCountEl.textContent = count - 1;
                    }
                    const declinedCountEl = document.querySelector('a[data-status="declined"] .tab-count');
                    if (declinedCountEl) {
                        const count = parseInt(declinedCountEl.textContent, 10) || 0;
                        declinedCountEl.textContent = count + 1;
                    }
                } else if (isEnd) {
                    const approvedCountEl = document.querySelector('a[data-status="approved"] .tab-count');
                    if (approvedCountEl) {
                        const count = parseInt(approvedCountEl.textContent, 10);
                        if (count > 0) approvedCountEl.textContent = count - 1;
                    }
                    const cancelledCountEl = document.querySelector('a[data-status="cancelled"] .tab-count');
                    if (cancelledCountEl) {
                        const count = parseInt(cancelledCountEl.textContent, 10) || 0;
                        cancelledCountEl.textContent = count + 1;
                    }
                }

                showBottomToast(isDecline ? 'Booking Request Declined' : 'Tenancy Ended', data.message || 'Updated successfully!', isDecline);
                return;
            }
        } catch (err) {
            console.error('AJAX action error, falling back to form submit', err);
        }
    }

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(btn);
    } else {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = btn.name;
        hidden.value = btn.value;
        form.appendChild(hidden);
        form.submit();
    }
});
</script>
</body>
</html>
