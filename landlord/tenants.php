<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (int)($_SESSION['user_id'] ?? 0);
$landlordProfile = get_authenticated_profile($pdo, $userId);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);
$landlordVerif = get_landlord_verification($pdo, $userId);
$isLandlordVerified = ($landlordVerif['status'] ?? '') === 'verified';
require_once __DIR__ . '/../config/notifications.php';
$landlordNotifications = get_user_in_app_notifications($pdo, $userId, 100);

$actionMsg = null;
$actionErr = null;

const CAPACITY_BY_TYPE = [
    'SINGLE'     => 1,
    'TWIN'       => 2,
    'QUAD'       => 4,
    'QUINTUPLE'  => 5,
    'SEXTUPLE'   => 6,
    'OCTUPLE'    => 8,
    'DECUPLE'    => 10,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['booking_action'], $_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $action = trim((string)$_POST['booking_action']);
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || isset($_POST['ajax']);

    if (!$isLandlordVerified) {
        $actionErr = 'Landlord Verification Required: You must submit a valid government ID in Settings and have your account verified by an administrator before managing booking requests.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $actionErr]);
            exit;
        }
    } else {
        $chkStmt = $pdo->prepare('
            SELECT b.id, b.user_id, b.status, b.room_id, u.first_name, u.last_name, p.name AS prop_name,
                   r.name AS room_name, r.type AS room_type, r.capacity AS room_capacity, r.occupants AS room_occupants, r.status AS room_status
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users u ON u.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ? AND p.landlord_id = ?
            LIMIT 1
        ');
        $chkStmt->execute([$bookingId, $userId]);
        $targetBooking = $chkStmt->fetch();

        if (!$targetBooking) {
            $actionErr = 'Booking record not found or does not belong to your properties.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => $actionErr]);
                exit;
            }
        } else {
            $tenantName = trim(($targetBooking['first_name'] ?? '') . ' ' . ($targetBooking['last_name'] ?? '')) ?: 'Student';
            $propName = (string)($targetBooking['prop_name'] ?? 'Dormitory');
            $roomSuffix = !empty($targetBooking['room_name']) ? " (Room {$targetBooking['room_name']})" : "";
            $tenantUserId = (int)$targetBooking['user_id'];
            $targetRoomId = !empty($targetBooking['room_id']) ? (int)$targetBooking['room_id'] : null;

            if ($action === 'approve') {
                // Strict Room Capacity Check
                if ($targetRoomId) {
                    $rmQ = $pdo->prepare('SELECT id, name, type, capacity, occupants, status FROM rooms WHERE id = ? FOR UPDATE');
                    $rmQ->execute([$targetRoomId]);
                    $rmData = $rmQ->fetch();
                    if ($rmData) {
                        $typeUp = strtoupper(trim((string)$rmData['type']));
                        $maxCap = (int)($rmData['capacity'] ?: (CAPACITY_BY_TYPE[$typeUp] ?? 1));
                        $currOcc = (int)$rmData['occupants'];

                        if ($currOcc >= $maxCap || $rmData['status'] === 'Occupied') {
                            $actionErr = "Cannot approve: Room {$rmData['name']} ({$rmData['type']}) is already at maximum capacity ({$currOcc}/{$maxCap} occupants) and closed.";
                            if ($isAjax) {
                                header('Content-Type: application/json; charset=UTF-8');
                                http_response_code(409);
                                echo json_encode(['ok' => false, 'error' => $actionErr]);
                                exit;
                            }
                        }
                    }
                }

                if (empty($actionErr)) {
                    $upd = $pdo->prepare("UPDATE bookings SET status = 'active', updated_at = NOW() WHERE id = ?");
                    $upd->execute([$bookingId]);

                    if ($targetRoomId) {
                        $newOcc = (int)($rmData['occupants'] ?? 0) + 1;
                        $pdo->prepare("UPDATE rooms SET occupants = ? WHERE id = ?")->execute([$newOcc, $targetRoomId]);
                        // If room reaches capacity, mark it Occupied and close it!
                        if ($newOcc >= $maxCap) {
                            $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?")->execute([$targetRoomId]);
                        }
                    }

                    $actionMsg = "Booking request from {$tenantName} has been approved! They are now an active resident.";
                    require_once __DIR__ . '/../config/notifications.php';
                    add_in_app_notification(
                        $pdo,
                        $tenantUserId,
                        'approval',
                        'Booking Request Approved! 🎉',
                        "Great news! Your booking request for {$propName}{$roomSuffix} has been approved by the landlord. You are now an active resident!",
                        $bookingId
                    );
                }
            } elseif ($action === 'reject') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                $actionMsg = "Booking request from {$tenantName} has been declined.";
                require_once __DIR__ . '/../config/notifications.php';
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'cancellation',
                    'Booking Request Declined',
                    "Your booking request for {$propName}{$roomSuffix} was declined by the landlord.",
                    $bookingId
                );
            } elseif ($action === 'end_tenancy') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                $actionMsg = "Tenancy for {$tenantName} has ended.";

                if ($targetRoomId) {
                    $rmQ = $pdo->prepare('SELECT id, name, type, capacity, occupants, status FROM rooms WHERE id = ? FOR UPDATE');
                    $rmQ->execute([$targetRoomId]);
                    $rmData = $rmQ->fetch();
                    if ($rmData) {
                        $typeUp = strtoupper(trim((string)$rmData['type']));
                        $maxCap = (int)($rmData['capacity'] ?: (CAPACITY_BY_TYPE[$typeUp] ?? 1));
                        $newOcc = max(0, (int)$rmData['occupants'] - 1);
                        $pdo->prepare("UPDATE rooms SET occupants = ? WHERE id = ?")->execute([$newOcc, $targetRoomId]);
                        // Reopen room to Available if spots are now free
                        if ($newOcc < $maxCap && $rmData['status'] === 'Occupied') {
                            $pdo->prepare("UPDATE rooms SET status = 'Available' WHERE id = ?")->execute([$targetRoomId]);
                        }
                    }
                }
            }

            // If AJAX request, return sub-100ms response immediately!
            if ($isAjax && empty($actionErr)) {
                $counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0];
                $allB = $pdo->prepare("SELECT b.status FROM bookings b JOIN properties p ON p.id = b.property_id WHERE p.landlord_id = ?");
                $allB->execute([$userId]);
                while ($row = $allB->fetch()) {
                    $stKey = strtolower((string)$row['status']);
                    if (isset($counts[$stKey])) $counts[$stKey]++;
                    $counts['all']++;
                }

                $newStatus = ($action === 'approve') ? 'active' : (($action === 'reject') ? 'rejected' : 'cancelled');

                if (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json; charset=UTF-8');
                header('Connection: close');
                ignore_user_abort(true);
                ob_start();
                echo json_encode([
                    'ok' => true,
                    'action' => $action,
                    'booking_id' => $bookingId,
                    'new_status' => $newStatus,
                    'message' => $actionMsg,
                    'counts' => $counts,
                    'pending_requests_count' => $counts['pending']
                ]);
                $contentSize = ob_get_length();
                header('Content-Length: ' . $contentSize);
                ob_end_flush();
                flush();

                // Background email delivery (non-blocking for landlord UI)
                require_once __DIR__ . '/../config/notifications.php';
                if ($action === 'approve') {
                    send_booking_decision_notification($pdo, $bookingId, 'active');
                    send_landlord_booking_decision_confirmation($pdo, $bookingId, 'active');
                } elseif ($action === 'reject') {
                    send_booking_decision_notification($pdo, $bookingId, 'rejected');
                    send_landlord_booking_decision_confirmation($pdo, $bookingId, 'rejected');
                }
                exit;
            }
        }
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$validStatuses = ['pending', 'active', 'all', 'rejected', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}
$highlightBookingId = (int)($_GET['highlight'] ?? $_GET['booking_id'] ?? 0);

// Fetch all distinct properties owned by this landlord for the property dropdown
$propStmt = $pdo->prepare('SELECT id, name FROM properties WHERE landlord_id = ? ORDER BY name ASC');
$propStmt->execute([$userId]);
$landlordProperties = $propStmt->fetchAll() ?: [];

// Fetch enriched bookings
$sql = '
    SELECT b.id, b.status, b.created_at, b.updated_at,
           u.id AS tenant_user_id, u.first_name, u.middle_name, u.last_name, u.personal_email, u.phone_number,
           u.school, u.photo AS user_photo, u.profile_photo, u.verification_status AS tenant_verification,
           u.emergency_name, u.emergency_relationship, u.emergency_phone,
           p.id AS property_id, p.name AS property_name, p.address AS property_address, p.university, p.description AS property_desc,
           r.id AS room_id, r.name AS room_name, r.type AS room_type, r.rent, r.deposit, r.capacity, r.occupants, r.status AS room_status, r.amenities
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN rooms r ON r.id = b.room_id
    WHERE p.landlord_id = ?
    ORDER BY b.id DESC
';

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll() ?: [];

// Status counts
$counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0];
foreach ($bookings as $b) {
    $st = strtolower((string)$b['status']);
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
    $counts['all']++;
}

