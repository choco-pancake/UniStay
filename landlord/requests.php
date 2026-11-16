<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (string)($_SESSION['user_id'] ?? '');
$landlordProfile = get_authenticated_profile($pdo, $userId, 'landlord');
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
            SELECT b.id, b.room_id, b.tenant_id, b.status, u.first_name, u.last_name, u.verification_status AS tenant_verification,
                   p.name AS prop_name, r.name AS room_name,
                   l.facebook_link AS landlord_fb
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN dorms p ON p.id = r.dorm_id
            JOIN landlords l ON l.landlord_id = p.landlord_id
            JOIN tenants u ON u.tenant_id = b.tenant_id
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
                if (empty($actionErr)) {
                    try {
                        $pdo->beginTransaction();
                    if (!empty($targetBooking['room_id'])) {
                        $rId = (int)$targetBooking['room_id'];
                        $roomLock = $pdo->prepare('SELECT occupancy FROM rooms WHERE id = ? FOR UPDATE');
                        $roomLock->execute([$rId]);
                        $capacity = (int)$roomLock->fetchColumn();
                        $activeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status = 'approved'");
                        $activeCountStmt->execute([$rId]);
                        $activeCount = (int)$activeCountStmt->fetchColumn();
                        $alreadyApproved = ($targetBooking['status'] ?? '') === 'approved';
                        if (!$alreadyApproved && $capacity > 0 && $activeCount >= $capacity) {
                            $actionErr = 'This room is already at full capacity. The booking request was not approved.';
                        }
                    }
                    if (empty($actionErr)) {
                        $upd = $pdo->prepare("UPDATE bookings SET status = 'approved', updated_at = NOW() WHERE id = ?");
                        $upd->execute([$bookingId]);
                        if (!empty($targetBooking['room_id'])) {
                            sync_room_availability($pdo, (int)$targetBooking['room_id']);
                        }
                    }
                        $pdo->commit();
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $error;
                    }

                    if (empty($actionErr)) {
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
                    }
                }
            } elseif ($action === 'reject' || $action === 'decline') {
                $feedback = trim((string)($_POST['feedback'] ?? $_POST['rejection_reason'] ?? ''));
                $upd = $pdo->prepare("UPDATE bookings SET status = 'declined', feedback = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$feedback !== '' ? $feedback : null, $bookingId]);
                if ($targetBooking['status'] === 'approved' && !empty($targetBooking['room_id'])) {
                    $rId = (int)$targetBooking['room_id'];
                    sync_room_availability($pdo, $rId);
                }
                $actionMsg = "Booking request from {$tenantName} has been declined.";
                require_once __DIR__ . '/../config/notifications.php';

                // Send in-app notification to tenant immediately
                $notifText = "Your booking request for {$propName}{$roomSuffix} was declined by the landlord.";
                if ($feedback !== '') {
                    $notifText .= "\n\nReason: " . $feedback;
                }
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'cancellation',
                    'Booking Request Declined',
                    $notifText,
                    $bookingId,
                    'tenant'
                );

                // Asynchronously dispatch SMTP notification emails in the background
                async_send_booking_decision_emails($bookingId, 'declined');
            } elseif ($action === 'end_tenancy') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'ended', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                if (!empty($targetBooking['room_id'])) {
                    $rId = (int)$targetBooking['room_id'];
                    sync_room_availability($pdo, $rId);
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
            'status' => ($action === 'approve' ? 'approved' : ($action === 'reject' || $action === 'decline' ? 'declined' : 'ended'))
        ]);
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if ($statusFilter === 'rejected') $statusFilter = 'declined';
$validStatuses = ['all', 'pending', 'approved', 'active', 'declined', 'ended'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}
$highlightBookingId = (int)($_GET['highlight'] ?? $_GET['booking_id'] ?? 0);

$sql = '
    SELECT b.id, b.status, b.created_at, b.room_id, b.tenant_id,
           u.tenant_id AS tenant_code, u.first_name, u.middle_name, u.last_name, u.email, u.email AS personal_email, u.phone_number, u.facebook_link,
           u.university, u.university AS school, u.emergency_name, u.emergency_relationship, u.emergency_phone,
           u.profile_photo AS user_photo, u.profile_photo, u.school_id, u.gov_id,
           u.verification_status AS tenant_verification, u.feedback AS tenant_feedback,
           p.name AS property_name, p.nearest_uni AS prop_university, p.address AS prop_address,
           r.name AS room_name, r.occupancy AS room_type, r.occupancy AS capacity, r.total_rent, r.rent_per_head, r.deposit, r.amenities
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN dorms p ON p.id = r.dorm_id
    JOIN tenants u ON u.tenant_id = b.tenant_id
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
$counts = ['all' => 0, 'approved' => 0, 'active' => 0, 'pending' => 0, 'declined' => 0, 'ended' => 0];
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
    $email = (string)($b['email'] ?? ($b['personal_email'] ?? ''));
    if ($photo === '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $photo = 'https://unavatar.io/' . rawurlencode(strtolower(trim($email))) . '?fallback=false';
    }
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
    <style id="landlord-tailwind-requests">
    /* landlord-tailwind:requests:start */