function tenant_card_avatar(array $b): array
{
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)($b['personal_email'] ?? 'Student Tenant');
    }
    $words = preg_split('/\s+/', $name);
    $initial = '';
    if (!empty($words[0])) {
        $initial .= mb_substr($words[0], 0, 1, 'UTF-8');
    }
    if (count($words) > 1 && !empty($words[count($words) - 1])) {
        $initial .= mb_substr($words[count($words) - 1], 0, 1, 'UTF-8');
    }
    if ($initial === '') {
        $initial = 'T';
    }
    $initial = strtoupper($initial);

    $photo = resolve_user_profile_photo_url(
        $b['profile_photo'] ?? null,
        $b['user_photo'] ?? null,
        'tenant'
    );
    return ['name' => $name, 'initial' => $initial, 'photo' => $photo];
}

function format_duration_stay(string $status, string $createdAt): string
{
    $createdTime = strtotime($createdAt);
    if (!$createdTime) {
        $createdTime = time();
    }
    $now = time();
    $diffDays = max(0, (int)floor(($now - $createdTime) / 86400));
    $months = max(1, (int)round($diffDays / 30.44));

    if ($status === 'pending') {
        if ($diffDays === 0) {
            return 'Requested today';
        }
        return "Requested {$diffDays} " . ($diffDays === 1 ? 'day' : 'days') . ' ago';
    }
    if ($status === 'rejected') {
        return 'Request declined';
    }
    if ($status === 'cancelled') {
        return "Stay ended ({$months} " . ($months === 1 ? 'month' : 'months') . ")";
    }
    return "{$months} " . ($months === 1 ? 'month' : 'months') . " and counting";
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requests · UniStay Landlord</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400;1,9..40,600&family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700;1,9..144,600;1,9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/common.css?v=32">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<link rel="stylesheet" href="../assets/css/confirm-modal.css?v=<?= filemtime(__DIR__ . '/../assets/css/confirm-modal.css') ?>">
<style>
/* UniStay Theme Harmonization */
:root {
  --line: #ebd9cb;
  --cream: #fdfaf5;
  --brown: #7a4b3a;
  --brown-light: #98614b;
  --brown-dark: #5f3425;
  --brown-darker: #452618;
  --text-dark: #3b312c;
  --muted: #8b7467;
  --green: #15803d;
  --green-bg: #dcfce7;
  --green-border: #bbf7d0;
  --amber: #92400e;
  --amber-bg: #fef3c7;
  --amber-border: #fde68a;
  --rose: #991b1b;
  --rose-bg: #fee2e2;
  --rose-border: #fecaca;
}

body {
  font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
  color: var(--text-dark);
}

.topbar h1 {
  font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
  font-weight: 700 !important;
  font-size: 32px !important;
  color: var(--brown-dark) !important;
  letter-spacing: -0.6px;
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
  gap: 20px;
  padding-bottom: 56px;
  max-width: 1240px;
}

/* Page Header */
.tenants-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
  margin-bottom: 2px;
}
.tenants-title-group h2 {
  font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
  font-size: 26px;
  font-weight: 700;
  margin: 0 0 6px;
  color: var(--brown-dark);
  letter-spacing: -0.4px;
}
.tenants-title-group p {
  margin: 0;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  color: var(--muted);
}
.pending-alert-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--amber-bg);
  border: 1px solid var(--amber-border);
  color: var(--amber);
  padding: 8px 18px;
  border-radius: 999px;
  font-family: 'DM Sans', sans-serif;
  font-weight: 700;
  font-size: 13px;
  box-shadow: 0 2px 8px rgba(180, 83, 9, 0.12);
}

/* ---------- TOOLBAR & RECTANGULAR STAY FILTERS ---------- */
.requests-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin: 6px 0 2px;
}

/* Rectangular pill filters matching dorms & properties */
.dorms-stay-filters,
.tenants-tabs {
  position: relative;
  display: flex;
  width: max-content;
  max-width: 100%;
  gap: 4px;
  margin: 0 0 22px;
  padding: 6px;
  border-radius: 14px;
  background: #EFEBE7;
  overflow-x: auto;
  scrollbar-width: none;
  border-bottom: none;
}
.dorms-stay-filters::-webkit-scrollbar,
.tenants-tabs::-webkit-scrollbar {
  display: none;
}

.stay-filter-btn {
  position: relative;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 16px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: var(--brown);
  font-family: 'DM Sans', sans-serif;
  font-size: 0.88rem;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
  transition: color 0.25s ease, background-color 0.25s ease;
}
.stay-filter-btn:hover:not(.is-active) {
  color: var(--brown-dark);
  background: rgba(122, 75, 58, 0.08);
}
.stay-filter-btn.is-active {
  background: transparent !important;
  color: #ffffff !important;
  box-shadow: none !important;
}

.stay-pill {
  position: absolute;
  top: 6px;
  left: 0;
  width: 0;
  height: 40px;
  border-radius: 10px;
  background: var(--brown);
  box-shadow: 0 2px 8px rgba(122, 75, 58, 0.22);
  z-index: 0;
  pointer-events: none;
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.stay-filter-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 0.74rem;
  font-weight: 700;
  background: rgba(122, 75, 58, 0.12);
  color: var(--brown);
  transition: background 0.25s ease, color 0.25s ease;
}
.stay-filter-btn.is-active .stay-filter-count {
  background: rgba(255, 255, 255, 0.24);
  color: #ffffff;
}

.toolbar-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

/* Matching UniStay .um-search & .um-select */
.um-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-start;
  gap: 12px;
  margin: 22px 0 16px;
}
.um-toolbar-left {
  display: inline-flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 12px;
  flex: 0 0 auto;
  max-width: 100%;
}
.um-search-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  flex: 0 1 360px;
  min-width: 220px;
  height: 44px;
  padding: 0 16px;
  border: 1px solid #d9bea6;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(84, 60, 48, 0.08);
  transition: border-color 0.18s, box-shadow 0.18s;
}
.um-search-wrap i {
  font-size: 14px;
  color: #8b7467;
}
.um-search-wrap input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: none;
  padding: 0;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  color: var(--brown-dark);
  outline: none;
}
.um-search-wrap input::placeholder {
  color: #8b7467;
  opacity: 1;
}
.um-search-wrap:focus-within {
  border-color: var(--brown);
  box-shadow: 0 2px 8px rgba(84, 60, 48, 0.12), 0 0 0 3px rgba(122, 75, 58, 0.14);
}

/* Custom Animated .um-select Dropdown Matching Tenant Dorms */
.um-select {
  position: relative;
}
.um-select-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  height: 44px;
  min-width: 240px;
  padding: 0 16px;
  border: 1px solid #d9bea6;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(84, 60, 48, 0.08);
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 500;
  color: #5f3425;
  cursor: pointer;
  transition: border-color 0.18s, box-shadow 0.18s;
}
.um-select-btn:hover {
  border-color: #cbb5a9;
}
.um-select-btn[aria-expanded="true"] {
  border-color: #7a4b3a;
  box-shadow: 0 2px 8px rgba(84, 60, 48, 0.12), 0 0 0 3px rgba(122, 75, 58, 0.14);
}
.um-select-caret {
  width: 17px;
  height: 17px;
  flex: none;
  stroke: currentColor;
  stroke-width: 2;
  color: #8b7467;
  transition: transform 0.2s ease;
}
.um-select-btn[aria-expanded="true"] .um-select-caret {
  transform: rotate(180deg);
}
.um-select-menu {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  z-index: 1200;
  min-width: 100%;
  margin: 0;
  padding: 6px;
  list-style: none;
  background: #fff;
  border: 1px solid #eadfd9;
  border-radius: 14px;
  box-shadow: 0 18px 44px rgba(60, 30, 20, 0.18);
  transform-origin: top left;
  animation: um-menu-in 0.22s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.um-select-menu[hidden] {
  display: none;
}
.um-select-menu.is-closing {
  animation: um-menu-out 0.18s cubic-bezier(0.2, 0.8, 0.2, 1) both;
  pointer-events: none;
}
.um-select-menu li {
  padding: 10px 14px;
  border-radius: 10px;
  color: #5f3425;
  font-family: 'DM Sans', sans-serif;
  font-size: 0.92rem;
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
}
.um-select-menu li:hover,
.um-select-menu li:focus {
  background: #f4ece7;
  outline: none;
}
.um-select-menu li[aria-selected="true"] {
  background: #7a4b3a;
  color: #fcf9f4;
  font-weight: 600;
}
@keyframes um-menu-in {
  from {
    opacity: 0;
    transform: translateY(-8px) scale(0.96);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
@keyframes um-menu-out {
  from {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to {
    opacity: 0;
    transform: translateY(-6px) scale(0.96);
  }
}

/* ---------- RESULT META ---------- */
.result-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 6px 2px 0;
  font-family: 'DM Sans', sans-serif;
  font-size: 13px;
  color: var(--muted);
}
.link-btn {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  font-size: 13px;
  font-weight: 600;
  color: var(--brown);
  transition: color 0.18s;
}
.link-btn:hover {
  color: var(--brown-dark);
  text-decoration: underline;
}

/* ---------- TENANT LIST & CARDS ---------- */
.tenant-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.tenant-card {
  display: flex;
  align-items: flex-start;
  gap: 20px;
  background: #ffffff;
  border: 1px solid var(--line);
  border-radius: 20px;
  padding: 22px 24px;
  transition: box-shadow 0.25s cubic-bezier(0.2, 0, 0, 1), transform 0.25s cubic-bezier(0.2, 0, 0, 1), border-color 0.25s ease;
  box-shadow: 0 4px 16px rgba(92, 62, 48, 0.06);
}
.tenant-card:hover {
  border-color: #cbb5a9;
  box-shadow: 0 12px 28px rgba(92, 62, 48, 0.12);
  transform: translateY(-2px);
}
.tenant-card.highlight-card {
  border-color: #d97706 !important;
  background: #fffdf5;
  box-shadow: 0 0 0 2px #d97706, 0 8px 24px rgba(217, 119, 6, 0.18);
  animation: cardPulse 2.5s ease-out;
}
@keyframes cardPulse {
  0% { background: #fef3c7; }
  50% { background: #fffbeb; }
  100% { background: #fffdf5; }
}

.tenant-avatar {
  width: 54px;
  height: 54px;
  flex-shrink: 0;
  border-radius: 14px;
  display: grid;
  place-items: center;
  background: #fbf4ee;
  color: var(--brown);
  font-family: 'DM Sans', sans-serif;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: .5px;
  overflow: hidden;
  border: 1px solid var(--line);
}
.tenant-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.tenant-main {
  flex: 1;
  min-width: 0;
}

.tenant-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.tenant-head h3 {
  margin: 0;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 18px;
  font-weight: 700;
  color: var(--brown-dark);
  letter-spacing: -0.2px;
}
.badge-verified-student {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--green-bg);
  border: 1px solid var(--green-border);
  color: var(--green);
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 999px;
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .3px;
  text-transform: uppercase;
}
.badge::before {
  content: "";
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}
.badge--present {
  background: var(--green-bg);
  color: var(--green);
  border: 1px solid var(--green-border);
}
.badge--leaving {
  background: var(--rose-bg);
  color: var(--rose);
  border: 1px solid var(--rose-border);
}
.badge--upcoming {
  background: var(--amber-bg);
  color: var(--amber);
  border: 1px solid var(--amber-border);
}

.tenant-sub {
  margin: 2px 0 0;
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.tenant-sub i {
  font-size: 12.5px;
  color: #9a8c84;
}
.tenant-sub .dot { color: #cbbbb0; }

.tenant-meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px 22px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed var(--line);
}
.meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.meta-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #9a8c84;
}
.meta-value {
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--brown-darker);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tenant-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex-shrink: 0;
  align-self: center;
  min-width: 140px;
}

/* ---------- SIGNATURE UNISTAY ENLARGING BUTTONS ---------- */
.btn-primary-action,
.btn-outline,
.btn-action-approve,
.btn-action-reject,
.btn-action-end,
.view-details {
  font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
  font-weight: 600 !important;
  font-size: 13px !important;
  padding: 9px 18px;
  border-radius: 10px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  transform: scale(1);
  transform-origin: center;
  transition: transform 0.18s cubic-bezier(0.2, 0, 0, 1), background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease !important;
}

/* Hover Enlarging */
.btn-primary-action:hover:not(.is-loading),
.btn-outline:hover:not(.is-loading),
.btn-action-approve:hover:not(.is-loading),
.btn-action-reject:hover:not(.is-loading),
.btn-action-end:hover:not(.is-loading),
.view-details:hover:not(.is-loading) {
  transform: scale(1.06) !important;
}

/* Active / Click Enlarging */
.btn-primary-action:active:not(.is-loading),
.btn-outline:active:not(.is-loading),
.btn-action-approve:active:not(.is-loading),
.btn-action-reject:active:not(.is-loading),
.btn-action-end:active:not(.is-loading),
.view-details:active:not(.is-loading) {
  transform: scale(1.10) !important;
}

/* Primary View / Action Button */
.btn-primary-action {
  background: var(--brown);
  border: 1px solid var(--brown);
  color: #ffffff !important;
  box-shadow: 0 2px 8px rgba(122, 75, 58, 0.2);
}
.btn-primary-action:hover:not(.is-loading) {
  background: var(--brown-dark);
  border-color: var(--brown-dark);
  box-shadow: 0 6px 18px rgba(122, 75, 58, 0.28);
}
.btn-primary-action:active:not(.is-loading) {
  background: var(--brown-darker);
  border-color: var(--brown-darker);
  box-shadow: 0 8px 24px rgba(122, 75, 58, 0.35);
}

/* Secondary / Outline Button */
.btn-outline,
.view-details {
  background: #ffffff;
  border: 1px solid #d9bea6;
  color: var(--brown);
  box-shadow: 0 2px 6px rgba(122, 75, 58, 0.06);
}
.btn-outline:hover:not(.is-loading),
.view-details:hover:not(.is-loading) {
  background: #fbf4ee;
  border-color: var(--brown);
  color: var(--brown-dark);
  box-shadow: 0 6px 18px rgba(122, 75, 58, 0.16);
}
.btn-outline:active:not(.is-loading),
.view-details:active:not(.is-loading) {
  background: #f7ede4;
  box-shadow: 0 8px 22px rgba(122, 75, 58, 0.22);
}

/* Approve Button */
.btn-action-approve {
  background: #16a34a;
  border: 1px solid #16a34a;
  color: #ffffff !important;
  box-shadow: 0 2px 8px rgba(22, 163, 74, 0.2);
}
.btn-action-approve:hover:not(.is-loading) {
  background: #15803d;
  border-color: #15803d;
  box-shadow: 0 6px 18px rgba(22, 163, 74, 0.28);
}
.btn-action-approve:active:not(.is-loading) {
  background: #166534;
  border-color: #166534;
  box-shadow: 0 8px 24px rgba(22, 163, 74, 0.35);
}

/* Decline Button */
.btn-action-reject {
  background: #ffffff;
  border: 1px solid #fca5a5;
  color: #dc2626;
  box-shadow: 0 2px 6px rgba(220, 38, 38, 0.08);
}
.btn-action-reject:hover:not(.is-loading) {
  background: #fee2e2;
  border-color: #f87171;
  color: #b91c1c;
  box-shadow: 0 6px 18px rgba(220, 38, 38, 0.2);
}
.btn-action-reject:active:not(.is-loading) {
  background: #fecaca;
  box-shadow: 0 8px 22px rgba(220, 38, 38, 0.26);
}

/* End Tenancy Button */
.btn-action-end {
  background: #ffffff;
  border: 1px solid #d1d5db;
  color: #4b5563;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}
.btn-action-end:hover:not(.is-loading) {
  background: #f3f4f6;
  border-color: #9ca3af;
  color: #111827;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1);
}
.btn-action-end:active:not(.is-loading) {
  background: #e5e7eb;
}

.btn-action-approve.is-loading,
.btn-action-reject.is-loading,
.btn-action-end.is-loading,
.btn-primary-action.is-loading {
  opacity: 0.85;
  cursor: wait;
  pointer-events: none;
  transform: none !important;
}

/* Room Occupancy Pill & Disabled Action States */
.occupancy-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 9px;
  border-radius: 999px;
  font-size: 0.74rem;
  font-weight: 700;
  background: rgba(122, 75, 58, 0.08);
  color: var(--brown);
  border: 1px solid rgba(122, 75, 58, 0.18);
  vertical-align: middle;
  line-height: 1.4;
}
.occupancy-pill.is-full {
  background: #fef2f2;
  color: #dc2626;
  border-color: #fecaca;
}
.occupancy-pill.is-available {
  background: #f0fdf4;
  color: #16a34a;
  border-color: #bbf7d0;
}

.btn-action-approve.is-disabled,
.btn-action-approve[disabled] {
  background: #e2e8f0 !important;
  border-color: #cbd5e1 !important;
  color: #94a3b8 !important;
  cursor: not-allowed !important;
  box-shadow: none !important;
  transform: none !important;
  pointer-events: auto !important;
}

/* ---------- EMPTY STATE ---------- */
.empty-state {
  display: none;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 64px 24px;
  background: #ffffff;
  border: 1px dashed var(--line);
  border-radius: 20px;
  box-shadow: 0 4px 16px rgba(92, 62, 48, 0.04);
}
.empty-state.is-visible { display: flex; }
.empty-icon {
  width: 64px;
  height: 64px;
  border-radius: 18px;
  background: #fbf4ee;
  color: #c2a795;
  display: grid;
  place-items: center;
  font-size: 24px;
  margin-bottom: 16px;
}
.empty-state h3 {
  margin: 0 0 6px;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 19px;
  font-weight: 700;
  color: var(--brown-dark);
}
.empty-state p {
  margin: 0 0 18px;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  color: var(--muted);
  max-width: 360px;
  line-height: 1.5;
}

/* ---------- SLIDE-OVER DRAWER ---------- */
.drawer-overlay {
  position: fixed;
  inset: 0;
  background: rgba(47, 32, 26, .45);
  backdrop-filter: blur(3px);
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s ease;
  z-index: 999;
}
.drawer-overlay.is-open {
  opacity: 1;
  pointer-events: auto;
}

.drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(560px, calc(100vw - 20px));
  background: #fdfaf5;
  box-shadow: -22px 0 54px rgba(45, 20, 10, 0.22);
  /* Semi-rounded rectangle: rounded top-left and bottom-left, sharp right */
  border-top-left-radius: 28px;
  border-bottom-left-radius: 28px;
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
  border-left: 1px solid rgba(217, 190, 166, 0.7);
  overflow: hidden;
  transform: translateX(100%);
  transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
  z-index: 1000;
  display: flex;
  flex-direction: column;
}
.drawer.is-open {
  transform: translateX(0);
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 22px 26px;
  border-bottom: 1px solid var(--line);
  flex-shrink: 0;
  background: #fff;
  border-top-left-radius: 28px;
  border-top-right-radius: 0;
}
.drawer-header h2 {
  margin: 0;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 21px;
  font-weight: 700;
  color: var(--brown-dark);
  letter-spacing: -0.3px;
}
.drawer-close {
  width: auto;
  height: auto;
  border-radius: 0;
  border: none;
  background: none;
  color: #7a4b3a;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  font-size: 26px;
  line-height: 1;
  box-shadow: none;
  flex-shrink: 0;
  margin-left: auto;
  transition: color 0.15s ease, transform 0.15s ease;
}

.drawer-close:hover {
  background: none;
  border: none;
  color: #3b2820;
  transform: scale(1.1);
  box-shadow: none;
}

.drawer-close:active {
  background: none;
  color: #1a0f0a;
  transform: scale(0.92);
}

/* Drawer Body with Landing Page Scrollbar */
.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 24px 26px 32px;
  display: flex;
  flex-direction: column;
  gap: 20px;
  scrollbar-width: thin;
  scrollbar-color: var(--brown, #7a4b3a) #F3E9DF;
}
.drawer-body::-webkit-scrollbar {
  width: 10px;
}
.drawer-body::-webkit-scrollbar-track {
  background: #F3E9DF;
  border-radius: 999px;
}
.drawer-body::-webkit-scrollbar-thumb {
  background: var(--brown, #7a4b3a);
  border: 2px solid #F3E9DF;
  border-radius: 999px;
}
.drawer-body::-webkit-scrollbar-thumb:hover {
  background: var(--brown-dark, #5f3425);
}

/* --- Hero card --- */
.drawer-hero {
  background: linear-gradient(135deg, #fbf4ee 0%, #fdfaf6 100%);
  border: 1px solid var(--line);
  border-radius: 20px;
  padding: 22px 22px 20px;
  box-shadow: 0 4px 14px rgba(122, 75, 58, 0.05);
}
.hero-top {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 14px;
}
.tenant-avatar.large {
  width: 64px;
  height: 64px;
  border-radius: 18px;
  font-family: 'DM Sans', sans-serif;
  font-size: 20px;
  font-weight: 700;
  background: #fff;
  border: 1px solid var(--line);
  color: var(--brown);
  box-shadow: 0 2px 8px rgba(122, 75, 58, .08);
}
.hero-info h3 {
  margin: 0 0 6px;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 20px;
  font-weight: 700;
  color: var(--brown-dark);
  letter-spacing: -0.2px;
}
.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 18px;
}
.hero-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(255, 255, 255, .95);
  border: 1px solid var(--line);
  font-family: 'DM Sans', sans-serif;
  font-size: 12.5px;
  font-weight: 500;
  color: #6b584d;
  box-shadow: 0 1px 4px rgba(70, 35, 20, 0.03);
}
.hero-chip i { font-size: 11.5px; color: var(--brown); }

.hero-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  padding-top: 16px;
  border-top: 1px dashed var(--line);
}
.hero-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(220, 205, 195, 0.5);
  border-radius: 12px;
  padding: 10px 12px;
  min-width: 0;
}
.hero-stat-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .6px;
  text-transform: uppercase;
  color: #9a8c84;
}
.hero-stat-value {
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: var(--brown-dark);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* --- Detail sections --- */
.detail-section {
  background: #ffffff;
  border: 1px solid var(--line);
  border-radius: 20px;
  padding: 22px 22px;
  box-shadow: 0 3px 12px rgba(60, 30, 20, 0.04);
}
.detail-section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f3e9df;
}
.detail-section-icon {
  width: 36px;
  height: 36px;
  border-radius: 11px;
  background: #fbf4ee;
  color: var(--brown);
  display: grid;
  place-items: center;
  font-size: 14px;
  flex-shrink: 0;
}
.detail-section-head h4 {
  margin: 0;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--brown-dark);
  letter-spacing: -0.2px;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.detail-item {
  background: #fdfbf9;
  border: 1px solid #f2e7df;
  border-radius: 12px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  transition: border-color 0.18s, background 0.18s;
}
.detail-item:hover {
  border-color: #d9bea6;
  background: #fffdfb;
}
.detail-item.full {
  grid-column: 1 / -1;
}
.detail-label {
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .5px;
  text-transform: uppercase;
  color: #8b7467;
}
.detail-value {
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-dark);
  word-break: break-word;
  line-height: 1.45;
}