*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.\!visible{visibility:visible!important}.visible{visibility:visible}.collapse{visibility:collapse}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.-right-1{right:-.25rem}.-right-1\.5{right:-.375rem}.-top-1{top:-.25rem}.-top-1\.5{top:-.375rem}.block{display:block}.inline{display:inline}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.\!grid{display:grid!important}.grid{display:grid}.hidden{display:none}.h-24{height:6rem}.h-5{height:1.25rem}.w-5{width:1.25rem}.w-full{width:100%}.flex-shrink{flex-shrink:1}.border-collapse{border-collapse:collapse}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.flex-wrap{flex-wrap:wrap}.rounded{border-radius:.25rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.border{border-width:1px}.bg-indigo-600{--tw-bg-opacity:1;background-color:rgb(79 70 229/var(--tw-bg-opacity,1))}.bg-slate-900{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}.bg-slate-900\/70{background-color:rgba(15,23,42,.7)}.object-cover{-o-object-fit:cover;object-fit:cover}.text-xs{font-size:.75rem;line-height:1rem}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.italic{font-style:italic}.leading-none{line-height:1}.text-slate-600{--tw-text-opacity:1;color:rgb(71 85 105/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.underline{text-decoration-line:underline}.outline{outline-style:solid}.ring{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(3px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.blur{--tw-blur:blur(8px)}.blur,.drop-shadow{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.drop-shadow{--tw-drop-shadow:drop-shadow(0 1px 2px rgba(0,0,0,.1)) drop-shadow(0 1px 1px rgba(0,0,0,.06))}.grayscale{--tw-grayscale:grayscale(100%)}.filter,.grayscale{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}:root{color-scheme:light;--brown:#7a4b3a;--ink:#5f3425;--cream:#fcf9f4;--text:#584b45;--line:#e8ded6;--nav-dur:0.35s}html{scrollbar-width:none}html::-webkit-scrollbar{display:none}body.unistay-scroll-locked,html.unistay-scroll-locked{overflow:hidden!important;overscroll-behavior:none}[hidden]{display:none!important}body{margin:0;padding:18px 14px 0;background:var(--cream);color:var(--ink);font:15px/1.5 DM Sans,Segoe UI,system-ui,sans-serif}a,button,input{-webkit-tap-highlight-color:transparent}button{font:inherit;cursor:pointer}a{text-decoration:none;color:inherit}:focus-visible{outline:3px solid #ac7728;outline-offset:3px}svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}.symbols{position:absolute;width:0;height:0;overflow:hidden}.skip{position:fixed;top:-100px;left:20px;z-index:50;padding:10px;background:#fff}.skip:focus{top:20px}.sidebar{position:fixed;top:18px;bottom:12px;left:14px;width:259px;padding:20px 19px;background:radial-gradient(55% 55% at 78% 8%,rgba(176,117,92,.85) 0,rgba(176,117,92,0) 100%),radial-gradient(60% 55% at 12% 100%,rgba(166,107,82,.9) 0,rgba(166,107,82,0) 100%),#7a4b3a;color:var(--cream);border-radius:21px;display:flex;flex-direction:column;height:calc(100vh - 30px)}.brand,a.brand{display:flex;align-items:center;gap:10px;margin:0 1px 21px;font:700 22px Georgia,Times New Roman,serif;text-decoration:none;color:inherit;cursor:pointer;transition:opacity .2s ease}.brand:hover,a.brand:hover{opacity:.92}.brand img{width:50px;height:50px}nav{display:grid;gap:10px}.sidebar nav a{display:grid;grid-template-columns:19px minmax(0,1fr) auto;align-items:center;justify-content:normal;gap:12px;min-height:45px;padding:11px 15px;border-radius:11px;color:var(--cream);text-align:left;font-size:14px;font-weight:600;letter-spacing:.2px}.sidebar nav a svg{width:19px;height:19px}.sidebar nav a:hover{background:#ffffff12}.sidebar nav,.sidebar nav a{position:relative}.sidebar nav a{z-index:1;background:transparent;transition:color .24s ease,background .15s ease}.sidebar nav a:before{content:"";position:absolute;inset:0;border-radius:inherit;background:var(--cream);box-shadow:inset 5px 0 #e4cbbf;z-index:-1;opacity:0;transform:scale(.97);pointer-events:none;transition:opacity .24s cubic-bezier(.16,1,.3,1),transform .24s cubic-bezier(.16,1,.3,1);will-change:opacity,transform}.sidebar nav a[aria-current=page]{background:transparent!important;color:var(--brown)!important}.sidebar nav a[aria-current=page]:before{opacity:1;transform:scale(1)}.sidebar nav a.nav-fading-out{color:var(--cream)!important;transition:color .3s ease!important}.sidebar nav a.nav-fading-out:before{opacity:0!important;transform:scale(.96)!important;transition:opacity .32s ease,transform .32s ease!important}.sidebar nav a.nav-fading-out .nav-badge{background:var(--cream)!important;color:var(--brown)!important;transition:background .3s ease,color .3s ease!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]{color:var(--cream)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]:before{opacity:0!important;transform:scale(.96)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page] .nav-badge{background:var(--cream)!important;color:var(--brown)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running{color:var(--brown)!important;transition:color .3s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running:before{opacity:1!important;transform:scale(1)!important;transition:opacity .32s cubic-bezier(.16,1,.3,1),transform .32s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running .nav-badge{background:var(--brown)!important;color:var(--cream)!important;transition:background .3s cubic-bezier(.16,1,.3,1),color .3s cubic-bezier(.16,1,.3,1)!important}.nav-badge{display:grid;place-items:center;min-width:24px;height:20px;padding:0 8px;border-radius:999px;background:var(--cream);color:var(--brown);font-size:12px;font-weight:700;line-height:1;transition:background .22s ease,color .22s ease}.sidebar nav a[aria-current=page] .nav-badge{background:var(--brown);color:var(--cream)}.shell{margin-left:277px;min-height:calc(100vh - 30px);height:auto;padding:0 54px 30px;background:linear-gradient(180deg,hsla(36,71%,97%,.98),hsla(36,56%,95%,.98));border-radius:22px;box-shadow:0 30px 60px rgba(92,62,48,.12)}.topbar{height:80px;align-items:center;justify-content:space-between;border-bottom:1px solid #c9a797;gap:20px}h1{margin:0;font:700 34px/1.2 DM Sans,Segoe UI,system-ui,sans-serif;letter-spacing:-1.2px}.dorms-page h1,.map-page h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-weight:700;letter-spacing:-.6px}.profile{display:flex;align-items:center;gap:26px;padding-right:3px}.btn-topbar-guide{position:relative;display:grid;place-items:center;width:28px;height:34px;padding:0;border:0;border-radius:50%;color:var(--brown);cursor:pointer;transition:color .18s ease,transform .18s ease;margin-right:0;text-decoration:none}.btn-topbar-guide,.btn-topbar-guide:hover{background:transparent!important;box-shadow:none!important}.btn-topbar-guide:hover{border-color:transparent!important;transform:scale(1.08);color:#5c3528}.btn-topbar-guide svg{width:27px;height:27px;stroke-width:2.1;color:var(--brown);transition:transform .18s ease}.btn-topbar-guide svg circle{transition:fill .18s ease,stroke .18s ease}.btn-topbar-guide svg line,.btn-topbar-guide svg path{transition:stroke .18s ease}.btn-topbar-guide:active svg circle,.btn-topbar-guide:hover svg circle,.btn-topbar-guide[aria-expanded=true] svg circle{fill:var(--brown);stroke:var(--brown)}.btn-topbar-guide:active svg line,.btn-topbar-guide:active svg path,.btn-topbar-guide:hover svg line,.btn-topbar-guide:hover svg path,.btn-topbar-guide[aria-expanded=true] svg line,.btn-topbar-guide[aria-expanded=true] svg path{stroke:#fff}.btn-topbar-guide:active{color:#4a2a1e;transform:scale(.92)}.btn-topbar-guide:active svg circle{fill:#4a2a1e;stroke:#4a2a1e}.btn-topbar-guide span{display:none!important}.topbar-auth{display:flex;align-items:center;gap:12px}.topbar-auth .btn-topbar-login{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--brown);background:var(--brown);color:#fff;cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-login:hover{color:#fff;background:#5c3527;border-color:#5c3527;transform:scale(1.1)}.topbar-auth .btn-topbar-login:active{color:#fff;background:#452618;border-color:#452618}.topbar-auth .btn-topbar-signup{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid #e8ddd3;background:#fdfaf5;color:var(--brown);cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-signup:hover{filter:brightness(.95);transform:scale(1.1)}.bell{position:relative;display:grid;place-items:center;width:28px;height:34px;border:0;border-radius:50%;padding:0;color:var(--brown);background:transparent;cursor:pointer;transition:color .18s ease,background-color .18s ease,box-shadow .18s ease,transform .18s ease}.bell svg{width:27px;height:27px;stroke-width:2.1;transition:fill .18s ease,transform .18s ease}.notification-badge{position:absolute;top:-4px;right:-8px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#a33d2d;color:#fff;font:600 9px/1 Segoe UI,system-ui,sans-serif}.notification-badge[hidden]{display:none}.bell:hover svg{fill:currentColor}.bell:active{color:#4a2a1e;transform:scale(.92)}.bell[aria-expanded=true] svg{fill:currentColor}.avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;border:0;padding:0;background:#f0e3d4 50%/cover no-repeat;color:var(--brown);font-weight:700;font-size:14px;cursor:pointer;overflow:hidden;position:relative;transition:box-shadow .18s ease,transform .18s ease}.avatar img,.avatar-img{width:100%;height:100%;border-radius:50%;-o-object-fit:cover;object-fit:cover;display:block}.avatar:hover{box-shadow:0 0 0 3px var(--line)}.avatar[aria-expanded=true]{box-shadow:0 0 0 3px #e4cbbf}.avatar:active{transform:scale(.94)}main{padding-top:38px}.summary{display:grid;width:min(100%,600px);margin-inline:auto;grid-template-columns:repeat(4,minmax(0,1fr));gap:30px}.card{position:relative;min-height:142px;padding:26px 20px 20px;border-radius:21px;background:var(--cream);box-shadow:6px 7px 9px #00000006}.card>svg{position:absolute;top:23px;right:20px;width:18px;height:18px}.card:nth-child(n+3)>svg{color:#75652e}.label{color:var(--text);font-size:12px;font-weight:500;padding-right:24px;text-transform:uppercase}.value{font-size:20px;font-weight:600;margin:5px 0 7px;line-height:1.4}.value small{font-weight:400}.detail,.value small{font-size:14px;color:var(--text)}.dashboard-grid{display:grid;width:min(100%,600px);grid-template-columns:1.55fr 1fr;gap:44px;margin:25px auto 0}h2{margin:0 0 4px 4px;font-size:26px;font-weight:650;letter-spacing:-.8px;line-height:1.45}.placeholder{height:333px;background:#d9d9d9;border-radius:16px}a.placeholder{display:block}.dormitories{margin-top:22px}.popover{z-index:1;top:calc(100% + 14px);right:0;width:340px;max-width:calc(100vw - 72px);border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08);overflow:hidden}html body .topbar:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}html body .profile:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}.topbar.is-popover-open,.topbar:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.profile.is-popover-open,.profile:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.popover:not([hidden]):not(.is-closing){z-index:1000000!important}.popover h2{margin:0;padding:12px 16px;background:var(--cream);border-bottom:1px solid var(--line);font-size:13px;letter-spacing:0}.empty{text-align:center;padding:26px 16px 24px}.empty-icon{display:grid;place-items:center;margin:0 auto 12px;width:38px;height:38px;border-radius:50%;color:#93867e;background:var(--cream)}.empty strong{display:block;font-size:13px;font-weight:500}.empty p{margin:4px 0 0;color:var(--text);font-size:11px}.notification-list{list-style:none;margin:0;padding:0;max-height:min(340px,45vh);overflow:auto}.notification-row{display:flex;align-items:stretch;border-top:1px solid var(--line);background:#fff}.notification-entry{display:grid;grid-template-columns:28px minmax(0,1fr);gap:4px 10px;flex:1;min-width:0;text-align:left;padding:14px 16px;background:#fff;color:var(--ink);border:0}.notification-entry.is-unread{background:#fcf7ef}.notification-entry:hover{background:var(--cream)}.notification-entry .entry-icon{grid-column:1;grid-row:1/4;display:grid;place-items:center;width:28px;height:28px;border-radius:9px;background:#f0e3d4;color:var(--brown);font-style:normal}.notification-entry strong{grid-column:2;display:block;font-size:13px}.notification-entry span{grid-column:2;display:block;font-size:12px;color:var(--text);margin-top:0}.notification-entry small{grid-column:2;display:block;font-size:10px;color:var(--brown);margin-top:3px}.notification-delete{margin:0 10px 0 0;padding:6px 8px}.notification-delete,.notification-mark-read{align-self:center;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--brown);font-size:11px}.notification-mark-read{margin:0 12px 0 4px;padding:5px 10px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .18s ease;flex-shrink:0}.notification-mark-read:hover{background:#f0e3d4;border-color:#d1bba4;color:#4a2511;transform:translateY(-1px)}.notification-delete:hover{background:#f3e8db}.notification-actions{display:flex;justify-content:flex-end;gap:8px;padding:8px 12px;border-top:1px solid var(--line);background:var(--cream)}.notification-actions button{border:0;padding:5px 7px;background:transparent;color:var(--brown);font-size:11px}.notification-actions button:hover{text-decoration:underline}.view-all{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;border:0;border-top:1px solid var(--line);padding:10px 16px;background:var(--cream);color:var(--text);font-size:12px}.view-all svg{width:15px;height:15px}.view-all:hover{color:var(--brown);text-decoration:none}::-webkit-scrollbar-track{background:transparent!important}*{scrollbar-color:#7a4b3a transparent}.profile-popover{width:260px}.profile-summary{display:flex;align-items:center;gap:12px;padding:16px}.profile-photo{position:relative;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#f0e3d4;color:var(--brown);font-weight:700;font-size:15px}.profile-photo img{position:absolute;inset:0;width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-identity{display:grid;gap:2px;min-width:0}.profile-identity strong{font-size:14px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-role{font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--text)}.profile-role-row{display:flex;align-items:center;gap:6px;min-width:0;line-height:1.2}.profile-role-sep{color:#a89487;font-size:8px;line-height:1}.profile-view-link{font-size:12px;font-weight:700;color:var(--brown,#7a4b3a);text-decoration:none!important;white-space:nowrap;transition:color .15s ease,background .15s ease;padding:2px 6px;border-radius:6px}.profile-view-link:hover{text-decoration:none!important;color:#3b1e10;background:rgba(122,75,58,.08)}.profile-view-link:active{text-decoration:none!important;color:#261109;background:rgba(122,75,58,.16)}.profile-popover .view-all{text-decoration:none!important;transition:background .15s ease,color .15s ease}.profile-popover .view-all:hover{text-decoration:none!important;background:#f5ece2;color:#3b1e10}.profile-popover .view-all:active{text-decoration:none!important;background:#ebdec9;color:#261109}.profile-logout{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;text-decoration:none!important}.profile-logout:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-logout:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@media (prefers-reduced-motion:no-preference){.popover{animation:popover-in .18s ease}.popover.is-closing{animation:popover-out .16s ease forwards}.bell[aria-expanded=true] svg{animation:bell-pop .45s ease}}@keyframes popover-in{0%{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}@keyframes popover-out{0%{opacity:1;transform:none}to{opacity:0;transform:translateY(-6px) scale(.98)}}@keyframes bell-pop{0%{transform:scale(1)}35%{transform:scale(1.18) rotate(-8deg)}70%{transform:scale(.94) rotate(4deg)}to{transform:scale(1)}}.notification-toast{position:fixed;z-index:100000;right:24px;bottom:24px;display:flex;flex-direction:column;justify-content:center;gap:8px;width:min(400px,calc(100vw - 32px));min-height:108px;box-sizing:border-box;padding:16px 20px;border:none;border-radius:16px;background:#ecdccb;color:#3b2820;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);opacity:0;transform:translateY(24px) scale(.96);pointer-events:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1)}.notification-toast.is-visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}.notification-toast.is-hiding{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.notification-toast .toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%}.notification-toast strong{font-size:15px;font-weight:700;color:#3b2820;line-height:1.35}.notification-toast span{font-size:13.5px;font-weight:400;color:#5a4236;line-height:1.45;display:block}.notification-toast .toast-close-btn{background:transparent;border:none;color:#7a4b3a;font-size:24px;line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;transition:color .15s,transform .15s}.notification-toast .toast-close-btn:hover{color:#3b2820;transform:scale(1.15)}.notification-toast .toast-actions{display:flex;align-items:center;gap:10px;margin-top:4px}@media (prefers-reduced-motion:reduce){.notification-toast,.notification-toast.is-hiding{transition:none}}dialog{border:1px solid #ebd9cb;border-radius:24px;padding:0;width:540px;max-width:calc(100vw - 32px);max-height:85vh;color:#3b2820;background:#fffdf9;box-shadow:0 28px 70px rgba(55,31,22,.28);font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden}dialog::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}.dialog-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px 18px;border-bottom:1px solid #ebd9cb;background:#fffdf9}.dialog-header h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:22px;color:#3b2820}.close{border:none;border-radius:0;width:auto;height:auto;padding:0;display:inline-flex;align-items:center;justify-content:center;color:#7a4b3a;background:none;cursor:pointer;box-shadow:none;font-size:26px;line-height:1;transition:color .15s ease,transform .15s ease}.close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.close:active{background:none;color:#1a0f0a;transform:scale(.92)}.close svg{width:18px;height:18px;fill:currentColor;stroke:currentColor}#feedback{padding:0 20px;font-size:12px}#feedback:empty{display:none}@media (min-width:901px){.dashboard-page .topbar{height:62px}.dashboard-page h1{font-size:28px;letter-spacing:-.7px}.dashboard-page .profile{gap:19px}.dashboard-page .bell,.dashboard-page .btn-theme-toggle,.dashboard-page .btn-topbar-guide{width:24px;height:30px}.dashboard-page .bell svg,.dashboard-page .btn-theme-toggle svg,.dashboard-page .btn-topbar-guide svg{width:22px;height:22px}.dashboard-page .avatar{width:32px;height:32px}.dashboard-page main{padding-top:30px}.dashboard-page .summary{width:min(100%,470px);grid-template-columns:repeat(4,minmax(0,1fr));gap:22px;align-items:stretch}.dashboard-page .card{min-height:167px;padding:19px 15px 14px;border-radius:19px}.dashboard-page .card>svg{top:19px;right:14px;width:15px;height:15px}.dashboard-page .label{padding-right:18px;font-size:10px;line-height:1.4}.dashboard-page .value{margin:3px 0 5px;font-size:16px;line-height:1.35}.dashboard-page .value small{font-size:11px}.dashboard-page .detail{font-size:11px;line-height:1.5}.dashboard-page .dashboard-grid{width:min(100%,470px);grid-template-columns:1.55fr 1fr;gap:34px;margin-top:21px}.dashboard-page h2{margin:0 0 4px 3px;font-size:20px;letter-spacing:-.4px;line-height:1.3}.dashboard-page .placeholder{height:260px;border-radius:12px}.dashboard-page .dormitories{margin-top:25px}}@media (max-width:1150px){.sidebar{width:220px}.shell{margin-left:238px;padding:0 28px 28px}.dashboard-grid{gap:24px}}@media (min-width:761px) and (max-width:900px){body{padding-right:6px;padding-left:4px}.sidebar{left:1.3vw;width:16.7vw;padding:12px 10px;border-radius:13px}.brand{gap:6px;margin:0 1px 10px;font-size:14px}.brand img{width:30px;height:30px}.sidebar nav{gap:4px}.sidebar nav a{grid-template-columns:14px minmax(0,1fr) auto;gap:6px;min-height:30px;padding:6px 8px;border-radius:7px;font-size:9px;letter-spacing:0}.sidebar nav a svg{width:14px;height:14px}.shell{margin-top:-10px;margin-left:18.8vw;padding:0 34px 4px 3.3vw;border-radius:12px 12px 0 0}.topbar{height:53px;gap:12px}h1{font-size:20px;letter-spacing:-.5px}.profile{gap:12px}.bell,.btn-theme-toggle,.btn-topbar-guide{width:20px;height:24px}.bell svg,.btn-theme-toggle svg,.btn-topbar-guide svg{width:18px;height:18px}.bell:after{width:7px;height:7px}.avatar{width:24px;height:24px}main{padding-top:19px}.summary{width:100%;gap:16px}.card{min-height:78px;padding:12px 11px 10px;border-radius:13px}.card>svg{top:12px;right:11px;width:13px;height:13px}.label{padding-right:16px;font-size:8px;line-height:1.35}.value{margin:2px 0 4px;font-size:14px;line-height:1.3}.value small{font-size:10px}.detail{font-size:8px;line-height:1.35}.dashboard-grid{width:100%;grid-template-columns:1.55fr 1fr;gap:24px;margin-top:15px}h2{margin:0 0 0 2px;font-size:16px;letter-spacing:-.3px;line-height:1.05}.placeholder{height:min(44.5vh,178px);border-radius:10px}.dormitories{margin-top:12px}}input[type=search]::-webkit-search-cancel-button{cursor:pointer}input[type=search]::-webkit-search-cancel-button:active,input[type=search]::-webkit-search-cancel-button:hover{cursor:pointer}@media (max-width:760px){body{padding:12px}.sidebar{position:static;width:auto;padding:14px;margin-bottom:12px;border-radius:17px}.brand{margin-bottom:14px}.brand img{width:40px;height:40px}nav{display:flex;gap:4px;flex-wrap:wrap}nav a{padding:8px 10px;min-height:38px;font-size:12px;gap:7px}.shell{margin:0;padding:0 20px 24px;border-radius:17px}h1{font-size:29px}.topbar{height:76px}.profile{gap:16px}.avatar{width:32px;height:32px}main{padding-top:24px}.dashboard-grid{grid-template-columns:1fr}.placeholder{height:260px}h2{font-size:24px}}@media (max-width:420px){.shell{padding:0 16px 24px}.summary{gap:12px}.card{padding:20px 14px;min-height:152px}.card>svg{position:static;margin-bottom:8px}.label{padding:0;font-size:10px}.value{font-size:17px}.detail{font-size:12px}.profile{gap:12px}.avatar{width:28px;height:28px}}.brand{overflow:hidden;padding-right:38px}.brand img{flex-shrink:0}.brand-name,.nav-label,.toggle-label{white-space:nowrap;overflow:hidden}.sidebar-toggle{display:none}@media (min-width:761px){.sidebar-toggle{display:grid;place-items:center;position:absolute;top:50%;right:-13px;transform:translateY(-50%);z-index:50;align-items:center;width:26px;height:26px;margin:0;padding:0;border:2px solid #fdf9f3;border-radius:50%;background:#633c2e;color:var(--cream);box-shadow:0 2px 8px rgba(0,0,0,.22);font-size:13px;font-weight:500;cursor:pointer;transition:background .2s ease,transform .3s ease}.sidebar-toggle:hover{background:#7a4b3a;color:var(--cream)}.toggle-circle{display:grid;place-items:center;flex:0 0 20px;width:20px;height:20px;border-radius:50%;background:transparent}.toggle-label{display:none}.toggle-circle svg{width:12px;height:12px;stroke-width:2.2;transition:transform .3s ease}.brand-name,.nav-badge,.nav-label,.toggle-label{transition:opacity .2s ease}html.sidebar-ready .sidebar{transition:width .3s ease}html.sidebar-ready .shell{transition:margin-left .3s ease}html.sidebar-collapsed .sidebar{width:88px}html.sidebar-collapsed .shell{margin-left:106px}html.sidebar-collapsed .brand-name,html.sidebar-collapsed .nav-badge,html.sidebar-collapsed .nav-label,html.sidebar-collapsed .toggle-label{opacity:0;pointer-events:none}html.sidebar-collapsed .brand{overflow:visible;padding-right:0}html.sidebar-collapsed .brand-name{display:none}html.sidebar-collapsed .brand img{width:42px;height:42px}html.sidebar-collapsed .sidebar-toggle{top:50%;right:-13px;transform:translateY(-50%)}html.sidebar-collapsed .toggle-circle svg{transform:rotate(180deg)}}@media (min-width:761px) and (max-width:900px){.sidebar-toggle{gap:0;padding:0;width:24px;height:24px}.sidebar-toggle,html.sidebar-collapsed .sidebar-toggle{top:50%;right:-12px;transform:translateY(-50%)}.toggle-circle{flex-basis:16px;width:16px;height:16px}.toggle-circle svg{width:10px;height:10px}html.sidebar-collapsed .sidebar{width:52px}html.sidebar-collapsed .admin-shell,html.sidebar-collapsed .shell{margin-left:calc(2.1vw + 52px)}}@media (min-width:901px){.admin-shell{margin-left:277px!important;transition:margin-left .24s cubic-bezier(.2,.8,.2,1)}html.sidebar-collapsed .admin-shell{margin-left:96px!important}}.logout-modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(30,15,10,.58);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}@keyframes logoutModalFadeOut{0%{opacity:1;backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}to{opacity:0;backdrop-filter:blur(0);-webkit-backdrop-filter:blur(0)}}@keyframes logoutCardPopOut{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(16px) scale(.92)}}.logout-modal-backdrop.is-active:not(.is-closing){opacity:1;pointer-events:auto}.logout-modal-backdrop.is-closing{pointer-events:none;animation:logoutModalFadeOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-card{background:#fff;border-radius:20px;max-width:420px;width:100%;padding:28px 28px 24px;box-shadow:0 20px 50px rgba(45,25,18,.28);border:1px solid #ebd9cb;transform:translateY(18px) scale(.94);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease;text-align:center}.logout-modal-backdrop.is-active:not(.is-closing) .logout-modal-card{transform:translateY(0) scale(1);opacity:1}.logout-modal-backdrop.is-closing .logout-modal-card{animation:logoutCardPopOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-icon{margin:0 auto 16px;border:none;display:flex;align-items:center;justify-content:center;box-shadow:none}.logout-modal-icon,.logout-modal-logo-img{width:68px;height:68px;background:transparent;border-radius:50%}.logout-modal-logo-img{-o-object-fit:cover;object-fit:cover;display:block}.logout-modal-title{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:700;font-size:1.3rem;color:#292524;margin:0 0 8px}.logout-modal-desc{font-size:.92rem;color:#78665c;line-height:1.5;margin:0 0 24px}.logout-modal-actions{display:flex;gap:12px;justify-content:center}.btn-logout-cancel{flex:1;padding:12px 18px;border-radius:10px;background:#fbf8f5;border:1px solid #ebd9cb;color:#57534e;font-weight:600;font-size:.92rem;cursor:pointer;transition:background .18s ease,border-color .18s ease,color .18s ease;box-shadow:0 1px 3px rgba(0,0,0,.05)}.btn-logout-cancel:hover{background:#f5eeea;color:#292524}.btn-logout-cancel:active{background:#ede2db}.btn-logout-confirm{flex:1;padding:12px 18px;border-radius:10px;background:#dc2626;border:none;color:#fff!important;font-weight:600;font-size:.92rem;cursor:pointer;text-decoration:none!important;display:inline-flex;align-items:center;justify-content:center;transition:transform .18s cubic-bezier(.34,1.56,.64,1),background .18s ease,box-shadow .18s ease;box-shadow:0 2px 6px rgba(220,38,38,.25)}.btn-logout-confirm:hover{background:#b91c1c;transform:scale(1.05);box-shadow:0 6px 18px rgba(220,38,38,.35)}.btn-logout-confirm:active{background:#991b1b;transform:scale(1.09);box-shadow:0 8px 22px rgba(220,38,38,.45)}.admin-logout-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 16px;border-radius:999px;font-size:.84rem;font-weight:600;color:#7a4b3a!important;background:#fff;border:1px solid #dfc3b2;text-decoration:none!important;transition:all .18s;cursor:pointer;box-shadow:0 1px 3px rgba(60,30,20,.05)}.admin-logout-btn:hover{background:#7a4b3a;color:#fff!important;border-color:#7a4b3a;transform:translateY(-1px)}.sidebar nav a.nav-logout{margin-top:14px;border-top:1px solid hsla(0,0%,100%,.14);padding-top:12px;color:#f7dcd0}.sidebar nav a.nav-logout:hover{background:rgba(220,38,38,.22);color:#fff}.sidebar nav a.nav-logout svg{stroke:currentColor}html:not([data-theme=dark]) #notification-popover{background:#f3e8db!important;border:1px solid #e8ded6!important;color:#3b2820!important;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08)!important;z-index:10000}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover .empty strong,html:not([data-theme=dark]) #notification-popover .notification-detail h3,html:not([data-theme=dark]) #notification-popover .notification-entry strong,html:not([data-theme=dark]) #notification-popover h2,html:not([data-theme=dark]) #notification-popover strong{color:#3b2820!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty p,html:not([data-theme=dark]) #notification-popover .notification-detail p,html:not([data-theme=dark]) #notification-popover .notification-entry span,html:not([data-theme=dark]) #notification-popover p,html:not([data-theme=dark]) #notification-popover span{color:#78665c!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover #view-all,html:not([data-theme=dark]) #notification-popover .notification-entry small,html:not([data-theme=dark]) #notification-popover .view-all,html:not([data-theme=dark]) #notification-popover small{color:#7a4b3a!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty,html:not([data-theme=dark]) #notification-popover .notification-actions,html:not([data-theme=dark]) #notification-popover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-list,html:not([data-theme=dark]) #notification-popover .notification-row{background:#f3e8db!important;border-color:#e8ddd3!important}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover h2{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-entry.is-unread{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .empty-icon,html:not([data-theme=dark]) #notification-popover .notification-entry .entry-icon{background:#f0e3d4!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-actions button,html:not([data-theme=dark]) #notification-popover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-mark-read{background:transparent!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-entry:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html:not([data-theme=dark]) #notification-popover .view-all{background:#f3e8db!important;border-top:1px solid #e8ddd3!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .view-all:hover{background:#ead7c3!important;color:#5c3527!important}#notification-popover a:focus-visible,#notification-popover button:focus-visible{outline:2px solid #7a4b3a;outline-offset:-3px}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3}#notification-popover .notification-row .notification-mark-read{transition:transform .18s ease,color .18s ease;border-color:transparent}html:not([data-theme=dark]) #notification-popover .notification-row:active,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:hover{background:#ead7c3}html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3;border-color:transparent}#notification-popover .notification-detail{padding:16px;background:#f3e8db}#notification-popover .notification-detail[hidden],#notification-popover [hidden]{display:none}.notification-detail button{background:transparent;color:#7a4b3a;border:1px solid #d8c3b4;border-radius:8px;padding:7px 10px;cursor:pointer}.notification-detail h3{font-size:.95rem;color:#3b2820;margin:16px 0 10px}.notification-detail p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5;font-size:.85rem}#notification-popover .notification-list{overscroll-behavior:contain}#notification-popover .notification-detail,#notification-popover .notification-list{max-height:min(340px,45vh);overflow-y:auto}dialog.approval-contact-dialog{width:min(calc(100vw - 32px),480px);padding:0;border:0;border-radius:20px;background:#fffaf4;color:#3b2820;box-shadow:0 24px 60px rgba(55,31,22,.28)}dialog.approval-contact-dialog::backdrop{background:rgba(42,28,22,.55);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}.approval-contact-card{padding:26px}.approval-contact-icon{display:grid;place-items:center;width:52px;height:52px;margin-bottom:16px;border-radius:50%;background:#e5f4e8;color:#28733a;font-size:24px;font-weight:700}.approval-contact-card h2{margin:0 0 10px;font-family:Fraunces,Georgia,serif;font-size:1.55rem;color:#5f3425}.approval-contact-card p{margin:0;color:#6f5a4e;font-size:.92rem;line-height:1.6}.approval-contact-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.approval-contact-actions a,.approval-contact-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 16px;border-radius:9px;font:600 .85rem DM Sans,sans-serif;cursor:pointer;text-decoration:none;transition:transform .18s ease,background .18s ease,border-color .18s ease}.approval-contact-later{border:1px solid #d8c3b4;background:#fff;color:#6a4333}.approval-contact-view{border:1px solid #7a4b3a;background:#7a4b3a;color:#fff}.approval-contact-actions a:hover,.approval-contact-actions button:hover{transform:scale(1.04)}.approval-contact-view:hover{background:#5f3425;border-color:#5f3425;color:#fff}.approval-contact-actions a:active,.approval-contact-actions button:active{transform:scale(1.08)}.approval-contact-actions a:focus-visible,.approval-contact-actions button:focus-visible{outline:2px solid #7a4b3a;outline-offset:3px}@media (max-width:520px){.approval-contact-actions{flex-direction:column-reverse}.approval-contact-actions a,.approval-contact-actions button{width:100%}}.avatar span,.profile-photo .profile-initials{display:flex!important;align-items:center!important;justify-content:center!important;text-align:center!important;line-height:1!important;width:100%!important;height:100%!important}.profile-popover{width:290px;max-height:85vh;overflow-y:auto}.profile-switch-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted,#8c7569);padding:12px 16px 4px;border-top:1px solid var(--line,#ebd9cb)}.profile-accounts-list{display:flex;flex-direction:column;max-height:240px;overflow-y:auto;scrollbar-width:thin}.profile-account-item{display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer;text-decoration:none;color:inherit;transition:background .15s ease}.profile-account-item:hover{background:#fbf5ee}.profile-account-item.is-switching{opacity:.55;pointer-events:none}.profile-account-item .account-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-align:center;line-height:1;background:#f0e3d4;color:var(--brown,#7a4b3a);font-weight:700;font-size:13px;flex-shrink:0;overflow:hidden}.profile-account-item .account-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-account-item .account-details{min-width:0;flex:1}.profile-account-item .account-name{font-size:13px;font-weight:700;color:var(--head,#5f3425);display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-account-item .account-email{font-size:11px;color:var(--text-muted,#8c7569);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.account-role-badge{font-size:9px;padding:1px 6px;border-radius:999px;font-weight:600;background:#f3ece4;color:#6b3624;text-transform:capitalize}.account-role-badge.role-admin{background:#fef3c7;color:#92400e}.account-role-badge.role-landlord{background:#f0e3d4;color:#7a4b3a}.account-role-badge.role-tenant{background:#dcfce7;color:#166534}.profile-add-account{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;border-top:1px solid var(--line,#ebd9cb);text-decoration:none!important}.profile-add-account:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-add-account:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@keyframes unistayShimmer{0%{background-position:-200% 0}to{background-position:200% 0}}.skeleton-shimmer{background:linear-gradient(90deg,#ece5df 25%,#f7f2ed 37%,#ece5df 63%)!important;background-size:400% 100%!important;animation:unistayShimmer 1.5s ease-in-out infinite!important}.skeleton-card{pointer-events:none!important;cursor:default!important;transform:none!important}.skeleton-card:hover{transform:none!important;box-shadow:0 6px 20px rgba(60,30,20,.06)!important;border-color:#ebd9cb!important}.skeleton-card-thumb{width:100%;aspect-ratio:16/10;border-radius:0}.skeleton-card-body{padding:20px 20px 18px;display:flex;flex-direction:column;gap:10px}.skeleton-line{height:14px;border-radius:6px}.skeleton-line-title{width:72%;height:20px}.skeleton-line-sub{width:48%;height:13px}.skeleton-line-price{width:38%;height:16px;margin-top:4px}.skeleton-card-btn{height:40px;width:100%;border-radius:10px;margin-top:10px}.profile-account-item{border:none;background:transparent;width:100%;text-align:left;font-family:inherit;font-size:inherit;box-sizing:border-box}.profile-switch-empty{padding:8px 16px;font-size:11px;color:var(--text-muted,#8c7569);font-style:italic}.admin-user-badge{position:relative!important;cursor:pointer!important;-webkit-user-select:none!important;-moz-user-select:none!important;user-select:none!important}#notification-page,#notification-popover .notification-detail,#notification-popover .notification-list,.full-inbox,.notification-list{scrollbar-width:thin;scrollbar-color:var(--brown,#7a4b3a) transparent}#notification-page::-webkit-scrollbar,#notification-popover .notification-detail::-webkit-scrollbar,#notification-popover .notification-list::-webkit-scrollbar,.full-inbox::-webkit-scrollbar,.notification-list::-webkit-scrollbar{width:8px;height:8px}#notification-page::-webkit-scrollbar-track,#notification-popover .notification-detail::-webkit-scrollbar-track,#notification-popover .notification-list::-webkit-scrollbar-track,.full-inbox::-webkit-scrollbar-track,.notification-list::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}#notification-page::-webkit-scrollbar-thumb,#notification-popover .notification-detail::-webkit-scrollbar-thumb,#notification-popover .notification-list::-webkit-scrollbar-thumb,.full-inbox::-webkit-scrollbar-thumb,.notification-list::-webkit-scrollbar-thumb{background:var(--brown,#7a4b3a);border:2px solid #f3e9df;border-radius:999px}#notification-page::-webkit-scrollbar-thumb:hover,#notification-popover .notification-detail::-webkit-scrollbar-thumb:hover,#notification-popover .notification-list::-webkit-scrollbar-thumb:hover,.full-inbox::-webkit-scrollbar-thumb:hover,.notification-list::-webkit-scrollbar-thumb:hover{background:#5f3425}.btn-action-reject,.btn-danger-outline,.btn-decline,.btn-delete,.btn-reject,.btn-settings-danger,.dorm-card-actions .dorm-delete-btn,.dorm-delete-btn,.rv-btn-danger{background:#fff!important;border:1px solid #fca5a5!important;color:#dc2626!important;box-shadow:0 2px 6px rgba(220,38,38,.08)!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important;font-size:13px!important;padding:9px 18px!important;border-radius:10px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;transform:scale(1)!important;transform-origin:center!important;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease!important;text-decoration:none!important}.btn-action-reject:hover:not(:disabled):not(.is-loading),.btn-danger-outline:hover:not(:disabled):not(.is-loading),.btn-decline:hover:not(:disabled):not(.is-loading),.btn-delete:hover:not(:disabled):not(.is-loading),.btn-reject:hover:not(:disabled):not(.is-loading),.btn-settings-danger:hover:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:hover:not(:disabled):not(.is-loading),.dorm-delete-btn:hover:not(:disabled):not(.is-loading),.rv-btn-danger:hover:not(:disabled):not(.is-loading){background:#fee2e2!important;border-color:#f87171!important;color:#b91c1c!important;transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(220,38,38,.2)!important}.btn-action-reject:active:not(:disabled):not(.is-loading),.btn-danger-outline:active:not(:disabled):not(.is-loading),.btn-decline:active:not(:disabled):not(.is-loading),.btn-delete:active:not(:disabled):not(.is-loading),.btn-reject:active:not(:disabled):not(.is-loading),.btn-settings-danger:active:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:active:not(:disabled):not(.is-loading),.dorm-delete-btn:active:not(:disabled):not(.is-loading),.rv-btn-danger:active:not(:disabled):not(.is-loading){background:#fecaca!important;transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(220,38,38,.26)!important}.nav-badge-pill,.sidebar .nav-badge-pill{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font-family:DM Sans,sans-serif;font-size:.72rem;font-weight:700;line-height:1;margin-left:auto;box-shadow:0 1px 3px rgba(220,38,38,.35);pointer-events:none;transition:transform .2s cubic-bezier(.2,0,0,1),opacity .2s ease}.sidebar.collapsed .nav-badge-pill{display:none}@keyframes bellShake{0%{transform:rotate(0)}15%{transform:rotate(14deg)}30%{transform:rotate(-14deg)}45%{transform:rotate(10deg)}60%{transform:rotate(-10deg)}75%{transform:rotate(6deg)}to{transform:rotate(0)}}#notification-bell.bell-ringing,.bell.bell-ringing{animation:bellShake .8s ease-in-out!important}.unistay-lightbox-overlay,dialog.unistay-lightbox-modal{position:fixed;inset:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;margin:0;padding:24px;border:none;background:transparent;display:flex;align-items:center;justify-content:center;z-index:1000001;overflow:hidden;box-sizing:border-box}dialog.unistay-lightbox-modal:not([open]){display:none!important}dialog.unistay-lightbox-modal::backdrop{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);animation:backdrop-fade-in .26s ease both}dialog.unistay-lightbox-modal.is-closing::backdrop{animation:backdrop-fade-out .22s ease both}.unistay-lightbox-overlay{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}.unistay-lightbox-overlay.is-open:not(.is-closing){opacity:1;pointer-events:auto}.unistay-lightbox-overlay.is-closing{opacity:0;pointer-events:none;transition:opacity .22s ease,backdrop-filter .22s ease,-webkit-backdrop-filter .22s ease}.unistay-lightbox-content{position:relative;max-width:90vw;max-height:88vh;display:flex;flex-direction:column;align-items:center;transform:scale(.92) translateY(12px);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease}.unistay-lightbox-overlay.is-open:not(.is-closing) .unistay-lightbox-content,dialog.unistay-lightbox-modal[open]:not(.is-closing) .unistay-lightbox-content{transform:scale(1) translateY(0);opacity:1}.unistay-lightbox-overlay.is-closing .unistay-lightbox-content,dialog.unistay-lightbox-modal.is-closing .unistay-lightbox-content{transform:scale(.94) translateY(10px);opacity:0;transition:transform .22s ease,opacity .2s ease}.unistay-lightbox-img{max-width:100%;max-height:80vh;-o-object-fit:contain;object-fit:contain;border-radius:14px;box-shadow:0 24px 50px rgba(0,0,0,.45)}.unistay-lightbox-caption{color:#fff;margin-top:14px;font-weight:600;font-size:1rem;text-align:center;text-shadow:0 1px 4px rgba(0,0,0,.5);max-width:80vw}.unistay-lightbox-close{position:absolute;top:-44px;right:0;background:none;border:none;border-radius:0;width:auto;height:auto;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;cursor:pointer;line-height:1;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.unistay-lightbox-close:hover{background:none;color:#e5d8cf;transform:scale(1.1)}.unistay-lightbox-close:active{background:none;color:#c9b7ad;transform:scale(.92)}.tenant-page .topbar .btn-theme-toggle{width:28px;height:34px;padding:0;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;color:var(--ink,#3b2820);transition:transform .18s ease,color .18s ease}.tenant-page .topbar .btn-theme-toggle svg{width:27px;height:27px;stroke-width:2.1}.tenant-page .topbar .btn-theme-toggle svg circle,.tenant-page .topbar .btn-theme-toggle svg path{transition:fill .18s ease,stroke .18s ease}.tenant-page .topbar .btn-theme-toggle:focus-visible,.tenant-page .topbar .btn-theme-toggle:hover{background:transparent!important;border:0!important;box-shadow:none!important;color:#7a4b3a;transform:scale(1.08)}.tenant-page .topbar .btn-theme-toggle:focus-visible{outline:2px solid currentColor;outline-offset:3px}.tenant-page .topbar .btn-theme-toggle:active svg path,.tenant-page .topbar .btn-theme-toggle:hover svg path{fill:currentColor}.tenant-page .topbar .btn-theme-toggle:active{background:transparent!important;color:#5c3527;transform:scale(.92)}html[data-theme=dark],html[data-theme=dark] .tenant-page,html[data-theme=dark] body{background:#1e1612!important;color:#d9c8bc!important}html[data-theme=dark] .dorms-page .shell,html[data-theme=dark] .shell,html[data-theme=dark] .tenant-page .shell{background:#382a22!important;border:1px solid #3d2d24!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .dorms-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;box-shadow:none!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .sidebar,html[data-theme=dark] aside.sidebar{background:linear-gradient(135deg,#4a372c,#3a2820)!important;border-right:1px solid #553e32!important}html[data-theme=dark] .brand,html[data-theme=dark] .brand .brand-name,html[data-theme=dark] .brand span,html[data-theme=dark] .sidebar .brand span,html[data-theme=dark] .sidebar .brand-name{color:#fff7f2!important}html[data-theme=dark] .sidebar nav a,html[data-theme=dark] .sidebar nav a .nav-label,html[data-theme=dark] .sidebar nav a span{color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a svg{stroke:#d9c8bc!important}html[data-theme=dark] .sidebar nav a:hover{background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .sidebar nav a[aria-current=page]{background:transparent!important;color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a[aria-current=page]:before{background:hsla(25,28%,79%,.14)!important;opacity:1!important}html[data-theme=dark] #guide-launcher-btn,html[data-theme=dark] .btn-topbar-guide,html[data-theme=dark] .btn-topbar-guide svg,html[data-theme=dark] .btn-topbar-guide svg circle,html[data-theme=dark] .btn-topbar-guide svg line,html[data-theme=dark] .btn-topbar-guide svg path,html[data-theme=dark] .sidebar-toggle,html[data-theme=dark] .sidebar-toggle .toggle-circle,html[data-theme=dark] .sidebar-toggle .toggle-label,html[data-theme=dark] .sidebar-toggle svg{color:#d9c8bc!important;stroke:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle{background:transparent!important;border:0!important;box-shadow:none!important;color:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle svg,html[data-theme=dark] .btn-theme-toggle svg circle,html[data-theme=dark] .btn-theme-toggle svg path,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] .btn-theme-toggle:active svg circle,html[data-theme=dark] .btn-theme-toggle:hover svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover svg circle{fill:#d9c8bc!important}html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:focus-visible,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover{color:#d9c8bc!important}html[data-theme=dark] #notification-bell,html[data-theme=dark] #notification-bell svg,html[data-theme=dark] #notification-bell svg path,html[data-theme=dark] .topbar .bell,html[data-theme=dark] .topbar .bell svg,html[data-theme=dark] .topbar .bell svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] #notification-bell:active svg,html[data-theme=dark] #notification-bell:active svg path,html[data-theme=dark] #notification-bell:hover svg,html[data-theme=dark] #notification-bell:hover svg path,html[data-theme=dark] .topbar .bell:active svg,html[data-theme=dark] .topbar .bell:active svg path,html[data-theme=dark] .topbar .bell:hover svg,html[data-theme=dark] .topbar .bell:hover svg path{fill:#d9c8bc!important}@media (min-width:901px){.tenant-page.dashboard-page .topbar .btn-theme-toggle{width:24px;height:30px}.tenant-page.dashboard-page .topbar .btn-theme-toggle svg{width:22px;height:22px}}@media (min-width:761px) and (max-width:900px){.tenant-page .topbar .btn-theme-toggle{width:20px;height:24px}.tenant-page .topbar .btn-theme-toggle svg{width:18px;height:18px}}html[data-theme=dark] #notification-popover,html[data-theme=dark] .popover,html[data-theme=dark] .profile-popover{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 18px 42px rgba(0,0,0,.6),0 6px 14px rgba(0,0,0,.35)!important}html[data-theme=dark] #notification-popover h2,html[data-theme=dark] #popover-title,html[data-theme=dark] .popover h2{background:#241813!important;border-bottom:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty,html[data-theme=dark] #popover-empty{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty p,html[data-theme=dark] #notification-popover .empty strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-list{background:#241813!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-track,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-thumb,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] #notification-popover .notification-row{background:#241813!important;border-top:1px solid #453127!important}html[data-theme=dark] #notification-popover .notification-entry{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry.is-unread{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-entry:hover,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry{background:#38271e!important}html[data-theme=dark] #notification-popover .notification-entry span,html[data-theme=dark] #notification-popover .notification-entry strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry small{color:#e2ab94!important}html[data-theme=dark] #notification-popover .notification-entry .entry-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete,html[data-theme=dark] #notification-popover .notification-mark-read{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete:hover,html[data-theme=dark] #notification-popover .notification-mark-read:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail{background:#241813!important;color:#fff7f2!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail h3,html[data-theme=dark] #notification-popover .notification-detail p{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button:hover{background:hsla(25,28%,79%,.25)!important}html[data-theme=dark] #notification-popover .notification-actions,html[data-theme=dark] #notification-popover .view-all,html[data-theme=dark] #view-all,html[data-theme=dark] .profile-popover .view-all{background:#241813!important;border-top:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all:hover,html[data-theme=dark] #view-all:hover,html[data-theme=dark] .profile-popover .view-all:hover{background:#2d1f19!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all svg,html[data-theme=dark] #view-all svg{stroke:#fff7f2!important;color:#fff7f2!important}html[data-theme=dark] .map-page .shell,html[data-theme=dark] .settings-page .shell,html[data-theme=dark] .tenant-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .map-page .topbar,html[data-theme=dark] .settings-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .map-page h1,html[data-theme=dark] .settings-page h1,html[data-theme=dark] .tenant-page .topbar h1,html[data-theme=dark] .tenant-page h1{color:#fff7f2!important}html[data-theme=dark] .tenant-page .stat-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .card-label,html[data-theme=dark] .tenant-page .card-suffix,html[data-theme=dark] .tenant-page .card-value,html[data-theme=dark] .tenant-page .stat-card-label,html[data-theme=dark] .tenant-page .stat-card-value{color:#fff7f2!important}html[data-theme=dark] .tenant-page .card-subtext,html[data-theme=dark] .tenant-page .card-subtext-link,html[data-theme=dark] .tenant-page .stat-card-desc{color:#fff7f2!important;opacity:.9}html[data-theme=dark] .tenant-page .stat-card-icon,html[data-theme=dark] .tenant-page .stat-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .current-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .current-booking-header{background:#241813!important;border-bottom:1px solid #453127!important}html[data-theme=dark] .tenant-page .current-booking-body{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-detail-label,html[data-theme=dark] .tenant-page .booking-detail-value,html[data-theme=dark] .tenant-page .booking-dorm-title,html[data-theme=dark] .tenant-page .booking-room-title,html[data-theme=dark] .tenant-page .current-booking-header h2,html[data-theme=dark] .tenant-page .section-title{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill svg{color:#fff7f2!important;stroke:#fff7f2!important;fill:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn,html[data-theme=dark] .tenant-page .btn-action-outline,html[data-theme=dark] .tenant-page .btn-action-primary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn:hover,html[data-theme=dark] .tenant-page .btn-action-outline:hover,html[data-theme=dark] .tenant-page .btn-action-primary:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-message h3,html[data-theme=dark] .tenant-page .booking-empty-message p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-home{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .tenant-page .booking-dorm-sub,html[data-theme=dark] .tenant-page .booking-room-heading,html[data-theme=dark] .tenant-page .booking-room-heading span,html[data-theme=dark] .tenant-page .booking-tenant-meta,html[data-theme=dark] .tenant-page .booking-tenant-meta span,html[data-theme=dark] .tenant-page .recent-bookings-empty h3,html[data-theme=dark] .tenant-page .recent-bookings-empty p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-bookings-empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details,html[data-theme=dark] .tenant-page .btn-manage-properties,html[data-theme=dark] .tenant-page .recent-bookings-view-all{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details:hover,html[data-theme=dark] .tenant-page .btn-manage-properties:hover,html[data-theme=dark] .tenant-page .recent-bookings-view-all:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 16px 36px rgba(0,0,0,.5)!important}html[data-theme=dark] .tenant-page .profile-toast h3,html[data-theme=dark] .tenant-page .profile-toast p,html[data-theme=dark] .tenant-page .profile-toast-btn{color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail{background:#5c4538!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] .map-page .map-panel-nav{background:#5c4538!important;border-bottom:1px solid #6e5344!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav span,html[data-theme=dark] .map-page .map-panel-nav strong{color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav .room-back-icon{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card,html[data-theme=dark] .map-page .map-dorm-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .map-page .map-search-bar{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-search-bar input{background:transparent!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-tabs{border-top:1px solid #6e5344!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .settings-page .settings-tab-btn{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-tab-btn:hover{color:#fff7f2!important;background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .settings-page .settings-tab-btn.is-active{color:#fff7f2!important;background:hsla(25,28%,79%,.14)!important;font-weight:700!important}html[data-theme=dark] .settings-page .settings-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 4px 16px rgba(0,0,0,.4)!important}html[data-theme=dark] .settings-page .profile-view-header{border-bottom:1px solid #453127!important}html[data-theme=dark] .settings-page .field-label,html[data-theme=dark] .settings-page .form-label,html[data-theme=dark] .settings-page .profile-view-name,html[data-theme=dark] .settings-page .settings-card h2,html[data-theme=dark] .settings-page .settings-card h3,html[data-theme=dark] .settings-page .settings-card h4,html[data-theme=dark] .settings-page .settings-card label,html[data-theme=dark] .settings-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] .settings-page .field-hint,html[data-theme=dark] .settings-page .profile-view-email,html[data-theme=dark] .settings-page .settings-card p,html[data-theme=dark] .settings-page .settings-section-desc{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-card input,html[data-theme=dark] .settings-page .settings-card select,html[data-theme=dark] .settings-page .settings-card textarea{background:#19110d!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-card input:focus,html[data-theme=dark] .settings-page .settings-card select:focus,html[data-theme=dark] .settings-page .settings-card textarea:focus{border-color:#e2ab94!important;outline:none!important}html[data-theme=dark] .settings-page .btn-primary,html[data-theme=dark] .settings-page .btn-save-settings,html[data-theme=dark] .settings-page .settings-btn-primary{background:#e2ab94!important;color:#1e1612!important;font-weight:700!important}html[data-theme=dark] .settings-page .btn-secondary,html[data-theme=dark] .settings-page .settings-btn-secondary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .settings-page #settings-main{scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:3px solid #241813!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#f0ded2!important}.auth-gate-close,.cl-dialog-close,.dorm-modal-close,.drawer-close,.id-lightbox-close,.landlord-profile-close-btn,.modal-close-btn,.policy-modal-close,.profile-toast-close,.tenant-drawer-close,.toast-close,.toast-close-btn,.unistay-lightbox-close,.us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#7a4b3a;transition:color .15s ease,transform .15s ease}.auth-gate-close:hover,.cl-dialog-close:hover,.dorm-modal-close:hover,.drawer-close:hover,.id-lightbox-close:hover,.landlord-profile-close-btn:hover,.modal-close-btn:hover,.policy-modal-close:hover,.profile-toast-close:hover,.tenant-drawer-close:hover,.toast-close-btn:hover,.toast-close:hover,.unistay-lightbox-close:hover,.us-confirm-close:hover{background:transparent!important;color:#3b2820!important;transform:scale(1.1)}.auth-gate-close:active,.cl-dialog-close:active,.dorm-modal-close:active,.drawer-close:active,.id-lightbox-close:active,.landlord-profile-close-btn:active,.modal-close-btn:active,.policy-modal-close:active,.profile-toast-close:active,.tenant-drawer-close:active,.toast-close-btn:active,.toast-close:active,.unistay-lightbox-close:active,.us-confirm-close:active{background:transparent!important;color:#1a0f0a!important;transform:scale(.92)}.auth-gate-close:focus-visible,.cl-dialog-close:focus-visible,.dorm-modal-close:focus-visible,.drawer-close:focus-visible,.id-lightbox-close:focus-visible,.landlord-profile-close-btn:focus-visible,.modal-close-btn:focus-visible,.policy-modal-close:focus-visible,.profile-toast-close:focus-visible,.tenant-drawer-close:focus-visible,.toast-close-btn:focus-visible,.toast-close:focus-visible,.unistay-lightbox-close:focus-visible,.us-confirm-close:focus-visible{outline:2px solid currentColor;outline-offset:3px}html[data-theme=dark] .auth-gate-close,html[data-theme=dark] .cl-dialog-close,html[data-theme=dark] .dorm-modal-close,html[data-theme=dark] .drawer-close,html[data-theme=dark] .id-lightbox-close,html[data-theme=dark] .landlord-profile-close-btn,html[data-theme=dark] .modal-close-btn,html[data-theme=dark] .policy-modal-close,html[data-theme=dark] .profile-toast-close,html[data-theme=dark] .tenant-drawer-close,html[data-theme=dark] .toast-close,html[data-theme=dark] .toast-close-btn,html[data-theme=dark] .unistay-lightbox-close,html[data-theme=dark] .us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#fff7f2!important}html[data-theme=dark] .auth-gate-close:hover,html[data-theme=dark] .cl-dialog-close:hover,html[data-theme=dark] .dorm-modal-close:hover,html[data-theme=dark] .drawer-close:hover,html[data-theme=dark] .id-lightbox-close:hover,html[data-theme=dark] .landlord-profile-close-btn:hover,html[data-theme=dark] .modal-close-btn:hover,html[data-theme=dark] .policy-modal-close:hover,html[data-theme=dark] .profile-toast-close:hover,html[data-theme=dark] .tenant-drawer-close:hover,html[data-theme=dark] .toast-close-btn:hover,html[data-theme=dark] .toast-close:hover,html[data-theme=dark] .unistay-lightbox-close:hover,html[data-theme=dark] .us-confirm-close:hover{background:transparent!important;color:#d8c3b4!important}html[data-theme=dark] .auth-gate-close:active,html[data-theme=dark] .cl-dialog-close:active,html[data-theme=dark] .dorm-modal-close:active,html[data-theme=dark] .drawer-close:active,html[data-theme=dark] .id-lightbox-close:active,html[data-theme=dark] .landlord-profile-close-btn:active,html[data-theme=dark] .modal-close-btn:active,html[data-theme=dark] .policy-modal-close:active,html[data-theme=dark] .profile-toast-close:active,html[data-theme=dark] .tenant-drawer-close:active,html[data-theme=dark] .toast-close-btn:active,html[data-theme=dark] .toast-close:active,html[data-theme=dark] .unistay-lightbox-close:active,html[data-theme=dark] .us-confirm-close:active{background:transparent!important;color:#b99f90!important}html[data-theme=dark] .profile-summary{background:#231a14!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .profile-photo{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-identity strong{color:#fff7f2!important}html[data-theme=dark] .profile-role{color:#a8948a!important}html[data-theme=dark] .profile-role-sep{color:#6c584c!important}html[data-theme=dark] .profile-view-link{color:#e2ab94!important}html[data-theme=dark] .profile-view-link:hover{background:hsla(18,57%,73%,.16)!important;color:#fff7f2!important}html[data-theme=dark] .profile-switch-header{color:#a8948a!important;border-top-color:#3d2d24!important}html[data-theme=dark] .profile-switch-empty{color:#a8948a!important}html[data-theme=dark] .profile-account-item{color:#d9c8bc!important}html[data-theme=dark] .profile-account-item:hover{background:#34261f!important}html[data-theme=dark] .profile-account-item .account-avatar{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-account-item .account-name{color:#fff7f2!important}html[data-theme=dark] .profile-account-item .account-email{color:#a8948a!important}html[data-theme=dark] .profile-add-account,html[data-theme=dark] .profile-logout{color:#e2ab94!important}html[data-theme=dark] .profile-logout:hover{background:#34261f!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread){background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry.is-unread,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:transparent!important;border-color:transparent!important;box-shadow:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{color:#e2ab94!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read:hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{color:#fff7f2!important;background:transparent!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button{background:transparent!important;border:0!important;box-shadow:none!important;color:#fff7f2!important;text-decoration:none!important}html:not([data-theme=dark]) #notification-popover .notification-row,html:not([data-theme=dark]) #notification-popover .notification-row .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row .notification-mark-read{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread),html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html[data-theme=dark] #notification-popover .notification-row,html[data-theme=dark] #notification-popover .notification-row .notification-delete,html[data-theme=dark] #notification-popover .notification-row .notification-entry,html[data-theme=dark] #notification-popover .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread),html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-row:focus-within,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-entry,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry,html[data-theme=dark] #notification-popover .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:hover{background:transparent!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:active{background:transparent!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link,html[data-theme=dark] .profile-popover:not(#_a) .view-all{text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:hover{background:transparent!important;color:#cf967f!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:active{background:transparent!important;color:#b57f6a!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:hover{color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:active{color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all,html[data-theme=dark] #notification-popover:not(#_a) .view-all{background:#241813!important;color:#fff7f2!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover{background:#241813!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active{background:#241813!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover svg{stroke:#d8c3b4!important;color:#d8c3b4!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active svg{stroke:#b99f90!important;color:#b99f90!important}body.tenant-page #clear-all,body.tenant-page #notification-popover .notification-actions,body.tenant-page #read-all,body.tenant-page .notification-page-heading{display:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar h1{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tabs,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-categories-bar{background:#5c4538!important;border-color:#6e5344!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab{color:#e2d3c8!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab:hover{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tabs-indicator,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-indicator{background:#e2ab94!important}html[data-theme=dark] body.admin-users-page .tenants-table-card,html[data-theme=dark] body.admin-verification-page .tenant-card{background:#2b1f19!important;border-color:#4b382d!important;color:#e2d3c8!important}html[data-theme=dark] body.admin-users-page .tenants-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table td{color:#e2d3c8!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table tbody tr:hover,html[data-theme=dark] body.admin-verification-page .tenant-card:hover{background:#34261f!important}html[data-theme=dark] body.admin-users-page .tenants-table td strong,html[data-theme=dark] body.admin-verification-page .meta-value,html[data-theme=dark] body.admin-verification-page .tenant-head h3{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .meta-label,html[data-theme=dark] body.admin-verification-page .tenant-sub{color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .tenant-meta{border-top-color:#4b382d!important}html[data-theme=dark] body.admin-verification-page .um-select-btn,html[data-theme=dark] body.admin-verification-page .um-select-menu,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search{background:#231a14!important;border-color:#563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .um-select-menu li,html[data-theme=dark] body.admin-verification-page .um-select-value,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search input{color:#d9c8bc!important}html[data-theme=dark] body.admin-users-page .badge-status-suspended{color:#f87171!important}#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{position:fixed!important;right:24px!important;bottom:24px!important;width:min(400px,calc(100vw - 32px))!important;max-width:min(400px,calc(100vw - 32px))!important;min-width:min(400px,calc(100vw - 32px))!important;height:116px!important;min-height:116px!important;max-height:116px!important;box-sizing:border-box!important;padding:16px 20px!important;border-radius:16px!important;z-index:100000!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}@media (max-width:520px){#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{right:16px!important;bottom:16px!important;left:16px!important;width:auto!important;min-width:0!important;max-width:none!important;height:116px!important;min-height:116px!important;max-height:116px!important}}#confirmModal,#confirmModal *,#deleteModal,#deleteModal *,#dorm-modal,#dorm-modal *,#inspectModal,#inspectModal *,#logout-modal,#logout-modal *,#rejectModal,#rejectModal *,#resultModal,#resultModal *,#room-modal,#room-modal *,#suspend-modal,#suspend-modal *,.cl-dialog,.cl-dialog *,.confirm-modal-box,.confirm-modal-box *,.cropper-modal-card,.cropper-modal-card *,.delete-account-modal,.delete-account-modal *,.dialog,.dialog *,.inspect-box,.inspect-box *,.inspect-modal,.inspect-modal *,.logout-modal-card,.logout-modal-card *,.modal,.modal *,.modal-box,.modal-box *,.modal-card,.modal-card *,.modal-panel,.modal-panel *,.policy-modal,.policy-modal *,.policy-modal-panel,.policy-modal-panel *,.ps-card,.ps-card *,.settings-modal-card,.settings-modal-card *,.suspend-modal-dialog,.suspend-modal-dialog *,dialog,dialog *,dialog.us-confirm,dialog.us-confirm *{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}.cl-dialog,.confirm-modal-box,.cropper-modal-card,.delete-account-modal,.inspect-box,.logout-modal-card,.modal-box,.modal-card,.modal-panel,.policy-modal-panel,.ps-card,.settings-modal-card,.suspend-modal-dialog,dialog,dialog.us-confirm{border-radius:24px!important}#dorm-modal h2,#dorm-modal h3,#room-details-title,.cl-dialog h2,.cl-dialog h3,.cropper-modal-title,.delete-modal-title,.inspect-box h2,.inspect-box h3,.logout-modal-title,.modal h1,.modal h2,.modal h3,.modal h4,.modal-box h1,.modal-box h2,.modal-box h3,.modal-box h4,.modal-panel h1,.modal-panel h2,.modal-panel h3,.modal-panel h4,.policy-card h2,.policy-modal-header h2,.policy-modal-panel h2,.policy-modal-panel h3,.ps-card h2,.room-modal-header-title,.room-title-heading,.settings-modal-card h2,.settings-modal-card h3,.suspend-modal-dialog h2,.suspend-modal-dialog h3,dialog h1,dialog h2,dialog h3,dialog h4,dialog.us-confirm .us-confirm-panel h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px!important;font-weight:700!important;letter-spacing:-.2px!important;line-height:1.3!important}.cl-actions button,.cropper-modal-footer button,.delete-modal-actions button,.inspect-footer button,.policy-modal-footer button,.us-confirm-actions button,dialog button:not(.us-confirm-close):not(.cl-dialog-close):not(.policy-modal-close):not(.delete-modal-close):not(.cropper-btn-round):not(.modal-close):not(.close-btn):not([aria-label*=lose]):not([aria-label*=Dismiss]){border-radius:12px!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important}.verified-check{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:14px;height:14px;margin-left:5px;vertical-align:-2px;border-radius:50%;background:#10b981;color:#fff;overflow:visible}.verified-check svg{width:8px;height:8px;stroke-width:3.4;overflow:visible}.view-all,.view-all:active,.view-all:focus,.view-all:focus-visible,.view-all:hover{text-decoration:none!important}html[data-theme=dark] .shell-orbs{display:none!important}:root{--bg-sidebar:#7b4a3b;--bg-main:#fdf9f3;--bg-card:#fff;--bg-placeholder:#d9d9d9;--text-dark:#5c3327;--text-light:#fff;--text-muted:#8b7369;--border-color:#eae0d5;--line:#e8ddd3;--head:#6b3624;--brown:#7b4a3a;--brown-dark:#5c3527;--font-serif:"Georgia","Times New Roman",serif;--font-sans:"DM Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}*{margin:0;padding:0;box-sizing:border-box}body{font-family:var(--font-sans)}.shell{position:relative;display:flex;flex-direction:column}@media (min-width:761px){.shell{min-height:calc(100vh - 30px);height:calc(100vh - 30px)}}@media (min-width:761px) and (max-width:900px){.shell{min-height:calc(100vh - 20px);height:calc(100vh - 20px)}}.profile,.shell>:not(.shell-orbs){position:relative}.profile{z-index:1001}@media (min-width:901px){.tenant-page.dashboard-page .topbar{height:76px;padding:0 4px;border-bottom-color:#ebd9cb}.tenant-page.dashboard-page .topbar h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-size:34px;font-weight:700;line-height:1.2;letter-spacing:-.6px}.tenant-page.dashboard-page .profile{gap:26px}.tenant-page.dashboard-page .bell,.tenant-page.dashboard-page .btn-theme-toggle,.tenant-page.dashboard-page .btn-topbar-guide{width:28px;height:34px}.tenant-page.dashboard-page .bell svg,.tenant-page.dashboard-page .btn-theme-toggle svg,.tenant-page.dashboard-page .btn-topbar-guide svg{width:27px;height:27px}.tenant-page.dashboard-page .avatar{width:40px;height:40px}}.popover{position:absolute;z-index:2000}#dashboard-page{flex:1;min-height:0;display:flex;flex-direction:column}.dashboard-overview{display:grid;grid-template-columns:minmax(240px,320px) minmax(0,1fr);align-items:stretch;gap:54px;flex:1;min-height:0}.stats-grid{display:grid;grid-template-columns:1fr;grid-template-rows:repeat(3,minmax(0,1fr));gap:14px;min-height:0;align-content:stretch}.stat-card{position:relative;background:linear-gradient(180deg,#fbf5ec,#f3e9dc);border:1px solid var(--line);padding:16px 18px;border-radius:20px;box-shadow:0 6px 18px rgba(92,62,48,.08);display:flex;flex-direction:column;min-height:0;gap:8px;transition:all .35s ease;cursor:default}.stat-card:hover{transform:translateY(-4px);border-color:#dcc8b8;box-shadow:0 14px 30px rgba(92,62,48,.16)}.stat-card-head{display:flex;align-items:center;gap:12px}.stat-card-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:999px;background:radial-gradient(circle at top,hsla(0,0%,100%,.9),hsla(34,49%,91%,.9));border:1px solid rgba(123,74,58,.16);color:var(--brown);flex-shrink:0}.stat-card-icon svg{width:20px;height:20px}.card-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.16em;color:var(--text-muted);font-weight:700}.card-icon{color:var(--text-dark);width:20px;height:20px}.card-middle{display:flex;align-items:baseline;gap:6px;margin-top:4px}.card-value{font-family:"Fraunces",var(--font-serif);font-size:1.6rem;color:var(--head);font-weight:700;letter-spacing:-.01em}.card-suffix{font-size:.9rem;color:var(--text-dark);font-weight:600}.card-bottom{margin-top:auto}.card-subtext{font-size:.9rem;color:var(--text-muted);line-height:1.4}.current-booking-card{background:linear-gradient(180deg,#fbf5ec,#f3e9dc);border:1px solid var(--line);border-radius:26px;box-shadow:0 6px 18px rgba(92,62,48,.08);min-height:0;overflow:hidden;transition:all .35s ease;height:100%;width:100%}.current-booking-card:hover{border-color:#dcc8b8;box-shadow:0 14px 30px rgba(92,62,48,.16)}.current-booking-header{display:flex;align-items:center;justify-content:space-between;padding:26px 32px 0;border-bottom:0!important}.current-booking-header .section-title{margin-bottom:0}html[data-theme=dark] .tenant-page.dashboard-page .current-booking-header{border-bottom:0!important}.current-booking-body{display:grid;grid-template-columns:minmax(240px,1.15fr) minmax(0,.85fr);gap:28px;align-items:stretch;padding:18px 32px 32px;flex:1;min-height:0}.booking-hero{position:relative;min-height:320px;border-radius:22px;overflow:hidden;box-shadow:0 12px 26px rgba(92,62,48,.12)}.booking-hero img{display:block;width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.booking-status-pill{position:absolute;top:16px;left:16px;display:inline-flex;align-items:center;padding:7px 14px;border-radius:999px;background:rgba(46,107,59,.92);color:#f7fff9;font-size:.78rem;font-weight:700;letter-spacing:.02em;box-shadow:0 8px 18px rgba(27,66,36,.22)}.booking-status-pill.pending-approval{background:rgba(136,90,18,.92);color:#fff7ec}.booking-content{gap:22px;min-width:0}.booking-content,.booking-main{display:flex;flex-direction:column}.booking-main{gap:8px}.booking-room-title{font-family:"Fraunces",var(--font-serif);font-size:clamp(1.6rem,2vw,2.2rem);color:var(--head);letter-spacing:-.01em}.booking-dorm-title{color:var(--text-muted);font-size:1rem}.booking-meta-row{flex-direction:column;margin-top:6px}.booking-meta-pill,.booking-meta-row{display:flex;align-items:flex-start;gap:8px}.booking-meta-pill{min-width:0;padding:0;border:0;border-radius:0;background:none;color:var(--text-dark);font-size:.9rem;font-weight:500;line-height:1.4;overflow-wrap:anywhere}.booking-meta-pill svg{width:18px;height:18px;flex-shrink:0;margin-top:1px;color:var(--brown)}.booking-details-grid{display:grid;grid-template-columns:1fr;grid-template-rows:repeat(3,1fr);gap:10px;flex:1}.booking-detail-item{display:flex;flex-direction:column;justify-content:center;gap:4px;padding:8px 12px;border-radius:12px;border:1px solid rgba(123,74,58,.16);background:hsla(0,0%,100%,.7);min-height:56px}.booking-detail-label{font-size:.78rem;text-transform:uppercase;letter-spacing:.14em;color:var(--text-muted);font-weight:700}.booking-detail-value{font-size:.96rem;font-weight:600;color:var(--text-dark);overflow-wrap:anywhere}.booking-actions-row{display:flex;flex-wrap:nowrap;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;width:100%}.booking-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 20px;height:40px;border-radius:8px;font:500 13px DM Sans,sans-serif;text-decoration:none;transition:.2s;border:1px solid transparent;flex:1;min-width:0;box-shadow:0 1px 3px rgba(0,0,0,.12);cursor:pointer}.booking-action-btn.primary{background:var(--brown);border-color:var(--brown);color:#fff}.booking-action-btn.primary:hover{color:#fff;background:var(--brown-dark);border-color:var(--brown-dark)}.booking-action-btn.primary:active{color:#fff;background:var(--brown-darker);border-color:var(--brown-darker)}.booking-action-btn.ghost{background:var(--bg-main);color:var(--brown);border-color:var(--line)}.booking-action-btn.ghost:hover{filter:brightness(.95)}.booking-action-btn:hover{scale:1.02}.booking-action-btn.landing-login{background:var(--brown);border-color:var(--brown);color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}.booking-action-btn.landing-login:focus-visible,.booking-action-btn.landing-login:hover{background:var(--brown-dark);border-color:var(--brown-dark);color:#fff;scale:1.06}.booking-action-btn.landing-login:active{background:var(--brown-darker,#452618);border-color:var(--brown-darker,#452618);color:#fff;scale:1.1}.booking-action-btn.landlord-view-details{background:#fff;border-color:#d9bea6;color:var(--brown);box-shadow:0 2px 6px rgba(122,75,58,.06)}.booking-action-btn.landlord-view-details:focus-visible,.booking-action-btn.landlord-view-details:hover{background:#fbf4ee;border-color:var(--brown);color:var(--brown-dark);box-shadow:0 6px 18px rgba(122,75,58,.16);scale:1.06}.booking-action-btn.landlord-view-details:active{background:#f7ede4;border-color:var(--brown);color:var(--brown-dark);box-shadow:0 8px 22px rgba(122,75,58,.22);scale:1.1}.section-title{font-family:"Fraunces",var(--font-serif);font-size:clamp(1.6rem,2vw,2rem);color:var(--head);letter-spacing:-.01em}@media (max-width:1200px){.dashboard-overview{grid-template-columns:minmax(220px,280px) minmax(0,1fr)}.current-booking-body{grid-template-columns:minmax(220px,1.15fr) minmax(0,.85fr);gap:22px;padding:18px 24px 26px}.current-booking-header{padding:22px 24px 0}}@media (max-width:1150px){.dashboard-overview{gap:28px}}@media (max-width:1024px){.dashboard-overview{grid-template-columns:1fr;gap:24px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:none}.stat-card{min-height:150px}}@media (max-width:768px){.dashboard-overview{grid-template-columns:1fr;margin-bottom:24px}.stats-grid{grid-template-columns:1fr;grid-template-rows:none}.stat-card{min-height:150px}.current-booking-body{grid-template-columns:1fr;padding:18px 20px 24px}.current-booking-header{padding:20px 20px 0}.booking-hero{min-height:240px}.booking-details-grid{grid-template-columns:1fr}}.current-booking-card{display:flex;flex-direction:column}.current-booking-card .recent-bookings-body{flex:1}.recent-bookings-footer{padding:0;border-top:1px solid #ebd9cb;text-align:center;background:#fff;border-bottom-left-radius:20px;border-bottom-right-radius:20px;margin-top:auto;overflow:hidden}.recent-bookings-view-all{color:#7a4b3a;font-weight:600;font-size:.9rem;text-decoration:none;display:flex;align-items:center;justify-content:center;width:100%;padding:16px 20px;box-sizing:border-box;gap:6px;transition:color .18s ease,background-color .18s ease}.recent-bookings-view-all:hover{color:#4a271c;background:#fbf7f4}.recent-bookings-view-all:active{color:#2b140d;background:#f5ece4}.recent-bookings-body{flex:1;min-height:0;padding:18px 24px;display:flex;flex-direction:column;justify-content:flex-start;align-items:stretch;gap:14px;overflow:hidden}.recent-booking-card{flex:0 0 auto;min-height:104px;height:104px;box-sizing:border-box;display:flex;align-items:center;gap:16px;padding:16px 20px;background:#fffaf5;border:1px solid #e5d5c6;border-radius:18px;box-shadow:0 3px 10px rgba(92,62,48,.05);transition:transform .18s ease,box-shadow .18s ease}.booking-avatar-circle{width:48px;height:48px;flex:0 0 48px;border-radius:50%;overflow:hidden;display:grid;place-items:center;background:#ead7c3;color:#7a4b3a;font-weight:700}.booking-avatar-circle img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.booking-info-col{flex:1;min-width:0;overflow-wrap:anywhere}.booking-room-heading{display:flex;flex-wrap:wrap;align-items:center;gap:8px;color:#3b2820;font-weight:700}.booking-status-tag{font-size:.7rem;text-transform:capitalize;border-radius:999px;padding:3px 9px;background:#eee4da;color:#6b574e}.booking-status-tag.pending{background:#fef3c7;color:#92400e}.booking-status-tag.active,.booking-status-tag.approved{background:#dcfce7;color:#166534}.booking-status-tag.upcoming{background:#dbeafe;color:#1e40af}.booking-status-tag.cancelled,.booking-status-tag.ended,.booking-status-tag.rejected{background:#fee2e2;color:#991b1b}.booking-dorm-sub{margin:5px 0;color:#78665c;font-size:.82rem}.booking-tenant-meta{margin:0;display:flex;align-items:center;gap:6px;font-size:.8rem;color:#78665c}.btn-booking-details{display:inline-flex;justify-content:center;align-items:center;flex-shrink:0;padding:10px 22px;border:1px solid var(--brown,#7a4b3a);border-radius:10px;background:var(--brown,#7a4b3a);color:#fff!important;text-decoration:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:.88rem;font-weight:600;box-shadow:0 2px 8px rgba(122,75,58,.2);transform:scale(1);transform-origin:center;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease!important;cursor:pointer}.btn-booking-details:focus-visible,.btn-booking-details:hover{background:var(--brown-dark,#5f3425);border-color:var(--brown-dark,#5f3425);transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(122,75,58,.28)}.btn-booking-details:active{background:var(--brown-darker,#432419);border-color:var(--brown-darker,#432419);transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(122,75,58,.35)}.btn-manage-properties{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--brown,#7a4b3a);background:var(--brown,#7a4b3a);color:#fff;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease;margin-top:10px}.btn-manage-properties:focus-visible,.btn-manage-properties:hover{color:#fff;background:var(--brown-dark,#5f3425);border-color:var(--brown-dark,#5f3425);transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.22)}.btn-manage-properties:active{color:#fff;background:var(--brown-darker,#432419);border-color:var(--brown-darker,#432419);transform:scale(1.12);box-shadow:0 4px 14px rgba(122,75,58,.28)}.recent-bookings-empty{flex:1;min-height:250px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:24px 12px;gap:10px}.recent-bookings-empty-icon{width:64px;height:64px;border-radius:50%;background:#ead7c3;color:#7a4b3a;display:grid;place-items:center}.recent-bookings-empty-icon svg{width:30px;height:30px;stroke:currentColor;fill:none}.recent-bookings-empty h3{margin:0;color:#3b2820;font-size:1.1rem}.recent-bookings-empty p{margin:0;max-width:290px;color:#78665c;font-size:.88rem;line-height:1.5}@media (max-width:600px){.recent-bookings-body{padding:18px}.recent-booking-card{flex-wrap:wrap}.recent-booking-card .btn-booking-details{margin-left:62px}}dialog{margin:auto}.recent-booking-card .btn-booking-details{transform-origin:center;transition:transform .16s ease,box-shadow .16s ease,background-color .16s ease}.recent-booking-card .btn-booking-details:focus-visible,.recent-booking-card .btn-booking-details:hover{transform:scale(1.06);box-shadow:0 5px 12px rgba(92,62,48,.2)}.recent-booking-card .btn-booking-details:active{transform:scale(1.1);box-shadow:0 7px 16px rgba(92,62,48,.25)}@media (prefers-reduced-motion:reduce){.recent-booking-card .btn-booking-details{transition:none}}.recent-bookings-body{scrollbar-color:#7b4a3a transparent;scrollbar-width:thin}.recent-bookings-body::-webkit-scrollbar{width:12px}.recent-bookings-body::-webkit-scrollbar-track{background:transparent!important}.recent-bookings-body::-webkit-scrollbar-thumb{background:#7b4a3a;border:3px solid #f3e9df;border-radius:999px}.recent-bookings-body::-webkit-scrollbar-thumb:hover{background:#5c3527}html[data-theme=dark] .booking-detail-item,html[data-theme=dark] .recent-booking-card{background:#3a2b23!important;border:1px solid #4b382d!important}html[data-theme=dark] .recent-booking-card .booking-dorm-sub,html[data-theme=dark] .recent-booking-card .booking-room-heading,html[data-theme=dark] .recent-booking-card .booking-tenant-meta,html[data-theme=dark] .recent-booking-card .booking-tenant-meta span{color:#fff7f2!important}html[data-theme=dark] .recent-booking-card .btn-booking-details,html[data-theme=dark] .recent-booking-card .btn-booking-details:active,html[data-theme=dark] .recent-booking-card .btn-booking-details:hover{color:#1e1612!important}html[data-theme=dark] .booking-detail-label{color:#d9c8bc!important}html[data-theme=dark] .booking-detail-value{color:#fff7f2!important}html[data-theme=dark] .booking-meta-pill{background:none!important;border:0!important;padding:0!important;color:#fff7f2}html[data-theme=dark] .tenant-page .current-booking-card .booking-meta-row .booking-meta-pill,html[data-theme=dark] .tenant-page .current-booking-card .booking-meta-row .booking-meta-pill:hover{background:none!important;background-color:transparent!important;background-image:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;padding:0!important;color:#fff7f2!important}@media (min-width:821px){#bookingRoomDialog .room-details-left-panel{padding:20px 22px}#bookingRoomDialog .room-details-left-panel .room-panel-top-row{flex:1 1 auto;min-height:0}#bookingRoomDialog .room-details-left-panel .room-panel-top-row>.dorm-mid-photo{flex:1 1 auto;height:auto!important;min-height:230px}#bookingRoomDialog .room-details-left-panel .room-panel-carousel{position:absolute;inset:0;height:100%!important}}@media (min-width:821px) and (max-height:700px){#bookingRoomDialog .room-details-left-panel{gap:8px;padding:16px 22px 14px}#bookingRoomDialog .room-details-left-panel .room-panel-top-row{flex:1 1 auto;min-height:0;gap:8px}#bookingRoomDialog .room-details-left-panel .room-panel-top-row>.dorm-mid-photo{height:auto!important;min-height:170px}}@media (min-width:821px) and (max-height:620px){#bookingRoomDialog .room-details-left-panel{gap:6px;padding:12px 20px 10px}#bookingRoomDialog .room-details-left-panel .room-panel-top-row{gap:6px}#bookingRoomDialog .room-details-left-panel .room-panel-top-row>.dorm-mid-photo{min-height:130px}}.settings-page .shell{min-height:calc(100vh - 30px);height:auto;border-radius:22px;padding:0 54px 48px}@media (min-width:761px){.settings-page .shell{min-height:calc(100vh - 30px);height:calc(100vh - 30px);padding:0 54px 24px;display:flex;flex-direction:column;overflow:hidden}.settings-page .shell:has(.popover:not([hidden])),.settings-page .shell:has(.topbar.is-popover-open){overflow:visible}.settings-page .topbar{flex-shrink:0}.settings-page #settings-main{flex:1;min-height:0;display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;padding-top:14px;margin:0 -54px 0 0;padding-right:42px;scrollbar-color:var(--brown) transparent;scrollbar-width:thin}.settings-page #settings-main::-webkit-scrollbar{width:12px}.settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}.settings-page #settings-main::-webkit-scrollbar-thumb{background:#7a4b3a;border:3px solid #f3e9df;border-radius:999px}.settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#5c3527}.settings-page .settings-tabs{flex-shrink:0;width:100%;margin:0}.settings-page .settings-tab-content.is-active{display:block;width:100%;overflow:visible;padding-bottom:24px}}.settings-page .topbar{flex-shrink:0;position:relative;z-index:1000;height:auto;display:flex;flex-direction:column;align-items:stretch;border-bottom:1px solid #ebd9cb;padding:0;gap:0}.settings-page .topbar-main-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 4px;position:relative;z-index:2}.settings-page .topbar.is-popover-open .topbar-main-row,.settings-page .topbar:has(.popover:not([hidden])) .topbar-main-row{z-index:1000000}.settings-page h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-size:34px;font-weight:700;letter-spacing:-.6px;line-height:1.2;margin:0;color:#3b2820}.settings-page .profile{display:flex;align-items:center;gap:26px;padding-right:3px}.settings-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:24px;font-size:.92rem;font-weight:500}.settings-alert.is-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.settings-alert.is-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.btn-settings-primary:disabled{cursor:not-allowed;opacity:.48;filter:grayscale(.15);box-shadow:none;pointer-events:none}.profile-save-hint{margin:10px 0 0;color:#8b5e4f;font-size:.82rem;text-align:right}.settings-tab-btn:disabled{cursor:not-allowed;opacity:.45;pointer-events:none}.settings-tabs{position:relative;display:flex;width:100%;border-top:1px solid #ebd9cb;margin:0;padding:0;background:hsla(38,67%,98%,.7);overflow-x:auto;scrollbar-width:none;border-radius:0;z-index:2}.settings-tabs::-webkit-scrollbar{display:none}.settings-tabs-indicator{position:absolute;bottom:0;top:auto;left:0;height:3px;background:#7a4b3a;border-radius:3px 3px 0 0;box-shadow:none;z-index:3;pointer-events:none;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}.settings-tab-btn{flex:1 1 0;min-width:0;position:relative;z-index:2;display:inline-flex;align-items:center;justify-content:center;gap:8px;height:auto;min-height:48px;padding:14px 16px;background:transparent;border:none;border-bottom:3px solid transparent;border-radius:0;color:#7a4b3a;font-family:DM Sans,sans-serif;font-size:.94rem;font-weight:600;cursor:pointer;transition:color .2s ease,background-color .2s ease;white-space:nowrap}.settings-tab-btn:hover:not(.is-active){color:#5f3425;background:rgba(122,75,58,.04)}.settings-tab-btn.is-active{color:#5f3425!important;font-weight:700;border-bottom-color:transparent;border-top-color:transparent;background:rgba(122,75,58,.02)}.settings-card{width:100%;box-sizing:border-box;background:#fff;border-radius:18px;border:1px solid #ebd9cb;box-shadow:0 4px 16px rgba(84,60,48,.06);padding:32px 36px;margin-bottom:28px;transition:box-shadow .2s ease}.settings-tab-content{display:none}.settings-tab-content.is-active{display:block}.profile-view-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding-bottom:22px;margin-bottom:24px;border-bottom:1px solid #f0e6e0}.profile-view-identity{display:flex;align-items:center;gap:18px}.profile-view-avatar{width:72px;height:72px;border-radius:50%;background:#7a4b3a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;overflow:hidden;flex-shrink:0;box-shadow:0 3px 10px rgba(122,75,58,.2);border:2px solid #fff}.profile-view-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-view-name{font-family:Fraunces,Georgia,serif;font-size:1.45rem;font-weight:700;color:#3b2820;margin:0 0 4px}.profile-role-badge{display:inline-block;padding:0;border:0;border-radius:0;background:none;color:#7a4b3a;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.settings-section-title{font-size:1.05rem;font-weight:700;color:#3b2820;margin:24px 0 14px;padding-bottom:8px;border-bottom:1px solid #f0e6e0}.settings-section-title:first-child{margin-top:0}.settings-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px 24px;margin-bottom:22px}.settings-grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:24px}.info-display-group{display:flex;flex-direction:column;gap:4px}.info-display-label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#8b7467}.info-display-val{font-size:.95rem;font-weight:600;color:#3b2820;background:#fdfaf7;padding:10px 14px;border-radius:10px;border:1px solid #f0e6e0;min-height:42px;display:flex;align-items:center;word-break:break-word}.settings-field{display:flex;flex-direction:column;gap:0}.settings-label{display:block;margin-bottom:7px;color:#1f1a17;font-size:14px;font-weight:500}.settings-input,.settings-select{width:100%;height:45px;padding:0 14px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;color:#1f1a17;font-size:15px;font-family:inherit;transition:all .18s ease}.settings-input::-moz-placeholder{color:#9a8c84}.settings-input::placeholder,.settings-select:invalid{color:#9a8c84}.settings-select,.settings-select:active,.settings-select:focus,.settings-select:hover{cursor:pointer}.settings-input:focus,.settings-select:focus{outline:0;background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.avatar-edit-box{display:flex;align-items:center;gap:20px;padding:18px 20px;border-radius:14px;background:#fcf8f5;border:1px solid #ebd9cb;margin-bottom:24px}.avatar-edit-preview-wrap{position:relative;width:76px;height:76px;flex-shrink:0}.avatar-preview-circle{width:100%;height:100%;border-radius:50%;background:#7a4b3a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;overflow:hidden;box-shadow:0 2px 8px rgba(122,75,58,.15)}.avatar-preview-circle img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.avatar-edit-btn{position:absolute;bottom:-2px;right:-2px;width:28px;height:28px;border-radius:50%;background:#7a4b3a;color:#fff;border:2px solid #fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 5px rgba(0,0,0,.2);transition:transform .18s,background .18s}.avatar-edit-btn:hover{background:#5d3627;transform:scale(1.08)}.avatar-edit-btn svg{width:14px;height:14px;stroke:currentColor;stroke-width:2}.settings-dropzone{border:2px dashed #d9bea6;border-radius:14px;background:#fdfaf7;padding:22px 16px;text-align:center;cursor:pointer;transition:all .2s ease}.settings-dropzone.dragover,.settings-dropzone:hover{background:#f7ede5;border-color:#7a4b3a;transform:translateY(-1px)}.dropzone-icon{width:36px;height:36px;margin:0 auto 8px;stroke:#7a4b3a;display:block}.dropzone-filename{font-weight:600;color:#3b2820;font-size:.9rem;margin-bottom:2px;word-break:break-all}.dropzone-hint{font-size:.78rem;color:#8b7467;margin-bottom:12px}.dropzone-btn-row{display:flex;gap:8px;justify-content:center}.dropzone-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#fff;border:1px solid #ebd9cb;border-radius:8px;font-size:.8rem;font-weight:600;color:#7a4b3a;cursor:pointer;transition:background .15s,border-color .15s}.dropzone-btn:hover{background:#fbf5ef;border-color:#7a4b3a}.dropzone-btn svg{width:14px;height:14px}.btn-settings-primary{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 24px;border:none;border-radius:12px;background:linear-gradient(135deg,#7a4b3a,#5d3627);color:#fff;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(122,75,58,.22);transition:all .2s ease}.btn-settings-primary:hover{filter:brightness(1.08);transform:translateY(-1px)}.btn-settings-secondary{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border:1px solid #d9bea6;border-radius:12px;background:#fff;color:#7a4b3a;font-size:.94rem;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(84,60,48,.05);transition:all .2s ease}.btn-settings-secondary:hover{background:#fbf5ef;border-color:#7a4b3a;transform:translateY(-1px)}.settings-change-password-btn{justify-content:center;text-decoration:none;font-family:inherit}html[data-theme=dark] .settings-page .settings-logout-btn{background:hsla(25,28%,79%,.14);border-color:hsla(25,28%,79%,.22);color:#fff}html[data-theme=dark] .settings-page .settings-logout-btn:hover{background:hsla(25,28%,79%,.25);border-color:hsla(25,28%,79%,.4);color:#fff}.btn-settings-danger{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;padding:9px 18px!important;border-radius:10px!important;background:#fff!important;color:#dc2626!important;border:1px solid #fca5a5!important;box-shadow:0 2px 6px rgba(220,38,38,.08)!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important;font-size:13px!important;cursor:pointer!important;transform:scale(1)!important;transform-origin:center!important;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease!important}.btn-settings-danger:hover{background:#fee2e2!important;border-color:#f87171!important;color:#b91c1c!important;transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(220,38,38,.2)!important}.btn-settings-danger:active{background:#fecaca!important;transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(220,38,38,.26)!important}.password-box{background:#fdfaf7;border:1px solid #ebd9cb;border-radius:14px;padding:20px;margin-top:16px;display:none}.password-box.is-open{display:block}.danger-zone-box{margin-top:16px;padding-top:16px;border-top:1px dashed #fecaca}.verification-pending-feedback{display:flex;align-items:center;gap:12px;margin-top:16px;padding:14px 18px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb}.verification-pending-feedback .verification-feedback-icon{font-size:22px}.verification-pending-feedback span:not(.verification-feedback-icon),.verification-pending-feedback strong{display:block;color:#92400e}.verification-pending-feedback strong{font-size:.95rem}.verification-pending-feedback span:not(.verification-feedback-icon){color:#78350f;font-size:.86rem}html[data-theme=dark] .settings-page .verification-pending-feedback{background:rgba(245,158,11,.12);border-color:hsla(48,97%,77%,.42)}html[data-theme=dark] .settings-page .verification-pending-feedback span,html[data-theme=dark] .settings-page .verification-pending-feedback strong{color:#fde68a!important}.switch-label{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid #f0e6e0}.switch-label:last-child{border-bottom:none}.switch-toggle{position:relative;display:inline-block;width:48px;height:26px}.switch-toggle input{opacity:0;width:0;height:0}.switch-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#d1d5db;transition:.24s;border-radius:26px}.switch-slider:before{position:absolute;content:"";height:20px;width:20px;left:3px;bottom:3px;background-color:#fff;transition:.24s;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2)}.switch-toggle input:checked+.switch-slider{background-color:#7a4b3a}.switch-toggle input:checked+.switch-slider:before{transform:translateX(22px)}.settings-modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:1000;align-items:center;justify-content:center;padding:20px}.settings-modal-overlay.is-open{display:flex}.settings-modal-card{background:#fffdf9;border-radius:24px;max-width:480px;width:100%;padding:34px 30px;box-shadow:0 28px 70px rgba(55,31,22,.28);border:1px solid #ebd9cb;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}.delete-account-modal{position:relative;margin:auto;text-align:center}.delete-modal-close{position:absolute;top:18px;right:18px;padding:0;border:0;border-radius:0;background:transparent;color:#7a4b3a;font-size:26px;line-height:1;cursor:pointer}.delete-modal-icon{width:64px;height:64px;margin:0 auto 20px;border:1px solid #fecaca;border-radius:50%;display:grid;place-items:center;background:#fee2e2;color:#dc2626}.delete-modal-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.delete-modal-title{margin:0 0 10px;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px;font-weight:700}.delete-modal-copy{max-width:380px;margin:0 auto 20px;color:#745f54;font-size:14px;line-height:1.6}.delete-modal-field{margin-bottom:22px}.delete-modal-field .settings-input{width:100%;text-align:center}.delete-modal-actions{display:flex;align-items:center;justify-content:center;gap:12px;width:100%}.delete-modal-actions .btn-settings-danger{flex:1}.delete-modal-cancel{flex:1;height:44px;padding:0 26px;border:1px solid var(--line,#e8ded6);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--bg,#fffdf9);color:var(--brown,#7a4b3a);box-shadow:0 1px 3px rgba(0,0,0,.12);font:700 14px DM Sans,sans-serif;cursor:pointer;transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease}.delete-modal-cancel:focus-visible,.delete-modal-cancel:hover{background:#fbf5ef;border-color:#7a4b3a;transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.2)}.delete-modal-cancel:active{transform:scale(1.12)}html[data-theme=dark] .delete-account-modal,html[data-theme=dark] .delete-modal-title{color:#fff7f2!important}html[data-theme=dark] .delete-modal-close,html[data-theme=dark] .delete-modal-copy{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-field .settings-input::-moz-placeholder{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-field .settings-input,html[data-theme=dark] .delete-modal-field .settings-input::placeholder{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-cancel{background:transparent;border-color:var(--ds-accent-light,#efc2ae);color:var(--ds-accent-light,#efc2ae);box-shadow:none;font-weight:500}html[data-theme=dark] .delete-modal-cancel:active,html[data-theme=dark] .delete-modal-cancel:focus-visible,html[data-theme=dark] .delete-modal-cancel:hover{background:hsla(18,57%,73%,.1);border-color:#efc2ae;color:#efc2ae;font-weight:700}@media (max-width:480px){.delete-modal-actions{flex-direction:column}.delete-modal-actions .btn-settings-danger,.delete-modal-actions .delete-modal-cancel{flex:none;width:100%}}.input-prefix-wrapper,.input-with-icon-wrapper{display:flex;align-items:stretch;width:100%;height:45px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;overflow:hidden;transition:border-color .2s,box-shadow .2s}.input-prefix-wrapper:focus-within,.input-with-icon-wrapper:focus-within{background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.field-icon{display:flex;align-items:center;justify-content:center;padding:0 12px 0 14px;color:#7a4b3a;background:#fbf6f2;border-right:1px solid #ebd9cb;-webkit-user-select:none;-moz-user-select:none;user-select:none;flex-shrink:0}.field-icon svg{display:block}.input-prefix-addon{display:flex;align-items:center;padding:0 12px;background:transparent;color:#5b4a42;font-size:14px;font-weight:500;border-right:1px solid #d9c9bd;-webkit-user-select:none;-moz-user-select:none;user-select:none;white-space:nowrap}.input-prefix-wrapper .settings-input,.input-with-icon-wrapper .settings-input{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;flex:1;min-width:0;height:43px}.select-with-icon-wrapper{position:relative;display:flex;align-items:center;width:100%}.select-with-icon-wrapper .field-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex;align-items:center;justify-content:center;color:#7a4b3a;background:transparent;border-right:none;padding:0;pointer-events:none;z-index:2}.select-with-icon-wrapper .settings-select{width:100%;height:44px;padding:0 44px 0 14px;border:1px solid #d9bea6;border-radius:12px;background:#fff;color:#3b2820;font-size:.94rem;font-family:inherit;appearance:none;-webkit-appearance:none;-moz-appearance:none;cursor:pointer!important;transition:border-color .18s,box-shadow .18s,background .18s}.select-with-icon-wrapper .settings-select.has-icon{padding-left:42px}.select-with-icon-wrapper .settings-select:hover{border-color:#bfa38d;background:#fdfaf7;cursor:pointer!important}.select-with-icon-wrapper .settings-select:focus{outline:none;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15);cursor:pointer!important}.select-with-icon-wrapper .select-dropdown-arrow{position:absolute;right:22px;top:50%;transform:translateY(-50%) rotate(180deg);display:flex;align-items:center;justify-content:center;color:#7a4b3a;pointer-events:none;z-index:2;transition:transform .24s cubic-bezier(.16,1,.3,1),color .18s ease}.select-with-icon-wrapper.is-dropped .select-dropdown-arrow{transform:translateY(-50%) rotate(0deg);color:#5d3627}.dropzone-preview-wrap{position:relative;width:100%;max-height:220px;border-radius:12px;overflow:hidden;margin-bottom:12px;background:#f5ede6;border:1px solid #e2cec0;display:flex;align-items:center;justify-content:center}.dropzone-preview-img{width:100%;max-height:220px;-o-object-fit:contain;object-fit:contain;display:block}.dropzone-active-state{display:flex;flex-direction:column;align-items:center;width:100%}.dropzone-badge{display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:20px;padding:4px 12px;font-size:.78rem;font-weight:600;margin-bottom:8px}.id-document-card{background:#fdfaf7;border:1px solid #ebd9cb;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;transition:transform .18s,box-shadow .18s}.id-document-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(84,60,48,.08)}.id-document-thumb{width:100%;height:180px;border-radius:10px;overflow:hidden;background:#f2e7de;display:flex;align-items:center;justify-content:center;cursor:pointer;border:1px solid #e5d3c5;position:relative}.id-document-thumb img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover;transition:transform .25s ease}.id-document-thumb:hover img{transform:scale(1.03)}.id-document-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}.id-document-title{font-size:.92rem;font-weight:700;color:#3b2820}.id-document-view-btn{font-size:.8rem;font-weight:600;color:#7a4b3a;text-decoration:none!important;background:none;border:none;cursor:pointer;padding:0;transition:color .18s ease}.id-document-view-btn:hover{color:#4a271c;text-decoration:none!important}.id-document-view-btn:active{color:#2b140d}.profile-toast{position:fixed;bottom:24px;right:24px;z-index:100000;width:min(calc(100vw - 32px),400px);min-height:110px;box-sizing:border-box;background:#ecdccb;border-radius:14px;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);border:none;overflow:hidden;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;animation:toast-smooth-in .35s cubic-bezier(.16,1,.3,1) .05s both;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.profile-toast.is-dismissing{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none}.profile-toast-inner{display:flex;align-items:center;gap:14px;padding:16px 20px;height:100%;box-sizing:border-box}.profile-toast-icon{width:44px;height:44px;border-radius:12px;background:rgba(122,75,58,.15);color:#7a4b3a;border:none;display:grid;place-items:center;flex-shrink:0;font-size:20px;font-weight:700}.profile-toast-body{flex:1;min-width:0}.profile-toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:2px}.profile-toast-header strong{font-size:15px;font-weight:700;color:#3b2820}.profile-toast-close{background:none;border:none;font-size:22px;line-height:1;color:#7a4b3a;cursor:pointer;padding:0 4px;transition:color .15s,transform .15s}.profile-toast-close:hover{color:#3b2820;transform:scale(1.15)}.profile-toast-body p{margin:0;font-size:13.5px;line-height:1.4;color:#5a4236}@keyframes toast-smooth-in{0%{opacity:0;transform:translateY(28px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}.cropper-modal-overlay{display:none;position:fixed;inset:0;background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:100000;align-items:center;justify-content:center;padding:20px}.cropper-modal-overlay.is-open{display:flex}.cropper-modal-card{background:#fffdf9;border-radius:24px;max-width:480px;width:100%;padding:30px;box-shadow:0 28px 70px rgba(55,31,22,.28);border:1px solid #ebd9cb;display:flex;flex-direction:column;gap:18px;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}.cropper-modal-header .profile-toast-close{width:auto;height:auto;border-radius:0;border:none;background:none;color:#7a4b3a;font-size:26px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.cropper-modal-header .profile-toast-close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.cropper-modal-header .profile-toast-close:active{background:none;color:#1a0f0a;transform:scale(.92)}.cropper-modal-header{display:flex;align-items:center;justify-content:space-between}.cropper-modal-title{margin:0;font-size:1.35rem;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-weight:700;color:#3b2820}.cropper-canvas-wrap{position:relative;width:100%;height:300px;background:#1f1b18;border-radius:14px;overflow:hidden;-webkit-user-select:none;-moz-user-select:none;user-select:none;touch-action:none;display:flex;align-items:center;justify-content:center;cursor:grab}.cropper-canvas-wrap:active{cursor:grabbing}.cropper-mask{position:absolute;inset:0;pointer-events:none;box-shadow:0 0 0 9999px rgba(0,0,0,.55);border-radius:50%;width:200px;height:200px;margin:auto;border:2px solid hsla(0,0%,100%,.85)}.cropper-controls{display:flex;align-items:center;gap:14px}.cropper-zoom-slider{flex:1;accent-color:#7a4b3a}.cropper-btn-round{width:34px;height:34px;border-radius:50%;border:1px solid #ebd9cb;background:#fbf6f2;color:#7a4b3a;font-size:18px;font-weight:700;display:grid;place-items:center;cursor:pointer;transition:all .15s}.cropper-btn-round:hover{background:#f3e5d9}.id-lightbox-overlay{position:fixed;inset:0;background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:100001;display:flex;align-items:center;justify-content:center;padding:24px;opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}.id-lightbox-overlay.is-open:not(.is-closing){opacity:1;pointer-events:auto}.id-lightbox-overlay.is-closing{opacity:0;pointer-events:none;transition:opacity .22s ease,backdrop-filter .22s ease,-webkit-backdrop-filter .22s ease}.id-lightbox-content{position:relative;max-width:90vw;max-height:88vh;display:flex;flex-direction:column;align-items:center;transform:scale(.92) translateY(12px);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease}.id-lightbox-overlay.is-open:not(.is-closing) .id-lightbox-content{transform:scale(1) translateY(0);opacity:1}.id-lightbox-overlay.is-closing .id-lightbox-content{transform:scale(.94) translateY(10px);opacity:0;transition:transform .22s ease,opacity .2s ease}.id-lightbox-img{max-width:100%;max-height:80vh;-o-object-fit:contain;object-fit:contain;border-radius:14px;box-shadow:0 24px 50px rgba(0,0,0,.45)}.id-lightbox-close{position:absolute;top:-44px;right:0;background:none;border:none;border-radius:0;width:auto;height:auto;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;cursor:pointer;line-height:1;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.id-lightbox-close:hover{background:none;color:#e5d8cf;transform:scale(1.1)}.id-lightbox-close:active{background:none;color:#c9b7ad;transform:scale(.92)}#tab-notifications .switch-label>div:first-child{flex:1;min-width:0;padding-right:24px}#tab-notifications .switch-toggle{flex:0 0 48px;margin-left:auto}#tab-notifications .settings-card{min-height:460px;display:flex;flex-direction:column}#notification-preference-status{margin:auto 0 0;padding-top:16px;align-self:flex-end;text-align:right;font-size:.85rem;color:#78665c}#notification-preference-status:empty{display:none}#tab-notifications .switch-label:last-of-type{border-bottom:none}.notification-preference-footer{display:block;width:100%;margin-top:auto;padding-top:16px;text-align:right}.notification-preference-footer #notification-preference-status{display:block;width:100%;margin:0;padding:0;text-align:right;overflow-wrap:anywhere}.notification-preference-footer #notification-preference-status:empty{display:none}dialog.ps-modal{width:min(92vw,480px);padding:0;border:1px solid #ebd9cb;border-radius:24px;background:#fffdf9;color:#3b2820;box-shadow:0 28px 70px rgba(55,31,22,.28);text-align:center;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}dialog.ps-modal::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}dialog.ps-modal[open]{animation:ps-in .28s cubic-bezier(.16,1,.3,1) both}dialog.ps-modal.is-closing{animation:ps-out .2s ease both}@keyframes ps-in{0%{opacity:0;transform:translateY(14px) scale(.95)}to{opacity:1;transform:none}}@keyframes ps-out{to{opacity:0;transform:translateY(10px) scale(.95)}}.ps-card{padding:34px 30px 28px}.ps-icon{width:64px;height:64px;margin:0 auto 18px;display:grid;place-items:center;border-radius:50%;background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}.ps-icon svg{width:30px;height:30px;stroke-width:2.2}.ps-card h2{margin:0 0 8px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-.2px;color:#3b2820}.ps-card p{margin:0 auto 24px;max-width:340px;font-size:.94rem;line-height:1.55;color:#6b574e}.ps-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:46px;padding:0 26px;border:0;border-radius:12px;background:linear-gradient(135deg,#7a4b3a,#5d3627);color:#fff;font:700 .94rem DM Sans,sans-serif;text-decoration:none;cursor:pointer;box-shadow:0 4px 14px rgba(122,75,58,.22);transition:all .2s ease}.ps-btn:hover{filter:brightness(1.08);transform:translateY(-1px)}@media (prefers-reduced-motion:reduce){dialog.ps-modal.is-closing,dialog.ps-modal[open]{animation:none}}.settings-field .um-select{position:relative;width:100%}.settings-field .um-select-btn{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;height:45px;min-width:0;padding:0 14px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;color:#1f1a17;font-family:DM Sans,sans-serif;font-size:15px;font-weight:400;cursor:pointer;transition:border-color .18s ease,box-shadow .18s ease;box-shadow:none}.settings-field .um-select-btn:hover{border-color:#d9c9bd}.settings-field .um-select-btn[aria-expanded=true]{background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.settings-field .um-select-btn.is-placeholder .um-select-value{color:#9a8c84}.settings-field .um-select-caret{width:17px;height:17px;flex:none;stroke:currentColor;stroke-width:2.2;color:#5b4a42;transition:transform .2s ease}.settings-field .um-select-btn[aria-expanded=true] .um-select-caret{transform:rotate(180deg)}.settings-field .um-select-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:1200;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #eadfd9;border-radius:14px;box-shadow:0 18px 44px rgba(60,30,20,.18);transform-origin:top center;animation:um-menu-in .22s cubic-bezier(.16,1,.3,1) both}.settings-field .um-select-menu[hidden]{display:none}.settings-field .um-select-menu.is-closing{animation:um-menu-out .18s cubic-bezier(.2,.8,.2,1) both;pointer-events:none}.settings-field .um-select-menu li{padding:10px 14px;border-radius:10px;color:#5f3425;font-family:DM Sans,sans-serif;font-size:.92rem;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s}.settings-field .um-select-menu li:focus,.settings-field .um-select-menu li:hover{background:#f4ece7;outline:none}.settings-field .um-select-menu li[aria-selected=true]{background:#7a4b3a;color:#fff;font-weight:600}@keyframes um-menu-in{0%{opacity:0;transform:translateY(-8px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes um-menu-out{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(-6px) scale(.96)}}html[data-theme=dark] .settings-page .settings-tabs{background:#5c4538!important;border-top-color:#6e5344!important;border-bottom-color:#6e5344!important}html[data-theme=dark] .settings-page .settings-tab-btn,html[data-theme=dark] .settings-page .settings-tab-btn.is-active{color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-tab-btn:hover:not(.is-active){color:#fff7f2!important;background:rgba(255,247,242,.06)!important}html[data-theme=dark] .settings-page .settings-tab-btn.is-active{background:rgba(122,75,58,.02)!important}html[data-theme=dark] .settings-page .settings-tabs-indicator{background:#fff7f2!important}html[data-theme=dark] .settings-page #profileViewCard{background:var(--dark-form-surface,#382a22)!important;border:1px solid var(--ds-line,#3d2d24)!important;border-radius:24px;color:var(--ds-title,#fff7f2)!important;box-shadow:0 16px 48px rgba(0,0,0,.5)!important}html[data-theme=dark] .settings-page #tab-security .settings-card>div:first-of-type strong{color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-change-password-btn{height:45px;padding:0 20px;border:1px solid var(--ds-accent,#e2ab94)!important;border-radius:10px;background:var(--ds-accent,#e2ab94)!important;color:var(--ds-on-accent,#1e1612)!important;font-size:14px;font-weight:700;box-shadow:none;transition:background-color .2s,border-color .2s,color .2s,transform .2s}html[data-theme=dark] .settings-page .settings-change-password-btn:hover{background:var(--ds-accent-hover,#efc2ae)!important;border-color:var(--ds-accent-hover,#efc2ae)!important;color:var(--ds-on-accent,#1e1612)!important;transform:scale(1.02)}html[data-theme=dark] .settings-page .settings-change-password-btn:active{background:var(--ds-accent-press,#cf967f)!important;border-color:var(--ds-accent-press,#cf967f)!important;color:var(--ds-on-accent,#1e1612)!important;transform:scale(1.02)}html[data-theme=dark] .settings-page .settings-change-password-btn:focus-visible{outline:2px solid #fff7f2;outline-offset:3px}.btn-settings-secondary.btn-edit-profile{height:44px;padding:0 26px;border:1px solid #7b4a3a;border-radius:8px;background:#7b4a3a;color:#fff;font:700 14px DM Sans,sans-serif;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s}.btn-settings-secondary.btn-edit-profile:hover{background:#5c3527;border-color:#5c3527;color:#fff;filter:brightness(.95);transform:none;scale:1.1}.btn-settings-secondary.btn-edit-profile:active{background:#452618;border-color:#452618;color:#fff}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile{background:#e2ab94;border-color:#e2ab94;color:#1e1612}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile:hover{background:#efc2ae;border-color:#efc2ae;color:#1e1612}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile:active{background:#cf967f;border-color:#cf967f;color:#1e1612}html[data-theme=dark] body.tenant-page:not(#_a) #profileEditForm,html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-header{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-name{color:#fff7f2!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-role-badge,html[data-theme=dark] body.tenant-page:not(#_a) .tenant-role-label{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-avatar{background:#231914!important;color:#d9c8bc!important;border:2px solid #563e33!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) .settings-section-title{color:#fff7f2!important;border-bottom:1px solid #563e33!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-label{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val a,html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val span{color:#e2ab94!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val:not(:has(a)) span{color:#8a6c5d!important}html[data-theme=dark] body.tenant-page:not(#_a) .btn-edit-profile{border-color:#c48974!important}body.tenant-page:not(#_a) .danger-zone-box{display:grid;grid-template-columns:1fr auto;-moz-column-gap:24px;column-gap:24px;align-items:center}body.tenant-page:not(#_a) .danger-zone-box h4{grid-column:1;grid-row:1}body.tenant-page:not(#_a) .danger-zone-box p{grid-column:1;grid-row:2;margin:3px 0 0!important}body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger{grid-column:2;grid-row:1/span 2;align-self:center;justify-self:end;white-space:nowrap}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box{border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box p{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger:hover,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger:active,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}@media (max-width:600px){body.tenant-page:not(#_a) .danger-zone-box{grid-template-columns:1fr}body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger{grid-column:1;grid-row:3;justify-self:start;margin-top:14px}}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{display:grid;grid-template-columns:1fr auto;-moz-column-gap:24px;column-gap:24px;align-items:center}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box h4{grid-column:1;grid-row:1}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box p{grid-column:1;grid-row:2;margin:3px 0 0!important}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{grid-column:2;grid-row:1/span 2;align-self:center;justify-self:end;white-space:nowrap}@media (max-width:600px){body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{grid-template-columns:1fr}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{grid-column:1;grid-row:3;justify-self:start;margin-top:14px}}html[data-theme=dark] body.landlord-settings-page #settings-main{scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:3px solid transparent!important;background-clip:padding-box!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#f0ded2!important;background-clip:padding-box!important}html[data-theme=dark] body.landlord-settings-page #profileEditForm,html[data-theme=dark] body.landlord-settings-page #profileViewCard,html[data-theme=dark] body.landlord-settings-page #tab-security .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .profile-view-header{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page #tab-security strong,html[data-theme=dark] body.landlord-settings-page .avatar-edit-box h3,html[data-theme=dark] body.landlord-settings-page .profile-view-name,html[data-theme=dark] body.landlord-settings-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page #tab-security p,html[data-theme=dark] body.landlord-settings-page .profile-role-badge,html[data-theme=dark] body.landlord-settings-page .profile-save-hint{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page .profile-view-avatar{background:#231914!important;color:#d9c8bc!important;border:2px solid #563e33!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .settings-section-title{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page #profileViewCard .info-display-label{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page #profileViewCard .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile{height:44px!important;padding:0 26px!important;border:1px solid #e2ab94!important;border-radius:8px!important;background:#e2ab94!important;color:#1e1612!important;font:700 14px DM Sans,sans-serif!important;box-shadow:0 1px 3px rgba(0,0,0,.12)!important}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile:hover{background:#efc2ae!important;border-color:#efc2ae!important;color:#1e1612!important;transform:none!important;scale:1.1}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile:active{background:#cf967f!important;border-color:#cf967f!important;color:#1e1612!important}html[data-theme=dark] body.landlord-settings-page .settings-field label,html[data-theme=dark] body.landlord-settings-page .settings-label{color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper,html[data-theme=dark] body.landlord-settings-page .settings-input:not([type=checkbox]):not([type=radio]),html[data-theme=dark] body.landlord-settings-page .settings-select,html[data-theme=dark] body.landlord-settings-page .um-select-btn{background:#231a14!important;border:1px solid #5c4639!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper .settings-input,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper .settings-input{background:transparent!important;border:0!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .field-icon,html[data-theme=dark] body.landlord-settings-page .input-prefix-addon{background:transparent!important;color:#d9c8bc!important;border-right-color:#5c4639!important}html[data-theme=dark] body.landlord-settings-page .settings-input::-moz-placeholder{color:#d9c8bc!important;opacity:.9!important}html[data-theme=dark] body.landlord-settings-page .settings-input::placeholder{color:#d9c8bc!important;opacity:.9!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper:focus-within,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper:focus-within,html[data-theme=dark] body.landlord-settings-page .settings-input:focus,html[data-theme=dark] body.landlord-settings-page .settings-select:focus,html[data-theme=dark] body.landlord-settings-page .um-select-btn[aria-expanded=true]{background:#281d17!important;border-color:#e2ab94!important;color:#d9c8bc!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper .settings-input:focus,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper .settings-input:focus{box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .settings-input:-webkit-autofill{-webkit-text-fill-color:#d9c8bc!important;-webkit-box-shadow:inset 0 0 0 1000px #231a14!important;caret-color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-box{background:#231914!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-btn{background:#e2ab94!important;color:#1e1612!important;border:2px solid #231914!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-btn:hover{background:#efc2ae!important}html[data-theme=dark] body.landlord-settings-page .settings-dropzone{background:#231914!important;border:2px dashed #563e33!important}html[data-theme=dark] body.landlord-settings-page .settings-dropzone.dragover,html[data-theme=dark] body.landlord-settings-page .settings-dropzone:hover{background:#2f211a!important;border-color:#c48974!important}html[data-theme=dark] body.landlord-settings-page .dropzone-hint,html[data-theme=dark] body.landlord-settings-page .dropzone-icon{color:#c5a898!important;stroke:#c5a898!important}html[data-theme=dark] body.landlord-settings-page .dropzone-filename{color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .dropzone-preview-wrap,html[data-theme=dark] body.landlord-settings-page .id-document-thumb{background:#1a110d!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn:hover{background:#2f211a!important;border-color:#c48974!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn.dropzone-btn-danger{background:transparent!important;border-color:hsla(0,91%,71%,.45)!important;color:#fca5a5!important}html[data-theme=dark] body.landlord-settings-page .id-document-card{background:#231914!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .id-document-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page .id-document-view-btn{color:#e2ab94!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-pending,html[data-theme=dark] body.landlord-settings-page .verification-callout.is-pending{background:rgba(245,158,11,.12)!important;border-color:rgba(251,191,36,.35)!important;color:#fde68a!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-rejected,html[data-theme=dark] body.landlord-settings-page .verification-callout.is-rejected{background:rgba(220,38,38,.12)!important;border-color:hsla(0,91%,71%,.4)!important;color:#fecaca!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-verified{background:rgba(34,197,94,.12)!important;border-color:rgba(134,239,172,.35)!important;color:#bbf7d0!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box{border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box p{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger:hover,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger:active,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}html[data-theme=dark] body.settings-page.admin-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page.admin-page .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body.settings-page.admin-page #headerTitle,html[data-theme=dark] body.settings-page.admin-page #tab-notifications strong,html[data-theme=dark] body.settings-page.admin-page #tab-security strong,html[data-theme=dark] body.settings-page.admin-page .profile-view-name,html[data-theme=dark] body.settings-page.admin-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.settings-page.admin-page #profileEditForm,html[data-theme=dark] body.settings-page.admin-page #profileViewCard,html[data-theme=dark] body.settings-page.admin-page .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.settings-page.admin-page .profile-view-header,html[data-theme=dark] body.settings-page.admin-page .settings-section-title{border-bottom-color:#563e33!important}html[data-theme=dark] body.settings-page.admin-page #tab-notifications p,html[data-theme=dark] body.settings-page.admin-page #tab-notifications span,html[data-theme=dark] body.settings-page.admin-page #tab-security p,html[data-theme=dark] body.settings-page.admin-page .info-display-label,html[data-theme=dark] body.settings-page.admin-page .settings-field label,html[data-theme=dark] body.settings-page.admin-page .settings-label{color:#c5a898!important}html[data-theme=dark] body.settings-page.admin-page .info-display-val,html[data-theme=dark] body.settings-page.admin-page .password-box,html[data-theme=dark] body.settings-page.admin-page .settings-input{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.settings-page.admin-page .info-display-val a{color:#e2ab94!important}html[data-theme=dark] body.settings-page.admin-page .settings-input::-moz-placeholder{color:#8a6c5d!important}html[data-theme=dark] body.settings-page.admin-page .settings-input::placeholder{color:#8a6c5d!important}html[data-theme=dark] body.settings-page.admin-page .settings-input:focus{border-color:#c48974!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box{background:#2b1f19!important;border-color:hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card :is(h3,strong){color:#fff7f2!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card p{color:#c5a898!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card>div[style*=border-top]{border-top-color:#563e33!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{background:#2b1f19!important;border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card :is(h3,strong){color:#fff7f2!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card :is(p,span:not(.switch-slider)){color:#c5a898!important}html[data-theme=dark] body.settings-page #tab-notifications .switch-slider{background:#4b382d!important}html[data-theme=dark] body.settings-page #tab-notifications .switch-toggle input:checked+.switch-slider{background:#c48974!important}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body{background:var(--bg);color:var(--text)}html[data-theme=dark] body:not(#_a) .shell:not(#_b){background:#5c4538!important;border:1px solid #6e5344!important}html[data-theme=dark] body:not(#_a) .shell:not(#_b)>.topbar,html[data-theme=dark] body:not(#_a) .shell:not(#_b)>main{background:transparent!important;box-shadow:none!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .bar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar{background:rgba(30,22,18,.96);border-bottom-color:var(--line);box-shadow:0 4px 18px rgba(0,0,0,.35)}[data-theme=dark] .admin-topbar h1,[data-theme=dark] .bar .logo,[data-theme=dark] .topbar h1,[data-theme=dark] .topbar h2,[data-theme=dark] h1,[data-theme=dark] h2,[data-theme=dark] h3,[data-theme=dark] h4{color:var(--head)}[data-theme=dark] .btn-theme-toggle{background:#2b1f19;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-theme-toggle:hover{background:#382921;border-color:var(--brown);color:var(--brown)}[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#4a372c,#3a2820);border-right-color:#553e32}[data-theme=dark] .admin-sidebar{background:linear-gradient(135deg,#32221b,#241813);border-right-color:#3d2d24}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08);color:#fff7f2}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14);color:#d9c8bc;font-weight:700}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .current-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .room-card,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .settings-modal-card,[data-theme=dark] .stat-card,[data-theme=dark] dialog{background:#2b1f19;border-color:var(--line);color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,.4)}html[data-theme=dark] .logout-modal-title{color:#fff7f2!important}html[data-theme=dark] .logout-modal-desc{color:#d9c8bc!important}[data-theme=dark] .dorm-card,[data-theme=dark] .dorm-card-body{background:#382a22;border-color:#4b382d;color:#d9c8bc}[data-theme=dark] .current-booking-body,[data-theme=dark] .dorm-modal-body,[data-theme=dark] .dorm-modal-header,[data-theme=dark] .landlord-profile-card-rect{background:#2b1f19;color:var(--text)}[data-theme=dark] .dorm-card-title{color:#fff7f2}[data-theme=dark] .profile-name,[data-theme=dark] .profile-switch-header,[data-theme=dark] .settings-section-title,[data-theme=dark] .stat-card-value{color:var(--head)}[data-theme=dark] .dorm-card-amenity{background:#231a14;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .admin-user-badge,[data-theme=dark] .topbar-categories-bar{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea{background:#231a14!important;color:var(--ink)!important;border-color:var(--line)!important}[data-theme=dark] .um-select-menu li{color:var(--text)}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f;color:var(--ink)}[data-theme=dark] .settings-tabs{background:#241a15;border-color:var(--line)}[data-theme=dark] .settings-tab-btn{color:#bba89d}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .settings-tab-btn[aria-selected=true]{color:var(--ink)}[data-theme=dark] .settings-tabs-indicator{background:var(--brown)}[data-theme=dark] .switch-slider{background-color:#4b382d}[data-theme=dark] .switch-toggle input:checked+.switch-slider{background-color:var(--brown)}[data-theme=dark] .profile-account-item{border-color:var(--line)}[data-theme=dark] .profile-account-item:hover{background:#34261f}[data-theme=dark] .profile-account-item strong{color:var(--head)}[data-theme=dark] .skeleton-card,[data-theme=dark] .skeleton-shimmer{background:linear-gradient(90deg,#2b1f19 25%,#382921 50%,#2b1f19 75%);background-size:200% 100%}[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .settings-modal-overlay,[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}html[data-theme=dark] dialog:not(.onboarding-toast-modal):not(.guide-modal):not(.landlord-profile-modal):not(.us-confirm):not(.rm-modal){position:fixed;inset:0;margin:auto}html[data-theme=dark] .landlord-properties-page dialog.dorm-modal[open],html[data-theme=dark] dialog.dorm-modal,html[data-theme=dark] dialog.room-details-modal{position:fixed!important;inset:0!important;margin:auto!important;top:0!important;left:0!important;right:0!important;bottom:0!important;transform:none!important}html[data-theme=dark] #modalOverlay,html[data-theme=dark] .cropper-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal,html[data-theme=dark] .preview-modal,html[data-theme=dark] .settings-modal-overlay{align-items:center!important;justify-content:center!important}html[data-theme=dark] #modalOverlay>.modal-card,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .preview-modal-box,html[data-theme=dark] .settings-modal-card{margin:auto}[data-theme=dark] .notification-toast{background:#32241d;border:1px solid var(--line);color:var(--ink);box-shadow:0 16px 36px rgba(0,0,0,.55)}[data-theme=dark] .notification-toast strong{color:var(--head)}[data-theme=dark] .notification-toast span{color:var(--text)}[data-theme=dark] .wrap section{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .crumb{color:#ab978c}[data-theme=dark] .foot{background:#18110d;color:#e2d3c8;border-top:1px solid var(--line)}[data-theme=dark] .feat.feature-hero{background:radial-gradient(circle at 50% 20%,#2b1f19 0,#1e1612 100%)}[data-theme=dark] .sec{background:#1e1612}[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--line)}[data-theme=dark] .tab{color:#bba89d}[data-theme=dark] .tab.active{color:#fff7f2}[data-theme=dark] .tab-indicator{background:var(--brown)}[data-theme=dark] .panel{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .hero-wrap.photo-section{background:#1e1612}[data-theme=dark] .hero-copy{color:var(--text)}[data-theme=dark] .hero-copy h2{color:var(--head)}[data-theme=dark] .sq{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .sq-badge{background:rgba(30,22,18,.85);color:var(--ink);border-color:var(--line)}[data-theme=dark] .sq-num{color:var(--head)}[data-theme=dark] .apply-dialog{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right-color:var(--line)}[data-theme=dark] .apply-ws-right{background:#2b1f19}[data-theme=dark] .la-label{color:#d1b8ab}[data-theme=dark] .la-input{background:#231a14!important;border-color:var(--line)!important;color:var(--ink)!important}[data-theme=dark] body{background-color:#1e1612}[data-theme=dark] .stage{background:#1e1612}[data-theme=dark] .card{background:#2b1f19;border-color:#563e33;box-shadow:0 16px 40px rgba(0,0,0,.45)}[data-theme=dark] .form-side{background:#2b1f19;color:#fbf5f0}[data-theme=dark] .form-side h2,[data-theme=dark] .form-side h3{color:#fbf5f0}[data-theme=dark] .field label,[data-theme=dark] .form-side .hint,[data-theme=dark] .tagline{color:#c5a898}[data-theme=dark] .control input,[data-theme=dark] .control select{background:#231914;border-color:#563e33;color:#d9c8bc}[data-theme=dark] .control input:focus,[data-theme=dark] .control select:focus{background:#281d17;border-color:var(--brown,#b85d34);color:#d9c8bc}[data-theme=dark] .control input::-moz-placeholder{color:#8a6c5d}[data-theme=dark] .control input::placeholder{color:#8a6c5d}[data-theme=dark] .control .lead-icon{stroke:#c5a898}[data-theme=dark] .btn-google{background:#231914;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-google:hover{background:#2f211a;border-color:#7a4b3a}[data-theme=dark] .actions--full .or:after,[data-theme=dark] .actions--full .or:before{background:#563e33}[data-theme=dark] .actions--full .or{color:#c5a898}[data-theme=dark] .back-link,[data-theme=dark] .btn-resend-link,[data-theme=dark] .forgot,[data-theme=dark] .form-footer a,[data-theme=dark] .resend-alt-link{color:#e2a07f}[data-theme=dark] .readonly-email-box{background-color:#231914!important;color:#c5a898!important;border-color:#563e33!important}[data-theme=dark] .landlord-dashboard-page .topbar h1,[data-theme=dark] .landlord-notifications-page .topbar h1,[data-theme=dark] .landlord-properties-page .topbar h1,[data-theme=dark] .landlord-settings-page .topbar h1,[data-theme=dark] .landlord-tenants-page .topbar h1{color:var(--head,#fff7f2)!important}html[data-theme=dark] .stat-card{background:#241813!important;border:1px solid #453127!important;color:var(--text,#e2d3c8)!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .stat-card :is(.card-label,.card-value,.card-suffix,.card-subtext,.card-subtext-link,.stat-card-title,.stat-card-label,.stat-card-value,.stat-card-desc){color:#fff7f2!important}html[data-theme=dark] .stat-card :is(.card-subtext,.card-subtext-link,.stat-card-desc){opacity:.9}html[data-theme=dark] .stat-card :is(.stat-card-icon,.stat-icon){background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .stat-card svg{color:#fff7f2!important;stroke:#fff7f2!important}[data-theme=dark] .recent-bookings-card{background:#2b1f19!important;border:1px solid var(--line,#4b382d)!important;box-shadow:0 4px 20px rgba(0,0,0,.4)!important}[data-theme=dark] .recent-bookings-header{border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .recent-bookings-view-all{background:var(--brown)!important;color:#1a0f0a!important}[data-theme=dark] .recent-bookings-view-all:hover{background:var(--brown-dark)!important;color:#1a0f0a!important}[data-theme=dark] .recent-bookings-footer{border-top:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-table td{border-bottom:1px solid var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .recent-bookings-table tbody tr:hover{background:#34261f!important}[data-theme=dark] .landlord-onboarding-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .onboarding-step-box{background:#231a14!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .onboarding-step-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .landlord-properties-page .um-search{background:#231a14!important;border-color:#5c4639!important}[data-theme=dark] .landlord-properties-page .um-search input{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-search input::-moz-placeholder{color:#d9c8bc!important;opacity:.9}[data-theme=dark] .landlord-properties-page .um-search input::placeholder{color:#d9c8bc!important;opacity:.9}[data-theme=dark] .landlord-properties-page .um-select-btn{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-select-btn,[data-theme=dark] .landlord-properties-page .um-select-menu{background:#231a14!important;border-color:#5c4639!important}[data-theme=dark] .landlord-properties-page .um-select-menu li{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-select-menu li:hover,[data-theme=dark] .landlord-properties-page .um-select-menu li[aria-selected=true]{background:#34261f!important;color:#fff7f2!important}[data-theme=dark] .landlord-properties-page .dorm-card{background:#241813!important;border-color:#453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}[data-theme=dark] .landlord-properties-page .dorm-card:hover{background:#2d1f19!important;border-color:#5d4235!important;box-shadow:0 16px 36px rgba(0,0,0,.5)!important}[data-theme=dark] .landlord-properties-page .dorm-card-address,[data-theme=dark] .landlord-properties-page .dorm-card-rent-label,[data-theme=dark] .landlord-properties-page .dorm-card-rent-value,[data-theme=dark] .landlord-properties-page .dorm-card-title{color:#fff7f2!important}[data-theme=dark] .landlord-properties-page .dorm-card-amenity,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap{background:rgba(255,247,242,.1)!important;color:#fff!important;border:1px solid rgba(255,247,242,.18)!important}[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill strong,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill svg,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenant-name,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenants-vacant,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenants-vacant span{color:#fff!important}[data-theme=dark] .landlord-properties-page .dorm-card-tenant-badge{background:rgba(255,247,242,.12)!important;border:1px solid rgba(255,247,242,.22)!important}[data-theme=dark] dialog.dorm-inspect-modal{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-inspect-col-right{background:#241a15!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-modal-rooms-header{border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .dorm-modal-rooms-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .dorm-modal-room-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-modal-room-photo-item{background:#1e1612!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .add-prop-page1-grid .form-col-right,[data-theme=dark] .add-prop-page2-grid{background:#241a15!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .rooms-section-header{background:#241a15!important;border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .rooms-section-header h3{color:var(--head,#fff7f2)!important}[data-theme=dark] .room-item-box:nth-child(2n){background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .room-item-box:nth-child(2n) .amenity-checkbox-item,[data-theme=dark] .room-item-box:nth-child(2n) .field-input,[data-theme=dark] .room-item-box:nth-child(2n) .photo-drop-box,[data-theme=dark] .room-item-box:nth-child(2n) select.field-input{background:#231a14!important;color:var(--ink,#fff7f2)!important}[data-theme=dark] .add-room-dashed-btn{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .add-room-dashed-btn:hover{background:#34261f!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .add-prop-modal .modal-footer{background:#1e1612!important;border-top-color:var(--line,#4b382d)!important}[data-theme=dark] .map-wrapper{border-color:var(--line,#4b382d)!important}[data-theme=dark] .landlord-tenants-page .card,[data-theme=dark] .tenants-table-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .tenants-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .tenants-table td{border-bottom:1px solid var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .tenants-table tbody tr:hover{background:#34261f!important}[data-theme=dark] .btn-filter-tab,[data-theme=dark] .filter-pill{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .btn-filter-tab.is-active,[data-theme=dark] .filter-pill.is-active{background:var(--brown,#c48974)!important;color:#fff!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .tenant-drawer{background:#2b1f19!important;border-left-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important;box-shadow:-10px 0 40px rgba(0,0,0,.6)!important}[data-theme=dark] .tenant-drawer-header{background:#231a14!important;border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .tenant-drawer-heading{color:var(--head,#fff7f2)!important}[data-theme=dark] .tenant-drawer-close{color:#dca08b!important}[data-theme=dark] .tenant-drawer-close:hover{color:#fff7f2!important}[data-theme=dark] .tenant-detail-section{background:#241a15!important;border:1px solid var(--line,#4b382d)!important;border-radius:14px}[data-theme=dark] .notification-hero h2{color:var(--head,#fff7f2)!important}[data-theme=dark] .notification-tab-btn{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .notification-tab-btn.is-active{background:var(--brown,#c48974)!important;color:#fff!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .notification-entry{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .notification-entry.is-unread{background:#34261f!important;border-color:#5f4435!important}[data-theme=dark] .notification-entry strong{color:var(--head,#fff7f2)!important}[data-theme=dark] .notification-timestamp{color:#a89486!important}[data-theme=dark] .settings-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .settings-tabs{background:#231a14!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .settings-tab-btn{color:#c5a898!important}[data-theme=dark] .settings-tab-btn.is-active{background:#34261f!important;color:var(--head,#fff7f2)!important}[data-theme=dark] .settings-label{color:var(--head,#fff7f2)!important}[data-theme=dark] .settings-input,[data-theme=dark] .settings-select{background:#1e1612!important;border-color:var(--line,#4b382d)!important;color:var(--ink,#fff7f2)!important}[data-theme=dark] .password-box{background:#231a14!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .danger-zone-box{border-top-color:hsla(0,91%,71%,.35)!important}[data-theme=dark] .danger-zone-box p{color:#c5a898!important}[data-theme=dark] .settings-modal-card{box-shadow:0 24px 60px rgba(0,0,0,.6)!important}[data-theme=dark] .cropper-modal-card,[data-theme=dark] .settings-modal-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .cropper-modal-header{background:#231a14!important;border-bottom-color:var(--line,#4b382d)!important}html[data-theme=dark] .rm-room-btn{background:#2a1a17!important;border-color:#7f1d1d!important;color:#f87171!important}html[data-theme=dark] .rm-room-btn:hover{background:#3b1c18!important;border-color:#dc2626!important;color:#fca5a5!important}html[data-theme=dark] #landing-toast,html[data-theme=dark] #notification-toast,html[data-theme=dark] #profileToast,html[data-theme=dark] .admin-verif-toast,html[data-theme=dark] .booking-toast,html[data-theme=dark] .notification-toast,html[data-theme=dark] .profile-toast,html[data-theme=dark] .room-booking-success-toast,html[data-theme=dark] .toast,html[data-theme=dark] .toast-popup{background:#32241d!important;border:1px solid var(--line,#4b382d)!important;color:var(--ink,#fff7f2)!important;box-shadow:0 16px 36px rgba(0,0,0,.55)!important}html[data-theme=dark] #confirmModal,html[data-theme=dark] #confirmModal *,html[data-theme=dark] #dorm-modal,html[data-theme=dark] #dorm-modal *,html[data-theme=dark] #inspectModal,html[data-theme=dark] #inspectModal *,html[data-theme=dark] #rejectModal,html[data-theme=dark] #rejectModal *,html[data-theme=dark] #resultModal,html[data-theme=dark] #resultModal *,html[data-theme=dark] #room-modal,html[data-theme=dark] #room-modal *,html[data-theme=dark] #suspend-modal,html[data-theme=dark] #suspend-modal *,html[data-theme=dark] .cl-dialog,html[data-theme=dark] .cl-dialog *,html[data-theme=dark] .confirm-modal-box,html[data-theme=dark] .confirm-modal-box *,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .cropper-modal-card *,html[data-theme=dark] .delete-account-modal,html[data-theme=dark] .delete-account-modal *,html[data-theme=dark] .inspect-box,html[data-theme=dark] .inspect-box *,html[data-theme=dark] .inspect-modal,html[data-theme=dark] .inspect-modal *,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .logout-modal-card *,html[data-theme=dark] .modal,html[data-theme=dark] .modal *,html[data-theme=dark] .modal-box,html[data-theme=dark] .modal-box *,html[data-theme=dark] .modal-card,html[data-theme=dark] .modal-card *,html[data-theme=dark] .modal-panel,html[data-theme=dark] .modal-panel *,html[data-theme=dark] .policy-modal,html[data-theme=dark] .policy-modal *,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .policy-modal-panel *,html[data-theme=dark] .ps-card,html[data-theme=dark] .ps-card *,html[data-theme=dark] .settings-modal-card,html[data-theme=dark] .settings-modal-card *,html[data-theme=dark] .suspend-modal-dialog,html[data-theme=dark] .suspend-modal-dialog *,html[data-theme=dark] dialog,html[data-theme=dark] dialog *,html[data-theme=dark] dialog.us-confirm,html[data-theme=dark] dialog.us-confirm *{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}html[data-theme=dark] .cl-dialog h2,html[data-theme=dark] .cropper-modal-title,html[data-theme=dark] .delete-modal-title,html[data-theme=dark] .inspect-box h3,html[data-theme=dark] .logout-modal-title,html[data-theme=dark] .modal-panel h2,html[data-theme=dark] .modal-panel h3,html[data-theme=dark] .policy-card h2,html[data-theme=dark] .policy-modal-header h2,html[data-theme=dark] .ps-card h2,html[data-theme=dark] .suspend-modal-dialog h2,html[data-theme=dark] dialog h1,html[data-theme=dark] dialog h2,html[data-theme=dark] dialog h3,html[data-theme=dark] dialog h4,html[data-theme=dark] dialog.us-confirm .us-confirm-panel h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;color:var(--head,#fff7f2)!important}html[data-theme=dark] body.landlord-settings-page .shell,html[data-theme=dark] body.landlord-tenants-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body.landlord-settings-page .topbar,html[data-theme=dark] body.landlord-tenants-page .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page .requests-page-title,html[data-theme=dark] body.landlord-tenants-page .topbar h1{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .tenants-table tr:hover td{background:#34261f!important}html[data-theme=dark] body.landlord-tenants-page .dorms-stay-filters{background:#5c4538!important;border-color:#6e5344!important}html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn{color:#e2d3c8!important}html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn.is-active,html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn:hover{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .stay-pill{background:#5c4538!important;box-shadow:inset 0 -1px 0 #e2ab94!important}html[data-theme=dark] body.landlord-tenants-page .request-room-meta,html[data-theme=dark] body.landlord-tenants-page .tenant-meta-email{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer{background:#2b1f19!important;border-left-color:#563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-drawer-header{background:#231914!important;border-bottom-color:#563e33!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-header,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .settings-section-title{border-bottom-color:#563e33!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-title,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-name,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-card>span,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-thumb span,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-label,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-role-badge,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-role-separator,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-verification-status{color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-card,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-avatar{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-avatar{border-width:2px!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer #drawer-status-pill{background:#3b2b23!important;border:1px solid #6e5344!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-thumb{background:#1a110d!important;border:1px solid #563e33!important;color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-view-btn,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val a{color:#e2ab94!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val span{color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .badge-verified-student{background:#183b2a!important;border:1px solid #357653!important;color:#bbf7d0!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-verification-pending{background:#47351e!important;border-color:#8b692d!important;color:#fde68a!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:4px!important;height:auto!important;padding:3px 9px!important;border:1px solid #c48974!important;border-radius:6px!important;background:#3b2b23!important;color:#fff7f2!important;font:700 .74rem DM Sans,sans-serif!important;line-height:1.3!important;box-shadow:none!important;transform:none!important;transition:all .16s ease!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details:hover{background:#5f4435!important;border-color:#e2ab94!important;color:#fff7f2!important;filter:none!important;scale:none!important;transform:translateY(-1px)!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details:active{background:#4b382d!important;border-color:#e2ab94!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;padding:9px 18px!important;border:1px solid hsla(0,91%,71%,.45)!important;border-radius:10px!important;background:#231914!important;color:#fca5a5!important;font:600 13px DM Sans,sans-serif!important;box-shadow:none!important;transform:scale(1)!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end:hover:not(.is-loading){background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important;transform:scale(1.06)!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end:active:not(.is-loading){background:rgba(220,38,38,.28)!important;border-color:#f87171!important;color:#fecaca!important;transform:scale(1.1)!important}html[data-theme=dark] .dorms-stay-filters,html[data-theme=dark] .tenants-tabs{background:transparent;border-top-color:#6e5344;border-bottom-color:#6e5344}dialog.us-confirm{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);margin:0;width:min(92vw,480px);max-width:480px;padding:0;border:none;border-radius:24px;background:#fffdf9;color:#3b2820;box-shadow:0 28px 70px rgba(55,31,22,.28);text-align:center;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden}dialog.us-confirm::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);animation:us-confirm-fade .22s ease both}dialog.us-confirm[open]{animation:us-confirm-pop .28s cubic-bezier(.16,1,.3,1) both}@keyframes us-confirm-pop{0%{opacity:0;transform:translate(-50%,-46%) scale(.97)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}dialog.us-confirm.is-closing{animation:us-confirm-out .2s cubic-bezier(.2,.8,.2,1) both;pointer-events:none}@keyframes us-confirm-out{0%{opacity:1;transform:translate(-50%,-50%) scale(1)}to{opacity:0;transform:translate(-50%,-46%) scale(.97)}}@keyframes us-confirm-fade{0%{opacity:0}to{opacity:1}}.us-confirm-panel{position:relative;padding:38px 34px 30px;display:flex;flex-direction:column;align-items:center;text-align:center;box-sizing:border-box}.us-confirm-close{position:absolute;top:18px;right:18px;display:inline-flex;align-items:center;justify-content:center;width:auto;height:auto;padding:0;border:none;border-radius:0;background:none;color:#7a4b3a;font-size:26px;line-height:1;cursor:pointer;box-shadow:none;transition:color .15s ease,transform .15s ease}.us-confirm-close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.us-confirm-close:active{background:none;color:#1a0f0a;transform:scale(.92)}.us-confirm-icon{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 20px;border-radius:50%;background:#f7efe5;color:#7a4b3a}.us-confirm-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.us-confirm-icon.is-danger{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}.us-confirm-panel h2{margin:0 0 10px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px;font-weight:700;line-height:1.3;letter-spacing:-.2px;color:#3b2820;text-align:center}.us-confirm-panel p{margin:0 auto 26px;max-width:390px;color:#745f54;line-height:1.6;font-size:14px;text-align:center}.us-confirm-actions{display:flex;align-items:center;justify-content:center;gap:12px;width:100%}.us-confirm-btn-primary,.us-confirm-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 24px;border-radius:12px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14.5px;font-weight:700;cursor:pointer;transition:all .18s ease;box-sizing:border-box;flex:1}.us-confirm-btn-secondary{border:1px solid #d8c3b4;background:#fff;color:#6b574e;font-weight:600;box-shadow:0 2px 6px rgba(0,0,0,.04)}.us-confirm-btn-secondary:hover{background:#fcf7f4;color:#3b2820;border-color:#c4aca0}.us-confirm-btn-primary{border:none;background:#7a4b3a;color:#fff;box-shadow:0 8px 20px rgba(122,75,58,.24)}.us-confirm-btn-primary:hover{background:#613829;transform:translateY(-1px);box-shadow:0 10px 24px rgba(122,75,58,.3)}.us-confirm-btn-primary.is-danger{background:#dc2626!important;border:none!important;color:#fff!important;box-shadow:0 8px 20px rgba(220,38,38,.28)!important}.us-confirm-btn-primary.is-danger:hover{background:#b91c1c!important;transform:translateY(-1px)!important;box-shadow:0 10px 24px rgba(220,38,38,.35)!important}.us-confirm-btn-primary:focus-visible,.us-confirm-btn-secondary:focus-visible,.us-confirm-close:focus-visible{outline:3px solid #ac7728;outline-offset:3px}@media (max-width:480px){.us-confirm-panel{padding:30px 22px 24px}.us-confirm-panel h2{font-size:24px}.us-confirm-actions{flex-direction:column-reverse}.us-confirm-btn-primary,.us-confirm-btn-secondary{width:100%}}@media (prefers-reduced-motion:reduce){dialog.us-confirm.is-closing,dialog.us-confirm::backdrop,dialog.us-confirm[open]{animation:none}.us-confirm-btn-primary,.us-confirm-btn-secondary{transition:none}}[data-theme=dark] dialog.us-confirm{background:#2b1f19;color:#fff7f2;border:1px solid var(--line,#4b382d);box-shadow:0 28px 70px rgba(0,0,0,.65)}[data-theme=dark] dialog.us-confirm::backdrop{background:rgba(18,12,9,.7)}[data-theme=dark] .us-confirm-panel h2{color:#fff7f2}[data-theme=dark] .us-confirm-panel p{color:#d9c8bc}[data-theme=dark] .us-confirm-close{color:#dca08b}[data-theme=dark] .us-confirm-close:hover{color:#fff7f2}[data-theme=dark] .us-confirm-icon{background:#382a22;color:#dca08b}[data-theme=dark] .us-confirm-icon.is-danger{background:rgba(197,59,42,.22);color:#f87171}[data-theme=dark] .us-confirm-btn-secondary{background:#382a22;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .us-confirm-btn-secondary:hover{background:#45332a;color:#fff7f2;border-color:#5f4435}.topbar h1{letter-spacing:-.6px}.requests-page-title,.topbar h1{font-family:Fraunces,Georgia,Times New Roman,serif!important;font-weight:700!important;color:#5f3425!important}.requests-page-title{font-size:1.6rem;margin:0 0 4px}.tenants-container{z-index:1;gap:24px;padding-bottom:40px}.tenants-container,.topbar{position:relative;display:flex;flex-direction:column}.topbar{z-index:1000;height:auto;align-items:stretch;border-bottom:1px solid #ebd9cb;padding:0;gap:0}.topbar-main-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 4px}.dorms-stay-filters,.tenants-tabs{position:relative;display:flex;width:100%;margin:0;padding:0;border-top:1px solid #ebd9cb;border-bottom:1px solid #ebd9cb;border-radius:0;background:hsla(38,67%,98%,.7);overflow-x:auto;scrollbar-width:none;z-index:2}.dorms-stay-filters::-webkit-scrollbar,.tenants-tabs::-webkit-scrollbar{display:none}.stay-filter-btn,.tab-btn{flex:1 1 0;min-width:0;position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;height:auto;min-height:48px;padding:14px 12px;border:0;border-bottom:3px solid transparent;border-top:none;border-radius:0;background:transparent;color:#7a4b3a;font-family:DM Sans,sans-serif;font-size:.94rem;font-weight:600;text-decoration:none;white-space:nowrap;cursor:pointer;transition:color .2s ease,background-color .2s ease}.stay-pill{position:absolute;bottom:0;top:auto;left:0;height:3px;border-radius:3px 3px 0 0;background:#fff;box-shadow:none;z-index:3;pointer-events:none;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}html[data-theme=dark] .stay-pill{background:#2b1f19}.stay-filter-btn:hover:not(.is-active):not(.active),.tab-btn:hover:not(.active):not(.is-active){color:#5f3425;background:rgba(122,75,58,.04)}.stay-filter-btn.active,.stay-filter-btn.is-active,.tab-btn.active,.tab-btn.is-active{background:rgba(122,75,58,.02)!important;color:#5f3425!important;font-weight:700;border-bottom-color:transparent;border-top-color:transparent;box-shadow:none!important}@media (prefers-reduced-motion:reduce){.stay-pill{transition:none!important}}.stay-filter-count,.tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;font-size:.72rem;font-weight:700;background:#ebd9cb;color:#5f3425;transition:background .2s ease,color .2s ease}.stay-filter-btn.active .stay-filter-count,.stay-filter-btn.is-active .stay-filter-count,.tab-btn.active .tab-count,.tab-btn.is-active .tab-count{background:#7a4b3a;color:#fff}.btn-booking-details,.btn-view-properties{font:600 .88rem DM Sans,sans-serif;padding:0 22px;height:42px;border-radius:8px;border:1px solid var(--brown,#7a4b3a);background:var(--brown,#7a4b3a);color:#fff!important;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(122,75,58,.18);transform:scale(1);transform-origin:center;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease;margin-top:14px}.btn-booking-details:focus-visible,.btn-booking-details:hover,.btn-view-properties:focus-visible,.btn-view-properties:hover{color:#fff!important;background:var(--brown-dark,#5f3425);border-color:var(--brown-dark,#5f3425);transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.24)}.btn-booking-details:active,.btn-view-properties:active{color:#fff!important;background:var(--brown-darker,#432419);border-color:var(--brown-darker,#432419);transform:scale(1.12);box-shadow:0 10px 28px rgba(122,75,58,.3)}.plain-empty-state{background:transparent;border:none;box-shadow:none;padding:60px 24px 80px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#8c7569}.plain-empty-icon{font-size:3rem;margin-bottom:12px;display:block}.plain-empty-title{margin:0 0 6px;color:#292524;font-family:Fraunces,Georgia,serif;font-size:20px;font-weight:700}.plain-empty-desc{margin:0 0 16px;font-size:.92rem;color:#8c7569;max-width:420px}.tenants-table-card{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 6px 18px rgba(92,62,48,.08);overflow:hidden}.tenants-table{width:100%;border-collapse:collapse;text-align:left}.tenants-table th{background:#fbf5ec;padding:16px 20px;font-size:.78rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);border-bottom:1px solid var(--line)}.tenants-table td{padding:18px 20px;border-bottom:1px solid #f3ece4;font-size:.92rem;color:var(--text-dark)}.tenants-table tr:last-child td{border-bottom:none}.tenants-table tr:hover td{background:#fdfaf6}.tenant-cell-user{display:flex;align-items:center;gap:12px}.tenant-avatar-sm{width:44px;height:44px;border-radius:50%;background:#fbf4ee;border:1px solid var(--line);color:var(--head);font-weight:700;font-size:14px;display:grid;place-items:center;overflow:hidden;flex-shrink:0}.tenant-avatar-sm img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.tenant-meta-name{font-weight:700;color:var(--head);display:flex;align-items:center;gap:6px;flex-wrap:wrap}.tenant-meta-email{font-size:.8rem;color:var(--text-muted)}.btn-action-approve{padding:8px 18px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:background .18s,transform .18s,opacity .18s}.btn-action-approve:hover:not(.is-loading){background:#15803d;transform:translateY(-1px)}.btn-action-reject{padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:background .18s,transform .18s,opacity .18s}.btn-action-reject:hover:not(.is-loading){background:#b91c1c;transform:translateY(-1px)}.btn-action-verify{padding:8px 18px;background:var(--brown,#7a4b3a);color:#fff!important;border:none;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;transition:background .18s,transform .18s,opacity .18s}.btn-action-verify:hover{background:var(--brown-dark,#5f3425);transform:translateY(-1px);color:#fff!important}html[data-theme=dark] .btn-action-verify{background:#c48974!important;color:#1e1612!important}html[data-theme=dark] .btn-action-verify:hover{background:#efc2ae!important}.btn-action-approve.is-loading,.btn-action-end.is-loading,.btn-action-reject.is-loading{opacity:.85;cursor:wait;pointer-events:none;transform:none!important}.btn-action-end{padding:8px 18px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .18s cubic-bezier(.16,1,.3,1);transform-origin:center}.btn-action-end:hover:not(.is-loading){background:#e5e7eb;color:#111827;transform:scale(1.06);box-shadow:0 4px 12px rgba(55,65,81,.12)}.btn-action-end:active:not(.is-loading){transform:scale(1.1);background:#d1d5db;box-shadow:0 6px 16px rgba(55,65,81,.18)}.booking-status-tag.declined,.booking-status-tag.rejected{background:#fee2e2;color:#991b1b}.nav-badge-pill{background:#dc2626;color:#fff;font-size:.72rem;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:auto}.badge-verified-student{gap:3px;background:#dcfce7;color:#15803d;font-size:.7rem;padding:2px 7px;border-radius:999px}.badge-verified-student,.btn-action-details{display:inline-flex;align-items:center;font-weight:700}.btn-action-details{padding:7px 14px;background:#fff;color:#7a4b3a;border:1px solid #d8c2b4;border-radius:8px;font-size:.82rem;cursor:pointer;justify-content:center;gap:4px;transition:all .18s ease}.btn-action-details:hover{background:#fdf8f4;border-color:#7a4b3a;color:#5c3527;transform:translateY(-1px);box-shadow:0 2px 8px rgba(122,75,58,.12)}.tenant-clickable-name{cursor:pointer;transition:color .15s ease}.tenant-clickable-name:hover{color:#7a4b3a;text-decoration:underline}.btn-tenant-view-details{background:#fbf5ef;color:#7a4b3a;border:1px solid #ebd9cb;border-radius:6px;padding:3px 9px;font-size:.74rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .16s ease;line-height:1.3}.btn-tenant-view-details:hover{background:#7a4b3a;color:#fff;border-color:#7a4b3a;transform:translateY(-1px);box-shadow:0 2px 6px rgba(122,75,58,.15)}.tenant-drawer-overlay{position:fixed;inset:0;background:rgba(41,37,36,.45);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:9998;opacity:0;visibility:hidden;transition:opacity .28s ease,visibility .28s ease}.tenant-drawer-overlay.is-open{opacity:1;visibility:visible}.tenant-drawer{position:fixed;top:0;right:0;bottom:0;width:620px;max-width:95vw;background:#fff;box-shadow:-10px 0 40px rgba(0,0,0,.18);z-index:9999;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .3s cubic-bezier(.16,1,.3,1);border-left:1px solid #ebd9cb;border-top-left-radius:24px;border-bottom-left-radius:24px;overflow:hidden}.tenant-drawer.is-open{transform:translateX(0)}.tenant-drawer-header{display:flex;align-items:center;justify-content:space-between;padding:20px 28px;border-bottom:1px solid #f0e6e0;background:#fdfaf7;flex-shrink:0;border-top-left-radius:24px}.tenant-drawer-heading{font-family:Fraunces,Georgia,serif;font-size:1.25rem;font-weight:700;color:#3b2820;margin:0}.tenant-drawer-close{background:transparent;border:none;width:34px;height:34px;border-radius:0;display:flex;align-items:center;justify-content:center;font-size:18px;color:#5c3527;cursor:pointer;transition:color .18s,transform .18s}.tenant-drawer-close:hover{background:transparent;color:#292524;transform:scale(1.1)}.tenant-drawer-close:active{background:transparent;color:#1a0f0a;transform:scale(.92)}.tenant-drawer-body{flex:1;overflow-y:auto;padding:24px 28px 40px}.tenant-role-separator,.tenant-verification-status{color:#8b7467;font-size:.78rem;font-weight:700;line-height:1}.tenant-verification-status{background:none;border:0;padding:0}.tenant-verification-waiting{display:inline-block;color:#92400e;font-size:.78rem;font-weight:700;line-height:1.25;text-align:center}.tenant-action-cancelled,.tenant-action-ended{color:#6b7280}.tenant-action-cancelled,.tenant-action-ended,.tenant-action-required{display:inline-block;font-size:.8rem;line-height:1.25;text-align:center}.tenant-action-required{color:#b45309;font-weight:600}html[data-theme=dark] .tenant-verification-waiting{color:#fde68a}html[data-theme=dark] .tenant-action-cancelled,html[data-theme=dark] .tenant-action-ended{color:#d9c8bc}html[data-theme=dark] .tenant-action-required{color:#fbbf24}html[data-theme=dark] #notification-toast.notification-toast{background:#ecdccb!important;border-top:none!important;border-right:none!important;border-bottom:none!important;color:#3b2820!important;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08)!important}html[data-theme=dark] #notification-toast.notification-toast strong{color:#3b2820!important}html[data-theme=dark] #notification-toast.notification-toast span{color:#5a4236!important}.hover\:bg-slate-50:hover{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity,1))}.hover\:bg-slate-900:hover{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}html[data-theme=dark] #decline-booking-modal textarea{background:#231914!important;border-color:#563e33!important;color:#fff7f2!important}html[data-theme=dark] #decline-booking-modal #declineModalTitle{color:#fff7f2!important}html[data-theme=dark] #decline-booking-modal #declineModalDesc{color:#c5a898!important}html[data-theme=dark] #decline-booking-modal label{color:#d9c8bc!important}html[data-theme=dark] #decline-booking-modal .us-confirm-panel{background:#2b1f19!important;border:1px solid #563e33!important;color:#fff7f2!important}
/* landlord-tailwind:requests:end */
    </style>
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

<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js?v=11"></script>
<script src="../assets/js/tenant/profile-menu.js?v=33"></script>
</head>
<body class="landlord-tenants-page">
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
<a href="requests.php" title="Requests" aria-current="page">
  <svg aria-hidden="true"><use href="#user-icon"/></svg>
  <span class="nav-label">Requests</span>
  <?php if ($counts['pending'] > 0): ?>
    <span class="nav-badge-pill" id="sidebar-requests-badge" <?= $counts['pending'] > 0 ? '' : 'style="display:none;"' ?>><?= $counts['pending'] > 99 ? '99+' : ($counts['pending'] > 0 ? $counts['pending'] : '') ?></span>
  <?php endif; ?>
</a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
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
        <a class="view-all" id="view-all" href="notifications.php?from=requests" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
      </section>
    </div>
  </div>

  <!-- Filter Tabs: Rectangular Pills matching Dorms -->
  <div class="dorms-stay-filters tenants-tabs" role="tablist" aria-label="Filter by request status">
      <span class="stay-pill" aria-hidden="true"></span>
      <a href="requests.php?status=all" data-status="all" class="stay-filter-btn tab-btn <?= $statusFilter === 'all' ? 'is-active active' : '' ?>">
          <span>All Bookings</span>
          <span class="stay-filter-count tab-count"><?= $counts['all'] ?></span>
      </a>
      <a href="requests.php?status=pending" data-status="pending" class="stay-filter-btn tab-btn <?= $statusFilter === 'pending' ? 'is-active active' : '' ?>">
          <span>Pending Requests</span>
          <span class="stay-filter-count tab-count"><?= $counts['pending'] ?></span>
      </a>
      <a href="requests.php?status=approved" data-status="approved" class="stay-filter-btn tab-btn <?= ($statusFilter === 'approved' || $statusFilter === 'active') ? 'is-active active' : '' ?>">
          <span>Approved Residents</span>
          <span class="stay-filter-count tab-count"><?= $counts['approved'] ?></span>
      </a>
      <a href="requests.php?status=declined" data-status="declined" class="stay-filter-btn tab-btn <?= ($statusFilter === 'declined' || $statusFilter === 'rejected') ? 'is-active active' : '' ?>">
          <span>Declined Residents</span>
          <span class="stay-filter-count tab-count"><?= $counts['declined'] ?></span>
      </a>
      <a href="requests.php?status=ended" data-status="ended" class="stay-filter-btn tab-btn <?= $statusFilter === 'ended' ? 'is-active active' : '' ?>">
          <span>Ended Tenancies</span>
          <span class="stay-filter-count tab-count"><?= $counts['ended'] ?></span>
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
                        <th>Booking Status</th>
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
                                                <img src="<?= htmlspecialchars($t['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                                                <span style="display:none;"><?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php else: ?>
                                                <span><?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="tenant-meta-name">
                                                <span role="button" tabindex="0" onclick="openTenantDrawer(<?= $bId ?>)" class="tenant-clickable-name" title="Click to view tenant details"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (($b['tenant_verification'] ?? '') === 'verified'): ?>
                                                    <span class="verified-check" role="img" aria-label="Verified student" title="Verified student"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
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
                                    <span class="request-room-meta" style="display:block; font-size:0.78rem; color:var(--text-muted);">
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
                                                <form method="POST" style="display:inline-flex; flex-direction:column; align-items:stretch; gap:6px; margin:0;">
                                                    <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                                    <button type="submit" name="booking_action" value="approve" class="btn-action-approve" title="Approve booking">Approve</button>
                                                    <button type="submit" name="booking_action" value="decline" class="btn-action-reject" title="Decline booking"
                                                        data-confirm-crumb="UniStay &bull; Booking Request"
                                                        data-confirm-title="Decline this booking request?"
                                                        data-confirm-message="<?= htmlspecialchars($declineMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-confirm-ok="Yes, decline"
                                                        data-confirm-cancel="Keep request">Decline</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="tenant-action-required">Landlord verification<br>required</span>
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
                                        <?php elseif ($st === 'ended'): ?>
                                            <span class="tenant-action-ended">Tenancy<br>ended</span>
                                        <?php elseif ($st === 'cancelled'): ?>
                                            <span class="tenant-action-cancelled">Stay<br>cancelled</span>
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

    const verifiedCheckHtml = vStatus === 'verified'
        ? '<span class="verified-check" role="img" aria-label="Verified student" title="Verified student"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span>'
        : '';

    if (vStatus === 'verified') {
        verifBadgeHtml = '';
    } else if (vStatus === 'pending' || (vStatus === 'unverified' && (booking.school_id || booking.gov_id))) {
        verifBadgeHtml = '<span class="tenant-verification-pending" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:700;">⏳ Verification Pending</span>';
    } else {
        verifBadgeHtml = '<span class="tenant-verification-status">Unverified</span>';
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
        const approveControl = vStatus === 'verified'
            ? `<form method="POST" style="display:inline-flex; align-items:center; margin:0;">
                    <input type="hidden" name="booking_id" value="${booking.id}">
                    <button type="submit" name="booking_action" value="approve" class="btn-action-approve" style="padding:10px 22px; font-size:0.9rem;">Approve Booking</button>
               </form>`
            : `<span style="display:inline-flex; align-items:center; font-weight:600; color:#854d0e; background:#fef9c3; border:1px solid #fef08a; padding:8px 16px; border-radius:10px; font-size:0.88rem;">Awaiting verification</span>`;
        actionsFooter = `
            <div style="margin-top:28px; padding-top:20px; border-top:1px solid #ebd9cb; display:flex; gap:10px; justify-content:flex-end;">
                ${approveControl}
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
                    <h2 class="profile-view-name">${escapeHtml(booking.full_name)}${verifiedCheckHtml}</h2>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                        <span class="profile-role-badge">TENANT</span>
                        <span class="tenant-role-separator" aria-hidden="true">·</span>
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
        const res = await fetch('requests.php', {
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
                            applyStatusFilter(currentTab);
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

// Decline feedback modal helper
function promptDeclineFeedback(opts) {
    return new Promise((resolve) => {
        const dlg = document.getElementById('decline-booking-modal');
        if (!dlg) {
            resolve({ confirmed: true, feedback: '' });
            return;
        }
        const titleEl = document.getElementById('declineModalTitle');
        const descEl = document.getElementById('declineModalDesc');
        const inputEl = document.getElementById('declineFeedbackInput');
        const cancelBtn = document.getElementById('declineModalCancelBtn');
        const confirmBtn = document.getElementById('declineModalConfirmBtn');
        const closeBtn = document.getElementById('declineModalCloseBtn');

        if (opts.title && titleEl) titleEl.textContent = opts.title;
        if (opts.message && descEl) descEl.textContent = opts.message;
        if (inputEl) inputEl.value = '';

        let resolved = false;
        function finish(confirmed) {
            if (resolved) return;
            resolved = true;
            const feedback = inputEl ? inputEl.value.trim() : '';
            dlg.classList.add('is-closing');
            setTimeout(() => {
                try { dlg.close(); } catch(_) {}
                dlg.classList.remove('is-closing');
                cleanup();
                resolve({ confirmed, feedback });
            }, 180);
        }

        function onCancel(e) { e?.preventDefault(); finish(false); }
        function onConfirm(e) { e?.preventDefault(); finish(true); }

        function cleanup() {
            cancelBtn?.removeEventListener('click', onCancel);
            closeBtn?.removeEventListener('click', onCancel);
            confirmBtn?.removeEventListener('click', onConfirm);
            dlg.removeEventListener('cancel', onCancel);
        }

        cancelBtn?.addEventListener('click', onCancel);
        closeBtn?.addEventListener('click', onCancel);
        confirmBtn?.addEventListener('click', onConfirm);
        dlg.addEventListener('cancel', onCancel);

        dlg.showModal();
        setTimeout(() => inputEl?.focus(), 50);
    });
}

// Styled confirmation modal for Decline / End Tenancy (replaces the browser alert)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-confirm-title]');
    if (!btn) return;
    e.preventDefault();

    const isDecline = btn.classList.contains('btn-action-reject');
    const isEnd = !isDecline;
    let feedbackVal = '';

    if (isDecline) {
        const res = await promptDeclineFeedback({
            title: btn.dataset.confirmTitle || 'Decline Booking Request',
            message: btn.dataset.confirmMessage || 'Are you sure you want to decline this booking request? You can provide a reason or feedback below for the student.'
        });
        if (!res.confirmed) return;
        feedbackVal = res.feedback || '';
    } else {
        const ok = await UniStayConfirm.open({
            crumb: btn.dataset.confirmCrumb,
            title: btn.dataset.confirmTitle,
            message: btn.dataset.confirmMessage,
            confirmText: btn.dataset.confirmOk,
            cancelText: btn.dataset.confirmCancel,
            danger: true
        });
        if (!ok) return;
    }

    const form = btn.form;
    if (!form) return;

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
        if (isDecline && feedbackVal) {
            formData.append('feedback', feedbackVal);
        }

        try {
            const res = await fetch('requests.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data && data.ok) {
                // Update in memory
                const bIndex = currentBookingsData.findIndex(b => String(b.id) === String(bookingId));
                if (bIndex !== -1) {
                    currentBookingsData[bIndex].status = isDecline ? 'declined' : 'ended';
                }

                // Update table row
                const row = document.getElementById(`booking-row-${bookingId}`);
                if (row) {
                    row.dataset.status = isDecline ? 'declined' : 'ended';
                    const statusTag = row.querySelector('.booking-status-tag');
                    if (statusTag) {
                        statusTag.className = 'booking-status-tag ' + (isDecline ? 'declined' : 'ended');
                        statusTag.textContent = isDecline ? 'declined' : 'ended';
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
                                applyStatusFilter(currentTab);
                                placeStayPill(true);
                            }, 350);
                        }, 900);
                    }
                }

                // Update drawer if open
                const statusPill = document.getElementById('drawer-status-pill');
                if (statusPill) {
                    statusPill.className = 'booking-status-tag ' + (isDecline ? 'declined' : 'ended');
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
                    const endedCountEl = document.querySelector('a[data-status="ended"] .tab-count');
                    if (endedCountEl) {
                        const count = parseInt(endedCountEl.textContent, 10) || 0;
                        endedCountEl.textContent = count + 1;
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

<!-- Decline Booking Feedback Modal -->
<dialog id="decline-booking-modal" class="us-confirm" aria-labelledby="declineModalTitle" aria-describedby="declineModalDesc">
  <div class="us-confirm-panel" role="alertdialog" aria-modal="true" style="max-width: 480px; width: 92%;">
    <button type="button" class="us-confirm-close" id="declineModalCloseBtn" aria-label="Close">&times;</button>
    <div class="us-confirm-icon is-danger" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <h2 id="declineModalTitle" style="font-size: 1.25rem; font-weight: 700; color: #1c1917; margin: 0 0 8px;">Decline Booking Request</h2>
    <p id="declineModalDesc" style="font-size: 0.9rem; color: #57534e; margin: 0 0 16px;">
      Are you sure you want to decline this booking request? You can provide a reason or feedback below for the student.
    </p>
    <div style="margin-bottom: 20px; text-align: left;">
      <label for="declineFeedbackInput" style="display: block; font-size: 0.85rem; font-weight: 600; color: #44403c; margin-bottom: 6px;">Feedback / Reason (Optional)</label>
      <textarea id="declineFeedbackInput" rows="3" style="width: 100%; box-sizing: border-box; border: 1px solid #d6d3d1; border-radius: 8px; padding: 10px 12px; font-family: inherit; font-size: 0.9rem; resize: vertical; outline: none; background: #fff; color: #1c1917;" placeholder="e.g., Room undergoing renovation, fully booked, or scheduling conflict..."></textarea>
    </div>
    <div class="us-confirm-actions">
      <button type="button" class="us-confirm-btn-secondary" id="declineModalCancelBtn">Cancel</button>
      <button type="button" class="us-confirm-btn-primary is-danger" id="declineModalConfirmBtn">Decline Request</button>
    </div>
  </div>
</dialog>

</body>
</html>