/* --- Footer actions --- */
.drawer-footer {
  flex-shrink: 0;
  display: flex;
  gap: 10px;
  padding: 20px 26px;
  border-top: 1px solid var(--line);
  background: #fff;
  border-bottom-left-radius: 28px;
  border-bottom-right-radius: 0;
}
.drawer-footer form {
  display: flex;
  gap: 10px;
  width: 100%;
}
.drawer-footer .btn-action-approve,
.drawer-footer .btn-action-reject,
.drawer-footer .btn-action-end,
.drawer-footer .btn-outline {
  flex: 1;
  min-width: 0;
  padding: 11px 18px;
  font-size: 13.5px !important;
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

/* Responsive */
@media (max-width: 860px) {
  .requests-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .toolbar-controls {
    width: 100%;
  }
  .um-search-wrap {
    flex: 1;
    width: 100%;
  }
  .um-select-wrap,
  .um-select-wrap select {
    width: 100%;
  }
}
@media (max-width: 768px) {
  .tenant-card {
    flex-wrap: wrap;
    padding: 18px;
  }
  .tenant-actions {
    flex-direction: row;
    align-self: stretch;
    width: 100%;
    min-width: 0;
    border-top: 1px dashed var(--line);
    padding-top: 14px;
  }
  .tenant-actions form {
    display: flex;
    flex: 1;
    gap: 8px;
  }
  .tenant-actions .btn-outline,
  .tenant-actions .btn-action-approve,
  .tenant-actions .btn-action-reject,
  .tenant-actions .btn-action-end {
    flex: 1;
  }
}
@media (max-width: 480px) {
  .detail-grid { grid-template-columns: 1fr; }
  .hero-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js"></script>
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
<a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Properties</span></a>
<a href="tenants.php" title="Requests" aria-current="page">
  <svg aria-hidden="true"><use href="#user-icon"/></svg>
  <span class="nav-label">Requests</span>
  <span class="nav-badge-pill" id="sidebar-requests-badge" <?= ($counts['pending'] ?? 0) > 0 ? '' : 'style="display:none;"' ?>><?= ($counts['pending'] ?? 0) > 0 ? $counts['pending'] : '' ?></span>
</a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
<div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
<header class="topbar"><h1>Requests</h1><div class="profile">
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
</div></header>

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
            <a href="settings.php" class="btn-primary-action" style="text-decoration:none; background:#b45309; border-color:#b45309;">Go to Settings</a>
        </div>
        <?php endif; ?>

        <?php if ($actionMsg): ?>
        <div style="background:#dcfce7; border:1px solid #bbf7d0; color:#166534; padding:14px 18px; border-radius:14px; font-weight:600; font-size:0.92rem;">
            <i class="fas fa-check-circle" style="margin-right:6px;"></i><?= htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <?php if ($actionErr): ?>
        <div style="background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:14px 18px; border-radius:14px; font-weight:600; font-size:0.92rem;">
            <i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($actionErr, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <!-- TOOLBAR: SEARCH & PROPERTY DROPDOWN (ABOVE PILL FILTERS) -->
        <div class="um-toolbar">
            <div class="um-toolbar-left">
                <div class="um-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="tenantSearch" placeholder="Search records" autocomplete="off">
                </div>
                <div class="um-select" id="property-select">
                    <button type="button" class="um-select-btn is-placeholder" aria-haspopup="listbox" aria-expanded="false">
                        <span class="um-select-value">All properties</span>
                        <svg class="um-select-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <ul class="um-select-menu" role="listbox" aria-label="Filter by property" hidden>
                        <li role="option" data-value="all" aria-selected="true">All properties</li>
                        <?php foreach ($landlordProperties as $p): ?>
                            <li role="option" data-value="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>" aria-selected="false">
                                <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" id="propertyFilter" value="all">
                </div>
                <button type="button" class="link-btn" id="clearFilters" style="display:none;">Clear filters</button>
            </div>
        </div>

        <!-- RECTANGULAR STAY FILTER TABS (MATCHING DORMS VIEW) -->
        <div class="dorms-stay-filters tenants-tabs" role="tablist" aria-label="Filter bookings by status">
            <span class="stay-pill" aria-hidden="true"></span>
            <button type="button" class="stay-filter-btn <?= $statusFilter === 'all' ? 'is-active' : '' ?>" data-filter="all" role="tab" aria-selected="<?= $statusFilter === 'all' ? 'true' : 'false' ?>">
                <span>All</span>
                <span class="stay-filter-count" data-count="all"><?= $counts['all'] ?></span>
            </button>
            <button type="button" class="stay-filter-btn <?= $statusFilter === 'pending' ? 'is-active' : '' ?>" data-filter="pending" role="tab" aria-selected="<?= $statusFilter === 'pending' ? 'true' : 'false' ?>">
                <span>Pending Requests</span>
                <span class="stay-filter-count" data-count="pending"><?= $counts['pending'] ?></span>
            </button>
            <button type="button" class="stay-filter-btn <?= $statusFilter === 'active' ? 'is-active' : '' ?>" data-filter="active" role="tab" aria-selected="<?= $statusFilter === 'active' ? 'true' : 'false' ?>">
                <span>Active Residents</span>
                <span class="stay-filter-count" data-count="active"><?= $counts['active'] ?></span>
            </button>
            <button type="button" class="stay-filter-btn <?= $statusFilter === 'rejected' ? 'is-active' : '' ?>" data-filter="rejected" role="tab" aria-selected="<?= $statusFilter === 'rejected' ? 'true' : 'false' ?>">
                <span>Declined</span>
                <span class="stay-filter-count" data-count="rejected"><?= $counts['rejected'] ?></span>
            </button>
            <button type="button" class="stay-filter-btn <?= $statusFilter === 'cancelled' ? 'is-active' : '' ?>" data-filter="cancelled" role="tab" aria-selected="<?= $statusFilter === 'cancelled' ? 'true' : 'false' ?>">
                <span>Ended</span>
                <span class="stay-filter-count" data-count="cancelled"><?= $counts['cancelled'] ?></span>
            </button>
        </div>

        <!-- TENANT CARD LIST -->
        <section class="tenant-list" id="tenantList" aria-live="polite">
            <?php foreach ($bookings as $index => $b):
                $t = tenant_card_avatar($b);
                $st = strtolower((string)$b['status']);
                $bId = (int)$b['id'];
                $isHighlighted = ($highlightBookingId === $bId);
                $propLabel = (string)($b['property_name'] ?? 'Property');
                $rawRoom = trim((string)($b['room_name'] ?? ''));
                if ($rawRoom === '') {
                    $roomName = 'Standard';
                } elseif (stripos($rawRoom, 'room') === 0) {
                    $roomName = $rawRoom;
                } else {
                    $roomName = 'Room ' . $rawRoom;
                }
                $roomType = (string)($b['room_type'] ?? 'Standard');
                $moveInDate = date('M j, Y', strtotime($b['created_at']));
                $phoneVal = !empty($b['phone_number']) ? (string)$b['phone_number'] : 'Not provided';
                $emailVal = (string)($b['personal_email'] ?? 'Not provided');
                $schoolVal = (string)($b['school'] ?: $b['university'] ?: 'Student');
                $duration = format_duration_stay($st, (string)$b['created_at']);
                $rentFormatted = !empty($b['rent']) ? '₱' . number_format((float)$b['rent'], 2) : '—';
                $depositFormatted = !empty($b['deposit']) ? '₱' . number_format((float)$b['deposit'], 2) : '—';

                $statusBadgeClass = 'badge--upcoming';
                $statusBadgeLabel = 'Pending';
                if ($st === 'active') {
                    $statusBadgeClass = 'badge--present';
                    $statusBadgeLabel = 'Present';
                } elseif ($st === 'rejected') {
                    $statusBadgeClass = 'badge--leaving';
                    $statusBadgeLabel = 'Declined';
                } elseif ($st === 'cancelled') {
                    $statusBadgeClass = 'badge--leaving';
                    $statusBadgeLabel = 'Ended';
                }

                $typeUpper = strtoupper(trim((string)($b['room_type'] ?? 'SINGLE')));
                $maxCap = (int)(($b['capacity'] ?? 0) ?: (CAPACITY_BY_TYPE[$typeUpper] ?? 1));
                $occ = (int)($b['occupants'] ?? 0);
                $isRoomFull = ($occ >= $maxCap) || (($b['room_status'] ?? '') === 'Occupied');

                $declineMsg = $t['name'] . ' will be notified that their request for ' . $propLabel . ' was declined. This action cannot be undone.';
                $endMsg = 'The tenancy of ' . $t['name'] . ' at ' . $propLabel . ' will be marked as ended.';

                $bookingJson = json_encode([
                    'index' => $index,
                    'bookingId' => $bId,
                    'status' => $st,
                    'statusLabel' => $statusBadgeLabel,
                    'statusBadgeClass' => $statusBadgeClass,
                    'name' => $t['name'],
                    'initial' => $t['initial'],
                    'photo' => $t['photo'],
                    'isVerifiedStudent' => (($b['tenant_verification'] ?? '') === 'verified'),
                    'property' => $propLabel,
                    'propertyAddress' => (string)($b['property_address'] ?? 'Not specified'),
                    'room' => $roomName,
                    'roomType' => $roomType,
                    'rent' => $rentFormatted,
                    'deposit' => $depositFormatted,
                    'capacity' => (string)$maxCap,
                    'occupants' => (string)$occ,
                    'isRoomFull' => $isRoomFull,
                    'roomStatus' => (string)($b['room_status'] ?? 'Available'),
                    'amenities' => (string)($b['amenities'] ?? 'Standard amenities'),
                    'moveIn' => $moveInDate,
                    'duration' => $duration,
                    'phone' => $phoneVal,
                    'email' => $emailVal,
                    'school' => $schoolVal,
                    'emergencyName' => (string)($b['emergency_name'] ?? 'Not provided'),
                    'emergencyPhone' => (string)($b['emergency_phone'] ?? 'Not provided'),
                    'emergencyRelationship' => (string)($b['emergency_relationship'] ?? 'Guardian'),
                    'declineMsg' => $declineMsg,
                    'endMsg' => $endMsg,
                    'canManage' => $isLandlordVerified
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            ?>
            <article class="tenant-card <?= $isHighlighted ? 'highlight-card' : '' ?>"
                     id="booking-card-<?= $bId ?>"
                     data-index="<?= $index ?>"
                     data-status="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"
                     data-property="<?= htmlspecialchars($propLabel, ENT_QUOTES, 'UTF-8') ?>"
                     data-search="<?= htmlspecialchars(strtolower($t['name'] . ' ' . $roomName . ' ' . $propLabel . ' ' . $schoolVal . ' ' . $phoneVal . ' ' . $emailVal), ENT_QUOTES, 'UTF-8') ?>"
                     data-booking="<?= htmlspecialchars($bookingJson, ENT_QUOTES, 'UTF-8') ?>">

                <div class="tenant-avatar" aria-hidden="true">
                    <?php if (!empty($t['photo'])): ?>
                        <img src="<?= htmlspecialchars($t['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" onerror="this.style.display='none';this.parentElement.textContent='<?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?>';">
                    <?php else: ?>
                        <?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>

                <div class="tenant-main">
                    <div class="tenant-head">
                        <h3><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (($b['tenant_verification'] ?? '') === 'verified'): ?>
                            <span class="badge-verified-student" title="Verified Student Credentials"><i class="fas fa-check-circle"></i> Verified</span>
                        <?php endif; ?>
                        <span class="badge <?= $statusBadgeClass ?>"><?= $statusBadgeLabel ?></span>
                    </div>

                    <p class="tenant-sub">
                        <i class="fas fa-door-open"></i> <?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?>
                        <span class="occupancy-pill <?= $isRoomFull ? 'is-full' : '' ?>" title="<?= $isRoomFull ? 'Room at maximum capacity and closed' : ($maxCap - $occ) . ' slot(s) available' ?>">
                            <?= $occ ?>/<?= $maxCap ?> <?= $isRoomFull ? 'Occupants (Full · Closed)' : 'Occupants' ?>
                        </span>
                        <span class="dot">&bull;</span>
                        <i class="fas fa-building"></i> <?= htmlspecialchars($propLabel, ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <div class="tenant-meta">
                        <div class="meta">
                            <span class="meta-label">Duration</span>
                            <span class="meta-value"><?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="meta">
                            <span class="meta-label"><?= $st === 'pending' ? 'Requested On' : 'Move-In' ?></span>
                            <span class="meta-value"><?= htmlspecialchars($moveInDate, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="meta">
                            <span class="meta-label">School</span>
                            <span class="meta-value"><?= htmlspecialchars($schoolVal, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="meta">
                            <span class="meta-label">Monthly Rent</span>
                            <span class="meta-value"><?= htmlspecialchars($rentFormatted, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </div>

                <div class="tenant-actions">
                    <?php if ($st === 'pending'): ?>
                        <?php if ($isLandlordVerified): ?>
                            <form method="POST" class="booking-action-form" style="display:flex; flex-direction:column; gap:8px; width:100%;">
                                <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                <?php if ($isRoomFull): ?>
                                    <button type="button" class="btn-action-approve is-disabled" disabled title="Room has reached full capacity (<?= $occ ?>/<?= $maxCap ?>) and is closed">
                                        <i class="fas fa-ban"></i> Room Full (<?= $occ ?>/<?= $maxCap ?>)
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="booking_action" value="approve" class="btn-action-approve" title="Approve booking">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                <?php endif; ?>
                                <button type="submit" name="booking_action" value="reject" class="btn-action-reject" title="Decline booking"
                                    data-confirm-crumb="UniStay &bull; Booking Request"
                                    data-confirm-title="Decline this booking request?"
                                    data-confirm-message="<?= htmlspecialchars($declineMsg, ENT_QUOTES, 'UTF-8') ?>"
                                    data-confirm-ok="Yes, decline"
                                    data-confirm-cancel="Keep request">
                                    <i class="fas fa-times"></i> Decline
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color:#b45309; font-size:11px; font-weight:700; text-align:center; padding:4px 0;">Verification Required</span>
                        <?php endif; ?>
                    <?php elseif ($st === 'active'): ?>
                        <?php if ($isLandlordVerified): ?>
                            <form method="POST" class="booking-action-form" style="display:flex; width:100%;">
                                <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" style="width:100%;" title="End Tenancy"
                                    data-confirm-crumb="UniStay &bull; Tenancy"
                                    data-confirm-title="End this student tenancy?"
                                    data-confirm-message="<?= htmlspecialchars($endMsg, ENT_QUOTES, 'UTF-8') ?>"
                                    data-confirm-ok="Yes, end tenancy"
                                    data-confirm-cancel="Keep tenancy">
                                    <i class="fas fa-sign-out-alt"></i> End Tenancy
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <button type="button" class="btn-outline view-details" data-booking-id="<?= $bId ?>">
                        <i class="fas fa-eye"></i> View details
                    </button>
                </div>
            </article>
            <?php endforeach; ?>
        </section>

        <!-- EMPTY STATE -->
        <div class="empty-state" id="emptyState" style="<?= empty($bookings) ? 'display:flex;' : '' ?>">
            <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
            <h3>No requests or bookings found</h3>
            <p>Try a different filter or search term. Student bookings for your properties will show up here.</p>
            <a href="properties.php" class="btn-primary-action" style="text-decoration:none;">
                <i class="fas fa-building"></i> View Listed Properties
            </a>
        </div>

    </div>
</main>
</div>

<!-- SLIDE-OVER DRAWER -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<aside class="drawer" id="tenantDrawer" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
    <div class="drawer-header">
        <h2 id="drawerTitle">Tenant &amp; Booking Details</h2>
        <button type="button" class="drawer-close" id="drawerClose" aria-label="Close details">&#x2715;</button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <!-- Rendered dynamically via JavaScript -->
    </div>
    <div class="drawer-footer" id="drawerFooter">
        <!-- Action buttons rendered dynamically based on status -->
    </div>
</aside>

<script id="notification-data" type="application/json">
<?= json_encode($landlordNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'landlord']) ?>;
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/landlord-notifications.js?v=6"></script>
<script src="../assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/confirm-modal.js') ?>"></script>
<script>
(function() {
    'use strict';

    var cards = Array.from(document.querySelectorAll('.tenant-card'));
    var listEl = document.getElementById('tenantList');
    var emptyEl = document.getElementById('emptyState');
    var searchEl = document.getElementById('tenantSearch');
    var propEl = document.getElementById('propertyFilter');
    var clearBtn = document.getElementById('clearFilters');

    // Rectangular Stay Tabs and Animated Indicator
    var stayTabs = document.querySelector('.dorms-stay-filters');
    var stayPill = stayTabs ? stayTabs.querySelector('.stay-pill') : null;
    var stayPillReady = false;
    var tabBtns = Array.from(document.querySelectorAll('.stay-filter-btn'));
    var countEls = Array.from(document.querySelectorAll('.stay-filter-count'));

    var drawerOverlay = document.getElementById('drawerOverlay');
    var drawer = document.getElementById('tenantDrawer');
    var drawerBody = document.getElementById('drawerBody');
    var drawerFooter = document.getElementById('drawerFooter');
    var drawerClose = document.getElementById('drawerClose');

    var activeFilter = <?= json_encode($statusFilter) ?> || 'all';

    function placeStayPill(instant) {
        var active = stayTabs ? stayTabs.querySelector('.stay-filter-btn.is-active') : null;
        if (!stayPill || !active) return;
        var noAnim = instant || !stayPillReady;
        if (noAnim) stayPill.style.transition = 'none';
        stayPill.style.width = active.offsetWidth + 'px';
        stayPill.style.transform = 'translateX(' + active.offsetLeft + 'px)';
        if (noAnim) {
            void stayPill.offsetWidth;
            stayPill.style.transition = '';
            stayPillReady = true;
        }
    }

    function getCardData(card) {
        try {
            return JSON.parse(card.dataset.booking);
        } catch (e) {
            return null;
        }
    }

    function applyFilters() {
        var q = (searchEl ? searchEl.value.trim().toLowerCase() : '');
        var selectedProp = (propEl ? propEl.value : 'all');
        var visibleCount = 0;

        cards.forEach(function(card) {
            var st = card.dataset.status;
            var prop = card.dataset.property;
            var searchHaystack = card.dataset.search || '';

            var matchesStatus = (activeFilter === 'all') || (st === activeFilter);
            var matchesProp = (selectedProp === 'all') || (prop === selectedProp);
            var matchesSearch = !q || searchHaystack.indexOf(q) !== -1;

            if (matchesStatus && matchesProp && matchesSearch) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });


        if (clearBtn) {
            clearBtn.style.display = (q !== '' || selectedProp !== 'all') ? 'inline-block' : 'none';
        }

        if (emptyEl) {
            if (visibleCount === 0) {
                emptyEl.classList.add('is-visible');
                emptyEl.style.display = 'flex';
            } else {
                emptyEl.classList.remove('is-visible');
                emptyEl.style.display = 'none';
            }
        }

        updateDynamicCounts();
    }

    function updateDynamicCounts() {
        var selectedProp = (propEl ? propEl.value : 'all');
        var q = (searchEl ? searchEl.value.trim().toLowerCase() : '');

        var matchedCards = cards.filter(function(card) {
            var prop = card.dataset.property;
            var searchHaystack = card.dataset.search || '';
            var matchesProp = (selectedProp === 'all') || (prop === selectedProp);
            var matchesSearch = !q || searchHaystack.indexOf(q) !== -1;
            return matchesProp && matchesSearch;
        });

        countEls.forEach(function(el) {
            var key = el.getAttribute('data-count');
            if (key === 'all') {
                el.textContent = matchedCards.length;
            } else {
                var cnt = matchedCards.filter(function(c) { return c.dataset.status === key; }).length;
                el.textContent = cnt;
            }
        });
    }

    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            tabBtns.forEach(function(b) {
                b.classList.remove('is-active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
            activeFilter = btn.getAttribute('data-filter') || 'all';

            placeStayPill();
            applyFilters();

            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('status', activeFilter);
                window.history.replaceState(null, '', url.toString());
            }
        });
    });

    if (searchEl) {
        searchEl.addEventListener('input', applyFilters);
    }

    // Custom Property Select Dropdown with smooth animated open/close (matching Tenant Dorms)
    var propSelect = document.getElementById('property-select');
    var propBtn = propSelect ? propSelect.querySelector('.um-select-btn') : null;
    var propValue = propSelect ? propSelect.querySelector('.um-select-value') : null;
    var propMenu = propSelect ? propSelect.querySelector('.um-select-menu') : null;
    var propCloseTimeout = null;

    function closePropertyMenu() {
        if (!propMenu || propMenu.hidden || propMenu.classList.contains('is-closing')) return;
        clearTimeout(propCloseTimeout);
        if (propBtn) propBtn.setAttribute('aria-expanded', 'false');
        propMenu.classList.add('is-closing');
        propCloseTimeout = setTimeout(function() {
            propMenu.hidden = true;
            propMenu.classList.remove('is-closing');
        }, 160);
    }

    function openPropertyMenu() {
        if (!propMenu) return;
        clearTimeout(propCloseTimeout);
        propMenu.classList.remove('is-closing');
        propMenu.hidden = false;
        if (propBtn) propBtn.setAttribute('aria-expanded', 'true');
    }

    function togglePropertyMenu(open) {
        if (!propMenu) return;
        var willOpen = (open !== undefined) ? open : propMenu.hidden;
        if (willOpen) {
            openPropertyMenu();
        } else {
            closePropertyMenu();
        }
    }

    if (propBtn) {
        propBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            togglePropertyMenu();
        });
    }

    document.addEventListener('click', function(e) {
        if (propSelect && !propSelect.contains(e.target)) {
            closePropertyMenu();
        }
    });

    if (propMenu) {
        propMenu.querySelectorAll('li').forEach(function(li) {
            li.addEventListener('click', function() {
                propMenu.querySelectorAll('li').forEach(function(el) {
                    el.setAttribute('aria-selected', 'false');
                });
                li.setAttribute('aria-selected', 'true');
                var val = li.getAttribute('data-value') || 'all';
                if (propEl) {
                    propEl.value = val;
                }
                if (propValue) {
                    propValue.textContent = li.textContent.trim();
                }
                if (propBtn) {
                    propBtn.classList.toggle('is-placeholder', val === 'all');
                }
                togglePropertyMenu(false);
                applyFilters();
            });
        });
    }

    if (propEl) {
        propEl.addEventListener('change', applyFilters);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchEl) searchEl.value = '';
            if (propEl) propEl.value = 'all';
            if (propValue) propValue.textContent = 'All properties';
            if (propBtn) propBtn.classList.add('is-placeholder');
            if (propMenu) {
                propMenu.querySelectorAll('li').forEach(function(el) {
                    el.setAttribute('aria-selected', el.getAttribute('data-value') === 'all' ? 'true' : 'false');
                });
            }
            activeFilter = 'all';
            tabBtns.forEach(function(b) {
                var isAll = b.getAttribute('data-filter') === 'all';
                b.classList.toggle('is-active', isAll);
                b.setAttribute('aria-selected', isAll ? 'true' : 'false');
            });
            placeStayPill();
            applyFilters();
        });
    }

    // Slide-over drawer generator
    function sectionHTML(icon, title, items) {
        var cells = items.map(function(pair) {
            var isFull = !!pair[2];
            var cls = isFull ? 'detail-item full' : 'detail-item';
            return '<div class="' + cls + '">' +
                '<span class="detail-label">' + pair[0] + '</span>' +
                '<span class="detail-value">' + pair[1] + '</span>' +
            '</div>';
        }).join('');

        return '<div class="detail-section">' +
            '<div class="detail-section-head">' +
                '<span class="detail-section-icon"><i class="fas fa-' + icon + '"></i></span>' +
                '<h4>' + title + '</h4>' +
            '</div>' +
            '<div class="detail-grid">' + cells + '</div>' +
        '</div>';
    }

    function openDrawer(data) {
        if (!data) return;

        var avatarContent = data.photo
            ? '<img src="' + encodeURI(data.photo) + '" alt="" style="width:100%;height:100%;object-fit:cover;">'
            : data.initial;

        var heroHTML = '' +
            '<div class="drawer-hero">' +
                '<div class="hero-top">' +
                    '<div class="tenant-avatar large" aria-hidden="true">' + avatarContent + '</div>' +
                    '<div class="hero-info">' +
                        '<h3>' + data.name + '</h3>' +
                        (data.isVerifiedStudent ? '<span class="badge-verified-student" style="margin-right:6px;"><i class="fas fa-check-circle"></i> Verified Student</span>' : '') +
                        '<span class="badge ' + data.statusBadgeClass + '">' + data.statusLabel + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="hero-meta">' +
                    '<span class="hero-chip"><i class="fas fa-door-open"></i> ' + data.room + ' &middot; ' + data.roomType + '</span>' +
                    '<span class="hero-chip"><i class="fas fa-building"></i> ' + data.property + '</span>' +
                '</div>' +
                '<div class="hero-stats">' +
                    '<div class="hero-stat">' +
                        '<span class="hero-stat-label">Duration</span>' +
                        '<span class="hero-stat-value">' + data.duration + '</span>' +
                    '</div>' +
                    '<div class="hero-stat">' +
                        '<span class="hero-stat-label">Monthly Rent</span>' +
                        '<span class="hero-stat-value">' + data.rent + '</span>' +
                    '</div>' +
                    '<div class="hero-stat">' +
                        '<span class="hero-stat-label">Booking Date</span>' +
                        '<span class="hero-stat-value">' + data.moveIn + '</span>' +
                    '</div>' +
                '</div>' +
            '</div>';

        var personalHTML = sectionHTML('user', 'Personal Information', [
            ['Full name', data.name],
            ['Student Verification', data.isVerifiedStudent ? '<span style="color:#15803d;font-weight:700;">✓ Verified ID on file</span>' : 'Unverified'],
            ['Personal email', data.email !== 'Not provided' ? '<a href="mailto:' + encodeURI(data.email) + '" style="color:var(--brown);font-weight:600;">' + data.email + '</a>' : 'Not provided'],
            ['Phone number', data.phone !== 'Not provided' ? '<a href="tel:' + encodeURI(data.phone) + '" style="color:var(--brown);font-weight:600;">' + data.phone + '</a>' : 'Not provided'],
            ['School / University', data.school, true],
            ['Emergency contact', data.emergencyName + ' (' + data.emergencyRelationship + ')'],
            ['Emergency phone', data.emergencyPhone]
        ]);

        var leaseHTML = sectionHTML('file-signature', 'Lease & Booking Details', [
            ['Booking / Move-in', data.moveIn],
            ['Duration of stay', data.duration],
            ['Monthly rent', data.rent],
            ['Security deposit', data.deposit],
            ['Booking Status', '<strong style="text-transform:capitalize;color:var(--brown-dark);">' + data.status + '</strong>'],
            ['Lease terms', 'Standard UniStay Academic Term']
        ]);

        var capNum = parseInt(data.capacity, 10) || 1;
        var occNum = parseInt(data.occupants, 10) || 0;
        var spotsLeft = Math.max(0, capNum - occNum);
        var occBadgeHtml = data.isRoomFull
            ? ' <span class="occupancy-pill is-full" style="margin-left:6px;">Full &middot; Closed</span>'
            : ' <span class="occupancy-pill is-available" style="margin-left:6px;">' + spotsLeft + ' spot' + (spotsLeft === 1 ? '' : 's') + ' left</span>';

        var roomHTML = sectionHTML('door-open', 'Room & Property Details', [
            ['Property name', data.property],
            ['Room assigned', data.room + ' &middot; ' + data.roomType],
            ['Property address', data.propertyAddress, true],
            ['Occupancy & Capacity', occNum + ' / ' + capNum + ' occupants' + occBadgeHtml],
            ['Amenities included', data.amenities, true]
        ]);

        drawerBody.innerHTML = heroHTML + personalHTML + leaseHTML + roomHTML;
        drawerBody.scrollTop = 0;

        // Populate drawer footer actions
        var footerHTML = '';
        if (data.status === 'pending') {
            if (data.canManage) {
                var approveBtnHtml = data.isRoomFull
                    ? '<button type="button" class="btn-action-approve is-disabled" disabled style="flex:1;" title="Room has reached full capacity and is closed"><i class="fas fa-ban"></i> Room Full (' + occNum + '/' + capNum + ')</button>'
                    : '<button type="submit" name="booking_action" value="approve" class="btn-action-approve" style="flex:1;"><i class="fas fa-check"></i> Approve Request</button>';

                footerHTML = '' +
                    '<form method="POST" class="booking-action-form" style="display:flex;gap:10px;width:100%;">' +
                        '<input type="hidden" name="booking_id" value="' + data.bookingId + '">' +
                        approveBtnHtml +
                        '<button type="submit" name="booking_action" value="reject" class="btn-action-reject" style="flex:1;" ' +
                            'data-confirm-crumb="UniStay &bull; Booking Request" ' +
                            'data-confirm-title="Decline this booking request?" ' +
                            'data-confirm-message="' + data.declineMsg.replace(/"/g, '&quot;') + '" ' +
                            'data-confirm-ok="Yes, decline" ' +
                            'data-confirm-cancel="Keep request">' +
                            '<i class="fas fa-times"></i> Decline' +
                        '</button>' +
                    '</form>';
            } else {
                footerHTML = '<span style="color:#b45309;font-weight:700;font-size:13px;padding:8px 0;width:100%;text-align:center;">Account verification required to approve/decline.</span>';
            }
        } else if (data.status === 'active') {
            if (data.canManage) {
                footerHTML = '' +
                    '<form method="POST" class="booking-action-form" style="display:flex;gap:10px;width:100%;">' +
                        '<input type="hidden" name="booking_id" value="' + data.bookingId + '">' +
                        '<button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" style="flex:1;" ' +
                            'data-confirm-crumb="UniStay &bull; Tenancy" ' +
                            'data-confirm-title="End this student tenancy?" ' +
                            'data-confirm-message="' + data.endMsg.replace(/"/g, '&quot;') + '" ' +
                            'data-confirm-ok="Yes, end tenancy" ' +
                            'data-confirm-cancel="Keep tenancy">' +
                            '<i class="fas fa-sign-out-alt"></i> End Tenancy' +
                        '</button>' +
                        (data.email !== 'Not provided' ? '<a href="mailto:' + encodeURI(data.email) + '" class="btn-outline" style="flex:1;text-decoration:none;"><i class="fas fa-envelope"></i> Email Tenant</a>' : '') +
                    '</form>';
            }
        } else {
            footerHTML = '<button type="button" class="btn-outline" id="drawerCloseFooter" style="width:100%;">Close</button>';
        }

        drawerFooter.innerHTML = footerHTML;

        var footerClose = document.getElementById('drawerCloseFooter');
        if (footerClose) {
            footerClose.addEventListener('click', closeDrawer);
        }

        drawer.classList.add('is-open');
        drawerOverlay.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawerOverlay.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    // View details click event
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.view-details');
        if (btn) {
            var card = btn.closest('.tenant-card');
            if (card) {
                var data = getCardData(card);
                openDrawer(data);
            }
            return;
        }

        if (e.target.closest('#drawerClose') || e.target.closest('#drawerOverlay')) {
            closeDrawer();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
            closeDrawer();
        }
    });

    // Instant Toast Notification
    function showToast(title, message, isError) {
        var toast = document.querySelector('.notification-toast');
        if (!toast) {
            toast = document.createElement('aside');
            toast.className = 'notification-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }
        toast.style.borderLeftColor = isError ? '#dc2626' : 'var(--brown)';
        toast.innerHTML = '<strong>' + (isError ? '<i class="fas fa-exclamation-circle" style="color:#dc2626;margin-right:6px;"></i>' : '<i class="fas fa-check-circle" style="color:#16a34a;margin-right:6px;"></i>') + title + '</strong>' +
            (message ? '<span>' + message + '</span>' : '');
        toast.classList.add('is-visible');
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function() {
            toast.classList.remove('is-visible');
        }, 4000);
    }

    // Fast-Path AJAX Booking Actions (Approve / Decline / End Tenancy)
    function executeBookingAction(action, bookingId, btn) {
        if (!bookingId || !action) return;
        if (btn) {
            btn.classList.add('is-loading');
            if (action === 'approve') {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
            } else if (action === 'reject') {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Declining...';
            } else if (action === 'end_tenancy') {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ending...';
            }
        }

        var fd = new FormData();
        fd.append('booking_id', bookingId);
        fd.append('booking_action', action);
        fd.append('ajax', '1');

        fetch('tenants.php', {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(res) {
            return res.json().catch(function() {
                return { ok: false, error: 'Invalid response from server' };
            });
        })
        .then(function(res) {
            if (!res.ok) {
                showToast('Action Failed', res.error || 'Could not complete the request.', true);
                if (btn) {
                    btn.classList.remove('is-loading');
                    if (action === 'approve') btn.innerHTML = '<i class="fas fa-check"></i> Approve';
                    else if (action === 'reject') btn.innerHTML = '<i class="fas fa-times"></i> Decline';
                    else if (action === 'end_tenancy') btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> End Tenancy';
                }
                return;
            }

            // Fast Success Toast!
            showToast('Request Updated', res.message || 'Updated successfully!', false);

            // Update Tab Counts
            if (res.counts) {
                document.querySelectorAll('.stay-filter-btn').forEach(function(b) {
                    var st = b.dataset.status;
                    if (st && res.counts[st] !== undefined) {
                        var cntEl = b.querySelector('.stay-filter-count');
                        if (cntEl) cntEl.textContent = res.counts[st];
                    }
                });
                var alertPill = document.querySelector('.pending-alert-pill');
                if (alertPill) {
                    if (res.pending_requests_count > 0) {
                        alertPill.innerHTML = '<i class="fas fa-clock"></i> ' + res.pending_requests_count + ' Pending Request' + (res.pending_requests_count === 1 ? '' : 's');
                        alertPill.style.display = 'inline-flex';
                    } else {
                        alertPill.style.display = 'none';
                    }
                }
            }

            // Update Sidebar requests badge if present
            var sideBadge = document.getElementById('sidebar-requests-badge');
            if (sideBadge) {
                if (res.pending_requests_count > 0) {
                    sideBadge.textContent = res.pending_requests_count;
                    sideBadge.style.display = 'inline-flex';
                } else {
                    sideBadge.style.display = 'none';
                }
            }

            // Update Card in DOM
            var card = document.getElementById('booking-card-' + bookingId);
            if (card) {
                card.dataset.status = res.new_status;
                var data = getCardData(card);

                var statusBadge = card.querySelector('.tenant-head .badge:not(.badge-verified-student)');
                var actionsContainer = card.querySelector('.tenant-actions');

                if (res.new_status === 'active') {
                    if (statusBadge) {
                        statusBadge.className = 'badge badge--present';
                        statusBadge.textContent = 'Present';
                    }
                    if (data) {
                        data.status = 'active';
                        data.statusLabel = 'Present';
                        data.statusBadgeClass = 'badge--present';
                        var occNum = parseInt(data.occupants, 10) || 0;
                        var capNum = parseInt(data.capacity, 10) || 1;
                        occNum = Math.min(capNum, occNum + 1);
                        data.occupants = String(occNum);
                        data.isRoomFull = (occNum >= capNum);
                        card.dataset.booking = JSON.stringify(data);

                        var occPill = card.querySelector('.occupancy-pill');
                        if (occPill) {
                            occPill.className = 'occupancy-pill ' + (data.isRoomFull ? 'is-full' : '');
                            occPill.textContent = data.occupants + '/' + data.capacity + ' ' + (data.isRoomFull ? 'Occupants (Full · Closed)' : 'Occupants');
                        }
                    }

                    if (actionsContainer) {
                        var viewBtn = actionsContainer.querySelector('.view-details');
                        actionsContainer.innerHTML = '' +
                            '<form method="POST" class="booking-action-form" style="display:flex; width:100%;">' +
                                '<input type="hidden" name="booking_id" value="' + bookingId + '">' +
                                '<button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" style="width:100%;" title="End Tenancy" ' +
                                    'data-confirm-crumb="UniStay &bull; Tenancy" ' +
                                    'data-confirm-title="End this student tenancy?" ' +
                                    'data-confirm-message="The tenancy will be marked as ended." ' +
                                    'data-confirm-ok="Yes, end tenancy" ' +
                                    'data-confirm-cancel="Keep tenancy">' +
                                    '<i class="fas fa-sign-out-alt"></i> End Tenancy' +
                                '</button>' +
                            '</form>';
                        if (viewBtn) actionsContainer.appendChild(viewBtn);
                    }
                } else if (res.new_status === 'rejected') {
                    if (statusBadge) {
                        statusBadge.className = 'badge badge--leaving';
                        statusBadge.textContent = 'Declined';
                    }
                    if (data) {
                        data.status = 'rejected';
                        data.statusLabel = 'Declined';
                        data.statusBadgeClass = 'badge--leaving';
                        card.dataset.booking = JSON.stringify(data);
                    }
                    if (actionsContainer) {
                        var viewBtn = actionsContainer.querySelector('.view-details');
                        actionsContainer.innerHTML = '';
                        if (viewBtn) actionsContainer.appendChild(viewBtn);
                    }
                } else if (res.new_status === 'cancelled') {
                    if (statusBadge) {
                        statusBadge.className = 'badge badge--leaving';
                        statusBadge.textContent = 'Ended';
                    }
                    if (data) {
                        data.status = 'cancelled';
                        data.statusLabel = 'Ended';
                        data.statusBadgeClass = 'badge--leaving';
                        var occNum = Math.max(0, (parseInt(data.occupants, 10) || 1) - 1);
                        data.occupants = String(occNum);
                        data.isRoomFull = false;
                        card.dataset.booking = JSON.stringify(data);

                        var occPill = card.querySelector('.occupancy-pill');
                        if (occPill) {
                            occPill.className = 'occupancy-pill';
                            occPill.textContent = data.occupants + '/' + data.capacity + ' Occupants';
                        }
                    }
                    if (actionsContainer) {
                        var viewBtn = actionsContainer.querySelector('.view-details');
                        actionsContainer.innerHTML = '';
                        if (viewBtn) actionsContainer.appendChild(viewBtn);
                    }
                }

                // If active filter is not 'all' and does not match new_status, smoothly fade out card
                var activeFilterBtn = document.querySelector('.stay-filter-btn.is-active');
                var currentFilter = activeFilterBtn ? activeFilterBtn.dataset.status : 'all';
                if (currentFilter !== 'all' && currentFilter !== res.new_status) {
                    card.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.96)';
                    setTimeout(function() {
                        applyFilters();
                        placeStayPill(false);
                    }, 260);
                } else {
                    applyFilters();
                    placeStayPill(false);
                }
            }

            // Close drawer if open
            if (drawer && drawer.classList.contains('is-open')) {
                closeDrawer();
            }
        })
        .catch(function() {
            showToast('Network Error', 'Please check your connection and try again.', true);
            if (btn) {
                btn.classList.remove('is-loading');
            }
        });
    }

    // Intercept Approve click for sub-100ms action
    document.addEventListener('click', function(e) {
        var approveBtn = e.target.closest('.btn-action-approve');
        if (!approveBtn || approveBtn.classList.contains('is-loading') || approveBtn.classList.contains('is-disabled') || approveBtn.disabled) return;
        
        var form = approveBtn.form || approveBtn.closest('form');
        var bookingInput = form ? form.querySelector('input[name="booking_id"]') : null;
        var bId = bookingInput ? bookingInput.value : null;

        if (bId) {
            e.preventDefault();
            executeBookingAction('approve', bId, approveBtn);
        }
    });

    // Confirmation modal for Decline / End Tenancy
    document.addEventListener('click', async function(e) {
        var btn = e.target.closest('[data-confirm-title]');
        if (!btn || btn.classList.contains('is-loading') || btn.disabled) return;
        e.preventDefault();

        if (typeof UniStayConfirm === 'undefined' || typeof UniStayConfirm.open !== 'function') {
            var confirmed = confirm(btn.dataset.confirmMessage || 'Are you sure you want to proceed?');
            if (!confirmed) return;
        } else {
            var ok = await UniStayConfirm.open({
                crumb: btn.dataset.confirmCrumb,
                title: btn.dataset.confirmTitle,
                message: btn.dataset.confirmMessage,
                confirmText: btn.dataset.confirmOk,
                cancelText: btn.dataset.confirmCancel,
                danger: true
            });
            if (!ok) return;
        }

        var form = btn.form || btn.closest('form');
        var bookingInput = form ? form.querySelector('input[name="booking_id"]') : null;
        var bId = bookingInput ? bookingInput.value : null;
        var actionVal = btn.value || (btn.name === 'booking_action' ? btn.value : null) || (btn.classList.contains('btn-action-reject') ? 'reject' : 'end_tenancy');

        if (bId && actionVal) {
            executeBookingAction(actionVal, bId, btn);
        }
    });

    // Deep link highlight support & Stay pill placement
    document.addEventListener('DOMContentLoaded', function() {
        placeStayPill(true);
        applyFilters();

        var hlCard = document.querySelector('.highlight-card');
        if (hlCard) {
            setTimeout(function() {
                hlCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var data = getCardData(hlCard);
                if (data) {
                    openDrawer(data);
                }
            }, 300);
        }
    });

    window.addEventListener('load', function() { placeStayPill(true); });
    window.addEventListener('resize', function() { placeStayPill(true); });
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function() { placeStayPill(true); });
    }

})();
</script>
</body>
</html>
