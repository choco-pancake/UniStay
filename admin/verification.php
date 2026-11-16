<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/landlord_applications.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');
$adminInitials = strtoupper(substr(trim((string)($adminProfile['name'] ?? 'Admin')), 0, 1)) ?: 'A';
$successMessage = '';
$errorMessage = '';
$invitationUrl = '';
if (!empty($_SESSION['admin_verification_flash']) && is_array($_SESSION['admin_verification_flash'])) {
    $successMessage = (string)($_SESSION['admin_verification_flash']['success'] ?? '');
    $errorMessage = (string)($_SESSION['admin_verification_flash']['error'] ?? '');
    unset($_SESSION['admin_verification_flash']);
}
$rawView = $_GET['view'] ?? $_POST['view'] ?? '';
if ($rawView === 'applications') {
    $view = 'applications';
} elseif ($rawView === 'properties') {
    $view = 'properties';
} else {
    $view = 'accounts';
}
$applicationView = ($view === 'applications');
$propertyView = ($view === 'properties');
if (empty($_SESSION['admin_verification_csrf'])) {
    $_SESSION['admin_verification_csrf'] = bin2hex(random_bytes(32));
}

// Active Role & Filter (Defaulting to 'all' as requested)
$role = $_GET['role'] ?? $_POST['role'] ?? $_POST['target_role'] ?? 'tenant';
if (!in_array($role, ['landlord', 'tenant'], true)) {
    $role = 'tenant';
}

$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'pending', 'approved', 'verified', 'declined', 'rejected'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

function admin_decide_dorm_listing(PDO $pdo, int $propertyId, string $decision, string $adminName, ?string $notes = null): bool
{
    $statement = $pdo->prepare('SELECT id, landlord_id, name, approval_status FROM dorms WHERE id = ? LIMIT 1');
    $statement->execute([$propertyId]);
    $property = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$property) {
        return false;
    }

    $notes = trim((string)$notes);
    $status = $decision === 'approve' ? 'approved' : 'declined';
    $update = $pdo->prepare('UPDATE dorms SET approval_status = ?, approved_by = ?, feedback = ? WHERE id = ?');
    if (!$update->execute([$status, $adminName, $notes !== '' ? $notes : null, $propertyId])) {
        return false;
    }

    if ($status === 'approved') {
        add_in_app_notification(
            $pdo,
            (string)$property['landlord_id'],
            'approval',
            'Dorm Listing Approved! 🏠',
            "Great news! Your listing \"{$property['name']}\" has been reviewed and approved by UniStay administration. It is now published and visible to students.",
            null,
            'landlord'
        );
    } else {
        $reasonMessage = $notes !== ''
            ? " Reason: {$notes}"
            : ' Please ensure your proof of ownership and room photos meet the requirements before resubmitting.';
        add_in_app_notification(
            $pdo,
            (string)$property['landlord_id'],
            'system',
            'Dorm Listing Update',
            "Your listing \"{$property['name']}\" could not be approved at this time.{$reasonMessage}",
            null,
            'landlord'
        );
    }

    return true;
}

// Handle Admin Actions
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$requestedAction = (string)($_POST['action'] ?? '');
$isBulkAction = in_array($requestedAction, ['bulk_approve', 'bulk_decline'], true);

if ($requestMethod === 'POST' && $isBulkAction) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $bulkNotes = trim((string)($_POST['rejection_notes'] ?? ''));
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif ($requestedAction === 'bulk_decline' && $bulkNotes === '') {
        $errorMessage = 'Please provide a reason before declining pending records.';
    } else {
        $decision = $requestedAction === 'bulk_approve' ? 'approve' : 'decline';
        $adminName = trim((string)($adminProfile['name'] ?? ''))
            ?: (trim(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? '')) ?: 'Administrator');
        $pendingIds = [];
        $categoryLabel = '';

        if ($propertyView) {
            $pendingIds = $pdo->query("SELECT id FROM dorms WHERE approval_status = 'pending' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $categoryLabel = 'dorm listing';
        } elseif ($applicationView) {
            $pendingIds = $pdo->query("SELECT landlord_id FROM landlords WHERE status = 'pending' ORDER BY landlord_id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $categoryLabel = 'landlord application';
        } elseif ($role === 'tenant') {
            $pendingIds = $pdo->query("SELECT tenant_id FROM tenants WHERE verification_status = 'pending' OR (verification_status = 'unverified' AND ((school_id IS NOT NULL AND school_id != '') OR (gov_id IS NOT NULL AND gov_id != ''))) ORDER BY tenant_id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $categoryLabel = 'student tenant';
        } else {
            $pendingIds = $pdo->query("SELECT landlord_id FROM landlords WHERE status = 'pending' ORDER BY landlord_id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $categoryLabel = 'landlord';
        }

        $processed = 0;
        $failed = 0;
        foreach ($pendingIds as $pendingId) {
            $ok = false;
            try {
                if ($propertyView) {
                    $ok = admin_decide_dorm_listing($pdo, (int)$pendingId, $decision, $adminName, $bulkNotes);
                } elseif ($applicationView) {
                    $applicationAction = $decision === 'approve' ? 'approve_application' : 'decline_application';
                    $result = decide_landlord_application($pdo, (string)$pendingId, $applicationAction, $bulkNotes, $adminName);
                    $ok = !empty($result['ok']);
                } else {
                    $ok = admin_set_verification_status(
                        $pdo,
                        (string)$pendingId,
                        $decision === 'approve' ? 'verified' : 'declined',
                        $decision === 'decline' ? $bulkNotes : null,
                        $role
                    );
                }
            } catch (Throwable $error) {
                error_log("UniStay bulk verification failed for {$categoryLabel} #{$pendingId}: " . $error->getMessage());
                $ok = false;
            }
            $ok ? $processed++ : $failed++;
        }

        if (count($pendingIds) === 0) {
            $successMessage = "There are no pending {$categoryLabel} records to update.";
        } else {
            $verb = $decision === 'approve' ? 'approved' : 'declined';
            $successMessage = ucfirst($verb) . " {$processed} pending {$categoryLabel}" . ($processed === 1 ? '' : 's') . '.';
            if ($failed > 0) {
                $errorMessage = $successMessage . " {$failed} record" . ($failed === 1 ? '' : 's') . ' could not be updated.';
                $successMessage = '';
            }
        }
    }
} elseif ($requestMethod === 'POST' && isset($_POST['action']) && $propertyView) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $propertyId = (int)($_POST['property_id'] ?? 0);
        $propAction = (string)$_POST['action'];
        $notes = trim((string)($_POST['rejection_notes'] ?? ''));

        $pStmt = $pdo->prepare('SELECT p.*, u.first_name, u.last_name, u.email, u.email AS personal_email FROM dorms p JOIN landlords u ON u.landlord_id = p.landlord_id WHERE p.id = ? LIMIT 1');
        $pStmt->execute([$propertyId]);
        $prop = $pStmt->fetch();

        if (!$prop) {
            $errorMessage = 'Dorm listing not found.';
        } elseif ($propAction === 'approve_property') {
            $adminName = trim((string)($adminProfile['name'] ?? 'Administrator')) ?: 'Administrator';
            $update = $pdo->prepare("UPDATE dorms SET approval_status = 'approved', approved_by = ?, feedback = ? WHERE id = ?");
            if ($update->execute([$adminName, $notes !== '' ? $notes : null, $propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" (ID #{$propertyId}) has been approved and is now active for student discovery!";
                add_in_app_notification(
                    $pdo,
                    (string)$prop['landlord_id'],
                    'approval',
                    'Dorm Listing Approved! 🏠',
                    "Great news! Your listing \"{$prop['name']}\" has been reviewed and approved by UniStay administration. It is now published and visible to students.",
                    null,
                    'landlord'
                );
            } else {
                $errorMessage = "Failed to approve dorm listing.";
            }
        } elseif ($propAction === 'decline_property') {
            $adminName = trim((string)($adminProfile['name'] ?? 'Administrator')) ?: 'Administrator';
            $update = $pdo->prepare("UPDATE dorms SET approval_status = 'declined', approved_by = ?, feedback = ? WHERE id = ?");
            if ($update->execute([$adminName, $notes !== '' ? $notes : null, $propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" (ID #{$propertyId}) has been declined.";
                $reasonMsg = $notes !== '' ? " Reason: {$notes}" : " Please ensure your proof of ownership and room photos meet the requirements before resubmitting.";
                add_in_app_notification(
                    $pdo,
                    (string)$prop['landlord_id'],
                    'system',
                    'Dorm Listing Update',
                    "Your listing \"{$prop['name']}\" could not be approved at this time.{$reasonMsg}",
                    null,
                    'landlord'
                );
            } else {
                $errorMessage = "Failed to decline dorm listing.";
            }
        } elseif ($propAction === 'reset_property') {
            $update = $pdo->prepare("UPDATE dorms SET approval_status = 'pending', approved_by = NULL, feedback = NULL WHERE id = ?");
            if ($update->execute([$propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" has been reset to pending review.";
            }
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $applicationView) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $applicationId = trim((string)($_POST['application_id'] ?? $_POST['target_user_id'] ?? ''));
        $applicationAction = (string)$_POST['action'];
        $decisionNote = trim((string)($_POST['feedback'] ?? $_POST['decision_note'] ?? $_POST['rejection_notes'] ?? ''));

        $adminName = trim((string)($adminProfile['name'] ?? '')) ?: (trim(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? '')) ?: 'Administrator');

        if ($applicationAction === 'approve_application' || $applicationAction === 'approve_landlord') {
            $res = decide_landlord_application($pdo, $applicationId, 'approve_application', $decisionNote, $adminName);
            if ($res['ok']) {
                $successMessage = "Landlord application #{$applicationId} has been approved! The landlord account has been activated and can log in immediately.";
            } else {
                $errorMessage = $res['message'] ?? "Failed to approve landlord application.";
            }
        } elseif ($applicationAction === 'decline_application' || $applicationAction === 'reject_landlord') {
            $res = decide_landlord_application($pdo, $applicationId, 'decline_application', $decisionNote, $adminName);
            if ($res['ok']) {
                $successMessage = "Landlord application #{$applicationId} has been declined.";
            } else {
                $errorMessage = $res['message'] ?? "Failed to decline landlord application.";
            }
        } elseif ($applicationAction === 'reset_application') {
            $upd = $pdo->prepare("UPDATE landlords SET status = 'pending', approved_by = NULL, feedback = NULL, decided_at = NULL WHERE landlord_id = ?");
            if ($upd->execute([$applicationId])) {
                $successMessage = "Landlord application #{$applicationId} was reset to pending review.";
            }
        }
    }
} elseif ($requestMethod === 'POST' && isset($_POST['action'])) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $targetId = trim((string)($_POST['target_user_id'] ?? ''));
        $targetRole = in_array($_POST['target_role'] ?? '', ['landlord', 'tenant'], true) ? $_POST['target_role'] : $role;
        $action = $_POST['action'];

        if ($targetId !== '') {
        if ($action === 'approve_user' || $action === 'approve_landlord' || $action === 'approve_tenant') {
            if (admin_set_verification_status($pdo, $targetId, 'verified', null, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} has been successfully verified! " . 
                    ($targetRole === 'landlord' ? 'Their listings are now active and can receive booking requests.' : 'Their verified status is now visible in UniStay.');
            } else {
                $errorMessage = "Failed to approve {$targetRole} verification.";
            }
        } elseif ($action === 'reject_user' || $action === 'reject_landlord' || $action === 'reject_tenant') {
            $notes = trim($_POST['feedback'] ?? $_POST['rejection_notes'] ?? 'The submitted identification could not be verified. Please re-upload clear credentials.');
            if (admin_set_verification_status($pdo, $targetId, 'declined', $notes, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} verification was declined. Feedback has been sent to their portal.";
            } else {
                $errorMessage = "Failed to decline {$targetRole} verification.";
            }
        } elseif ($action === 'reset_user' || $action === 'reset_landlord' || $action === 'reset_tenant') {
            if (admin_set_verification_status($pdo, $targetId, 'pending', null, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} verification was reset to pending review.";
            }
        }
    }
}
}

if ($requestMethod === 'POST') {
    $_SESSION['admin_verification_flash'] = [
        'success' => $successMessage,
        'error' => $errorMessage,
    ];
    $redirectParams = ['filter' => 'pending'];
    if ($propertyView) {
        $redirectParams['view'] = 'properties';
    } elseif ($applicationView) {
        $redirectParams['view'] = 'applications';
    } else {
        $redirectParams['role'] = $role;
    }
    header('Location: verification.php?' . http_build_query($redirectParams), true, 303);
    exit;
}

// Counters
$landlordCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'pending'")->fetchColumn(),
    'verified' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'approved'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'declined'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM landlords")->fetchColumn(),
];

$tenantCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE verification_status = 'pending' OR (verification_status = 'unverified' AND ((school_id IS NOT NULL AND school_id != '') OR (gov_id IS NOT NULL AND gov_id != '')))")->fetchColumn(),
    'verified' => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE verification_status = 'verified'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE verification_status = 'declined'")->fetchColumn(),
    'declined' => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE verification_status = 'declined'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn(),
];

$activeCounts = ($role === 'tenant') ? $tenantCounts : $landlordCounts;

$applicationCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'approved'")->fetchColumn(),
    'declined' => (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'declined'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM landlords")->fetchColumn(),
];
$applicationFilter = in_array($filter, ['all', 'pending', 'approved', 'declined'], true) ? $filter : 'all';

$applications = [];
if ($applicationView) {
    $appSql = "SELECT landlord_id AS id, landlord_id, first_name, middle_name, last_name, email, email AS personal_email, phone_number AS phone, phone_number, facebook_link, residential_address, experience_summary, gov_id AS government_id_file, gov_id, profile_photo, profile_photo AS user_profile_photo, status, feedback, approved_by, decided_at, created_at FROM landlords";
    if ($applicationFilter !== 'all') {
        $appSql .= " WHERE status = " . $pdo->quote($applicationFilter);
    }
    $appSql .= " ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, landlord_id DESC";
    $applications = $pdo->query($appSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$propertyCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM dorms WHERE approval_status = 'pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM dorms WHERE approval_status = 'approved'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM dorms WHERE approval_status = 'declined'")->fetchColumn(),
    'declined' => (int)$pdo->query("SELECT COUNT(*) FROM dorms WHERE approval_status = 'declined'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM dorms")->fetchColumn(),
];
$propertyFilter = in_array($filter, ['all', 'pending', 'approved', 'declined', 'rejected'], true) ? ($filter === 'rejected' ? 'declined' : $filter) : 'all';

$properties = [];
if ($propertyView) {
    $propSql = "
        SELECT p.*, 
               u.first_name, u.last_name, u.email, u.email AS personal_email, u.phone_number,
               (SELECT COUNT(*) FROM rooms r WHERE r.dorm_id = p.id) AS room_count,
               (SELECT COALESCE(SUM(CAST(r.occupancy AS UNSIGNED)), 0) FROM rooms r WHERE r.dorm_id = p.id) AS total_slots
        FROM dorms p
        JOIN landlords u ON u.landlord_id = p.landlord_id
        ORDER BY CASE p.approval_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, p.id DESC
    ";
    $properties = $pdo->query($propSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$bulkPendingCount = $propertyView
    ? $propertyCounts['pending']
    : ($applicationView ? $applicationCounts['pending'] : $activeCounts['pending']);
$bulkCategoryLabel = $propertyView
    ? 'dorm listings'
    : ($applicationView ? 'landlord applications' : ($role === 'tenant' ? 'student tenants' : 'landlords'));

// Fetch all records for active role to enable instant client-side tab sliding and filtering
$requests = admin_get_verification_requests($pdo, $role, 'all');

require_once __DIR__ . '/../config/verification_documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7a4b3a">
    <title>Verification Center · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style id="admin-tailwind-verification">
    /* admin-tailwind:verification:start */
*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.\!visible{visibility:visible!important}.visible{visibility:visible}.collapse{visibility:collapse}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.-right-1{right:-.25rem}.-right-1\.5{right:-.375rem}.-top-1{top:-.25rem}.-top-1\.5{top:-.375rem}.block{display:block}.inline{display:inline}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.\!grid{display:grid!important}.grid{display:grid}.hidden{display:none}.h-24{height:6rem}.h-5{height:1.25rem}.w-5{width:1.25rem}.w-full{width:100%}.flex-shrink{flex-shrink:1}.border-collapse{border-collapse:collapse}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.flex-wrap{flex-wrap:wrap}.rounded{border-radius:.25rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.border{border-width:1px}.bg-indigo-600{--tw-bg-opacity:1;background-color:rgb(79 70 229/var(--tw-bg-opacity,1))}.bg-slate-900{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}.bg-slate-900\/70{background-color:rgba(15,23,42,.7)}.object-cover{-o-object-fit:cover;object-fit:cover}.text-xs{font-size:.75rem;line-height:1rem}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.italic{font-style:italic}.leading-none{line-height:1}.text-slate-600{--tw-text-opacity:1;color:rgb(71 85 105/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.underline{text-decoration-line:underline}.outline{outline-style:solid}.ring{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(3px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.blur{--tw-blur:blur(8px)}.blur,.drop-shadow{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.drop-shadow{--tw-drop-shadow:drop-shadow(0 1px 2px rgba(0,0,0,.1)) drop-shadow(0 1px 1px rgba(0,0,0,.06))}.grayscale{--tw-grayscale:grayscale(100%)}.filter,.grayscale{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}:root{color-scheme:light;--brown:#7a4b3a;--ink:#5f3425;--cream:#fcf9f4;--text:#584b45;--line:#e8ded6;--nav-dur:0.35s}*{box-sizing:border-box}html{scrollbar-width:none}html::-webkit-scrollbar{display:none}body.unistay-scroll-locked,html.unistay-scroll-locked{overflow:hidden!important;overscroll-behavior:none}[hidden]{display:none!important}body{margin:0;padding:18px 14px 0;background:var(--cream);color:var(--ink);font:15px/1.5 DM Sans,Segoe UI,system-ui,sans-serif}a,button,input{-webkit-tap-highlight-color:transparent}button{font:inherit;cursor:pointer}a{text-decoration:none;color:inherit}:focus-visible{outline:3px solid #ac7728;outline-offset:3px}svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}.symbols{position:absolute;width:0;height:0;overflow:hidden}.skip{position:fixed;top:-100px;left:20px;z-index:50;padding:10px;background:#fff}.skip:focus{top:20px}.sidebar{position:fixed;top:18px;bottom:12px;left:14px;width:259px;padding:20px 19px;background:radial-gradient(55% 55% at 78% 8%,rgba(176,117,92,.85) 0,rgba(176,117,92,0) 100%),radial-gradient(60% 55% at 12% 100%,rgba(166,107,82,.9) 0,rgba(166,107,82,0) 100%),#7a4b3a;color:var(--cream);border-radius:21px;display:flex;flex-direction:column;height:calc(100vh - 30px)}.brand,a.brand{display:flex;align-items:center;gap:10px;margin:0 1px 21px;font:700 22px Georgia,Times New Roman,serif;text-decoration:none;color:inherit;cursor:pointer;transition:opacity .2s ease}.brand:hover,a.brand:hover{opacity:.92}.brand img{width:50px;height:50px}nav{display:grid;gap:10px}.sidebar nav a{display:grid;grid-template-columns:19px minmax(0,1fr) auto;align-items:center;justify-content:normal;gap:12px;min-height:45px;padding:11px 15px;border-radius:11px;color:var(--cream);text-align:left;font-size:14px;font-weight:600;letter-spacing:.2px}.sidebar nav a svg{width:19px;height:19px}.sidebar nav a:hover{background:#ffffff12}.sidebar nav,.sidebar nav a{position:relative}.sidebar nav a{z-index:1;background:transparent;transition:color .24s ease,background .15s ease}.sidebar nav a:before{content:"";position:absolute;inset:0;border-radius:inherit;background:var(--cream);box-shadow:inset 5px 0 #e4cbbf;z-index:-1;opacity:0;transform:scale(.97);pointer-events:none;transition:opacity .24s cubic-bezier(.16,1,.3,1),transform .24s cubic-bezier(.16,1,.3,1);will-change:opacity,transform}.sidebar nav a[aria-current=page]{background:transparent!important;color:var(--brown)!important}.sidebar nav a[aria-current=page]:before{opacity:1;transform:scale(1)}.sidebar nav a.nav-fading-out{color:var(--cream)!important;transition:color .3s ease!important}.sidebar nav a.nav-fading-out:before{opacity:0!important;transform:scale(.96)!important;transition:opacity .32s ease,transform .32s ease!important}.sidebar nav a.nav-fading-out .nav-badge{background:var(--cream)!important;color:var(--brown)!important;transition:background .3s ease,color .3s ease!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]{color:var(--cream)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]:before{opacity:0!important;transform:scale(.96)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page] .nav-badge{background:var(--cream)!important;color:var(--brown)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running{color:var(--brown)!important;transition:color .3s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running:before{opacity:1!important;transform:scale(1)!important;transition:opacity .32s cubic-bezier(.16,1,.3,1),transform .32s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running .nav-badge{background:var(--brown)!important;color:var(--cream)!important;transition:background .3s cubic-bezier(.16,1,.3,1),color .3s cubic-bezier(.16,1,.3,1)!important}.nav-badge{display:grid;place-items:center;min-width:24px;height:20px;padding:0 8px;border-radius:999px;background:var(--cream);color:var(--brown);font-size:12px;font-weight:700;line-height:1;transition:background .22s ease,color .22s ease}.sidebar nav a[aria-current=page] .nav-badge{background:var(--brown);color:var(--cream)}.shell{margin-left:277px;min-height:calc(100vh - 30px);height:auto;padding:0 54px 30px;background:linear-gradient(180deg,hsla(36,71%,97%,.98),hsla(36,56%,95%,.98));border-radius:22px;box-shadow:0 30px 60px rgba(92,62,48,.12);flex-direction:column}.shell,.topbar{position:relative;display:flex}.topbar{z-index:1000;height:80px;align-items:center;justify-content:space-between;border-bottom:1px solid #c9a797;gap:20px}h1{margin:0;font:700 34px/1.2 DM Sans,Segoe UI,system-ui,sans-serif;letter-spacing:-1.2px}.dorms-page h1,.map-page h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-weight:700;letter-spacing:-.6px}.profile{display:flex;align-items:center;gap:26px;padding-right:3px;position:relative;z-index:1001}.btn-topbar-guide{position:relative;display:grid;place-items:center;width:28px;height:34px;padding:0;border:0;border-radius:50%;color:var(--brown);cursor:pointer;transition:color .18s ease,transform .18s ease;margin-right:0;text-decoration:none}.btn-topbar-guide,.btn-topbar-guide:hover{background:transparent!important;box-shadow:none!important}.btn-topbar-guide:hover{border-color:transparent!important;transform:scale(1.08);color:#5c3528}.btn-topbar-guide svg{width:27px;height:27px;stroke-width:2.1;color:var(--brown);transition:transform .18s ease}.btn-topbar-guide svg circle{transition:fill .18s ease,stroke .18s ease}.btn-topbar-guide svg line,.btn-topbar-guide svg path{transition:stroke .18s ease}.btn-topbar-guide:active svg circle,.btn-topbar-guide:hover svg circle,.btn-topbar-guide[aria-expanded=true] svg circle{fill:var(--brown);stroke:var(--brown)}.btn-topbar-guide:active svg line,.btn-topbar-guide:active svg path,.btn-topbar-guide:hover svg line,.btn-topbar-guide:hover svg path,.btn-topbar-guide[aria-expanded=true] svg line,.btn-topbar-guide[aria-expanded=true] svg path{stroke:#fff}.btn-topbar-guide:active{color:#4a2a1e;transform:scale(.92)}.btn-topbar-guide:active svg circle{fill:#4a2a1e;stroke:#4a2a1e}.btn-topbar-guide span{display:none!important}.topbar-auth{display:flex;align-items:center;gap:12px}.topbar-auth .btn-topbar-login{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--brown);background:var(--brown);color:#fff;cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-login:hover{color:#fff;background:#5c3527;border-color:#5c3527;transform:scale(1.1)}.topbar-auth .btn-topbar-login:active{color:#fff;background:#452618;border-color:#452618}.topbar-auth .btn-topbar-signup{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid #e8ddd3;background:#fdfaf5;color:var(--brown);cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-signup:hover{filter:brightness(.95);transform:scale(1.1)}.bell{position:relative;display:grid;place-items:center;width:28px;height:34px;border:0;border-radius:50%;padding:0;color:var(--brown);background:transparent;cursor:pointer;transition:color .18s ease,background-color .18s ease,box-shadow .18s ease,transform .18s ease}.bell svg{width:27px;height:27px;stroke-width:2.1;transition:fill .18s ease,transform .18s ease}.notification-badge{position:absolute;top:-4px;right:-8px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#a33d2d;color:#fff;font:600 9px/1 Segoe UI,system-ui,sans-serif}.notification-badge[hidden]{display:none}.bell:hover svg{fill:currentColor}.bell:active{color:#4a2a1e;transform:scale(.92)}.bell[aria-expanded=true] svg{fill:currentColor}.avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;border:0;padding:0;background:#f0e3d4 50%/cover no-repeat;color:var(--brown);font-weight:700;font-size:14px;cursor:pointer;overflow:hidden;position:relative;transition:box-shadow .18s ease,transform .18s ease}.avatar img,.avatar-img{width:100%;height:100%;border-radius:50%;-o-object-fit:cover;object-fit:cover;display:block}.avatar:hover{box-shadow:0 0 0 3px var(--line)}.avatar[aria-expanded=true]{box-shadow:0 0 0 3px #e4cbbf}.avatar:active{transform:scale(.94)}main{padding-top:38px}.summary{display:grid;width:min(100%,600px);margin-inline:auto;grid-template-columns:repeat(4,minmax(0,1fr));gap:30px}.card{position:relative;min-height:142px;padding:26px 20px 20px;border-radius:21px;background:var(--cream);box-shadow:6px 7px 9px #00000006}.card>svg{position:absolute;top:23px;right:20px;width:18px;height:18px}.card:nth-child(n+3)>svg{color:#75652e}.label{color:var(--text);font-size:12px;font-weight:500;padding-right:24px;text-transform:uppercase}.value{font-size:20px;font-weight:600;margin:5px 0 7px;line-height:1.4}.value small{font-weight:400}.detail,.value small{font-size:14px;color:var(--text)}.dashboard-grid{display:grid;width:min(100%,600px);grid-template-columns:1.55fr 1fr;gap:44px;margin:25px auto 0}h2{margin:0 0 4px 4px;font-size:26px;font-weight:650;letter-spacing:-.8px;line-height:1.45}.placeholder{height:333px;background:#d9d9d9;border-radius:16px}a.placeholder{display:block}.dormitories{margin-top:22px}.popover{position:absolute;z-index:1;top:calc(100% + 14px);right:0;width:340px;max-width:calc(100vw - 72px);border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08);overflow:hidden}html body .topbar:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}html body .profile:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}.topbar.is-popover-open,.topbar:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.profile.is-popover-open,.profile:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.popover:not([hidden]):not(.is-closing){z-index:1000000!important}.popover h2{margin:0;padding:12px 16px;background:var(--cream);border-bottom:1px solid var(--line);font-size:13px;letter-spacing:0}.empty{text-align:center;padding:26px 16px 24px}.empty-icon{display:grid;place-items:center;margin:0 auto 12px;width:38px;height:38px;border-radius:50%;color:#93867e;background:var(--cream)}.empty strong{display:block;font-size:13px;font-weight:500}.empty p{margin:4px 0 0;color:var(--text);font-size:11px}.notification-list{list-style:none;margin:0;padding:0;max-height:min(340px,45vh);overflow:auto}.notification-row{display:flex;align-items:stretch;border-top:1px solid var(--line);background:#fff}.notification-entry{display:grid;grid-template-columns:28px minmax(0,1fr);gap:4px 10px;flex:1;min-width:0;text-align:left;padding:14px 16px;background:#fff;color:var(--ink);border:0}.notification-entry.is-unread{background:#fcf7ef}.notification-entry:hover{background:var(--cream)}.notification-entry .entry-icon{grid-column:1;grid-row:1/4;display:grid;place-items:center;width:28px;height:28px;border-radius:9px;background:#f0e3d4;color:var(--brown);font-style:normal}.notification-entry strong{grid-column:2;display:block;font-size:13px}.notification-entry span{grid-column:2;display:block;font-size:12px;color:var(--text);margin-top:0}.notification-entry small{grid-column:2;display:block;font-size:10px;color:var(--brown);margin-top:3px}.notification-delete{margin:0 10px 0 0;padding:6px 8px}.notification-delete,.notification-mark-read{align-self:center;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--brown);font-size:11px}.notification-mark-read{margin:0 12px 0 4px;padding:5px 10px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .18s ease;flex-shrink:0}.notification-mark-read:hover{background:#f0e3d4;border-color:#d1bba4;color:#4a2511;transform:translateY(-1px)}.notification-delete:hover{background:#f3e8db}.notification-actions{display:flex;justify-content:flex-end;gap:8px;padding:8px 12px;border-top:1px solid var(--line);background:var(--cream)}.notification-actions button{border:0;padding:5px 7px;background:transparent;color:var(--brown);font-size:11px}.notification-actions button:hover{text-decoration:underline}.view-all{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;border:0;border-top:1px solid var(--line);padding:10px 16px;background:var(--cream);color:var(--text);font-size:12px}.view-all svg{width:15px;height:15px}.view-all:hover{color:var(--brown);text-decoration:none}::-webkit-scrollbar-track{background:transparent!important}*{scrollbar-color:#7a4b3a transparent}.profile-popover{width:260px}.profile-summary{display:flex;align-items:center;gap:12px;padding:16px}.profile-photo{position:relative;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#f0e3d4;color:var(--brown);font-weight:700;font-size:15px}.profile-photo img{position:absolute;inset:0;width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-identity{display:grid;gap:2px;min-width:0}.profile-identity strong{font-size:14px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-role{font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--text)}.profile-role-row{display:flex;align-items:center;gap:6px;min-width:0;line-height:1.2}.profile-role-sep{color:#a89487;font-size:8px;line-height:1}.profile-view-link{font-size:12px;font-weight:700;color:var(--brown,#7a4b3a);text-decoration:none!important;white-space:nowrap;transition:color .15s ease,background .15s ease;padding:2px 6px;border-radius:6px}.profile-view-link:hover{text-decoration:none!important;color:#3b1e10;background:rgba(122,75,58,.08)}.profile-view-link:active{text-decoration:none!important;color:#261109;background:rgba(122,75,58,.16)}.profile-popover .view-all{text-decoration:none!important;transition:background .15s ease,color .15s ease}.profile-popover .view-all:hover{text-decoration:none!important;background:#f5ece2;color:#3b1e10}.profile-popover .view-all:active{text-decoration:none!important;background:#ebdec9;color:#261109}.profile-logout{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;text-decoration:none!important}.profile-logout:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-logout:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@media (prefers-reduced-motion:no-preference){.popover{animation:popover-in .18s ease}.popover.is-closing{animation:popover-out .16s ease forwards}.bell[aria-expanded=true] svg{animation:bell-pop .45s ease}}@keyframes popover-in{0%{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}@keyframes popover-out{0%{opacity:1;transform:none}to{opacity:0;transform:translateY(-6px) scale(.98)}}@keyframes bell-pop{0%{transform:scale(1)}35%{transform:scale(1.18) rotate(-8deg)}70%{transform:scale(.94) rotate(4deg)}to{transform:scale(1)}}.notification-toast{position:fixed;z-index:100000;right:24px;bottom:24px;display:flex;flex-direction:column;justify-content:center;gap:8px;width:min(400px,calc(100vw - 32px));min-height:108px;box-sizing:border-box;padding:16px 20px;border:none;border-radius:16px;background:#ecdccb;color:#3b2820;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);opacity:0;transform:translateY(24px) scale(.96);pointer-events:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1)}.notification-toast.is-visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}.notification-toast.is-hiding{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.notification-toast .toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%}.notification-toast strong{font-size:15px;font-weight:700;color:#3b2820;line-height:1.35}.notification-toast span{font-size:13.5px;font-weight:400;color:#5a4236;line-height:1.45;display:block}.notification-toast .toast-close-btn{background:transparent;border:none;color:#7a4b3a;font-size:24px;line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;transition:color .15s,transform .15s}.notification-toast .toast-close-btn:hover{color:#3b2820;transform:scale(1.15)}.notification-toast .toast-actions{display:flex;align-items:center;gap:10px;margin-top:4px}@media (prefers-reduced-motion:reduce){.notification-toast,.notification-toast.is-hiding{transition:none}}dialog{border:1px solid #ebd9cb;border-radius:24px;padding:0;width:540px;max-width:calc(100vw - 32px);max-height:85vh;color:#3b2820;background:#fffdf9;box-shadow:0 28px 70px rgba(55,31,22,.28);font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden}dialog::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}.dialog-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px 18px;border-bottom:1px solid #ebd9cb;background:#fffdf9}.dialog-header h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:22px;color:#3b2820}.close{border:none;border-radius:0;width:auto;height:auto;padding:0;display:inline-flex;align-items:center;justify-content:center;color:#7a4b3a;background:none;cursor:pointer;box-shadow:none;font-size:26px;line-height:1;transition:color .15s ease,transform .15s ease}.close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.close:active{background:none;color:#1a0f0a;transform:scale(.92)}.close svg{width:18px;height:18px;fill:currentColor;stroke:currentColor}#feedback{padding:0 20px;font-size:12px}#feedback:empty{display:none}@media (min-width:901px){.dashboard-page .topbar{height:62px}.dashboard-page h1{font-size:28px;letter-spacing:-.7px}.dashboard-page .profile{gap:19px}.dashboard-page .bell,.dashboard-page .btn-theme-toggle,.dashboard-page .btn-topbar-guide{width:24px;height:30px}.dashboard-page .bell svg,.dashboard-page .btn-theme-toggle svg,.dashboard-page .btn-topbar-guide svg{width:22px;height:22px}.dashboard-page .avatar{width:32px;height:32px}.dashboard-page main{padding-top:30px}.dashboard-page .summary{width:min(100%,470px);grid-template-columns:repeat(4,minmax(0,1fr));gap:22px;align-items:stretch}.dashboard-page .card{min-height:167px;padding:19px 15px 14px;border-radius:19px}.dashboard-page .card>svg{top:19px;right:14px;width:15px;height:15px}.dashboard-page .label{padding-right:18px;font-size:10px;line-height:1.4}.dashboard-page .value{margin:3px 0 5px;font-size:16px;line-height:1.35}.dashboard-page .value small{font-size:11px}.dashboard-page .detail{font-size:11px;line-height:1.5}.dashboard-page .dashboard-grid{width:min(100%,470px);grid-template-columns:1.55fr 1fr;gap:34px;margin-top:21px}.dashboard-page h2{margin:0 0 4px 3px;font-size:20px;letter-spacing:-.4px;line-height:1.3}.dashboard-page .placeholder{height:260px;border-radius:12px}.dashboard-page .dormitories{margin-top:25px}}@media (max-width:1150px){.sidebar{width:220px}.shell{margin-left:238px;padding:0 28px 28px}.dashboard-grid{gap:24px}}@media (min-width:761px) and (max-width:900px){body{padding-right:6px;padding-left:4px}.sidebar{left:1.3vw;width:16.7vw;padding:12px 10px;border-radius:13px}.brand{gap:6px;margin:0 1px 10px;font-size:14px}.brand img{width:30px;height:30px}.sidebar nav{gap:4px}.sidebar nav a{grid-template-columns:14px minmax(0,1fr) auto;gap:6px;min-height:30px;padding:6px 8px;border-radius:7px;font-size:9px;letter-spacing:0}.sidebar nav a svg{width:14px;height:14px}.shell{margin-top:-10px;margin-left:18.8vw;padding:0 34px 4px 3.3vw;border-radius:12px 12px 0 0}.topbar{height:53px;gap:12px}h1{font-size:20px;letter-spacing:-.5px}.profile{gap:12px}.bell,.btn-theme-toggle,.btn-topbar-guide{width:20px;height:24px}.bell svg,.btn-theme-toggle svg,.btn-topbar-guide svg{width:18px;height:18px}.bell:after{width:7px;height:7px}.avatar{width:24px;height:24px}main{padding-top:19px}.summary{width:100%;gap:16px}.card{min-height:78px;padding:12px 11px 10px;border-radius:13px}.card>svg{top:12px;right:11px;width:13px;height:13px}.label{padding-right:16px;font-size:8px;line-height:1.35}.value{margin:2px 0 4px;font-size:14px;line-height:1.3}.value small{font-size:10px}.detail{font-size:8px;line-height:1.35}.dashboard-grid{width:100%;grid-template-columns:1.55fr 1fr;gap:24px;margin-top:15px}h2{margin:0 0 0 2px;font-size:16px;letter-spacing:-.3px;line-height:1.05}.placeholder{height:min(44.5vh,178px);border-radius:10px}.dormitories{margin-top:12px}}input[type=search]::-webkit-search-cancel-button{cursor:pointer}input[type=search]::-webkit-search-cancel-button:active,input[type=search]::-webkit-search-cancel-button:hover{cursor:pointer}@media (max-width:760px){body{padding:12px}.sidebar{position:static;width:auto;padding:14px;margin-bottom:12px;border-radius:17px}.brand{margin-bottom:14px}.brand img{width:40px;height:40px}nav{display:flex;gap:4px;flex-wrap:wrap}nav a{padding:8px 10px;min-height:38px;font-size:12px;gap:7px}.shell{margin:0;padding:0 20px 24px;border-radius:17px}h1{font-size:29px}.topbar{height:76px}.profile{gap:16px}.avatar{width:32px;height:32px}main{padding-top:24px}.dashboard-grid{grid-template-columns:1fr}.placeholder{height:260px}h2{font-size:24px}}@media (max-width:420px){.shell{padding:0 16px 24px}.summary{gap:12px}.card{padding:20px 14px;min-height:152px}.card>svg{position:static;margin-bottom:8px}.label{padding:0;font-size:10px}.value{font-size:17px}.detail{font-size:12px}.profile{gap:12px}.avatar{width:28px;height:28px}}.brand{overflow:hidden;padding-right:38px}.brand img{flex-shrink:0}.brand-name,.nav-label,.toggle-label{white-space:nowrap;overflow:hidden}.sidebar-toggle{display:none}@media (min-width:761px){.sidebar-toggle{display:grid;place-items:center;position:absolute;top:50%;right:-13px;transform:translateY(-50%);z-index:50;align-items:center;width:26px;height:26px;margin:0;padding:0;border:2px solid #fdf9f3;border-radius:50%;background:#633c2e;color:var(--cream);box-shadow:0 2px 8px rgba(0,0,0,.22);font-size:13px;font-weight:500;cursor:pointer;transition:background .2s ease,transform .3s ease}.sidebar-toggle:hover{background:#7a4b3a;color:var(--cream)}.toggle-circle{display:grid;place-items:center;flex:0 0 20px;width:20px;height:20px;border-radius:50%;background:transparent}.toggle-label{display:none}.toggle-circle svg{width:12px;height:12px;stroke-width:2.2;transition:transform .3s ease}.brand-name,.nav-badge,.nav-label,.toggle-label{transition:opacity .2s ease}html.sidebar-ready .sidebar{transition:width .3s ease}html.sidebar-ready .shell{transition:margin-left .3s ease}html.sidebar-collapsed .sidebar{width:88px}html.sidebar-collapsed .shell{margin-left:106px}html.sidebar-collapsed .brand-name,html.sidebar-collapsed .nav-badge,html.sidebar-collapsed .nav-label,html.sidebar-collapsed .toggle-label{opacity:0;pointer-events:none}html.sidebar-collapsed .brand{overflow:visible;padding-right:0}html.sidebar-collapsed .brand-name{display:none}html.sidebar-collapsed .brand img{width:42px;height:42px}html.sidebar-collapsed .sidebar-toggle{top:50%;right:-13px;transform:translateY(-50%)}html.sidebar-collapsed .toggle-circle svg{transform:rotate(180deg)}}@media (min-width:761px) and (max-width:900px){.sidebar-toggle{gap:0;padding:0;width:24px;height:24px}.sidebar-toggle,html.sidebar-collapsed .sidebar-toggle{top:50%;right:-12px;transform:translateY(-50%)}.toggle-circle{flex-basis:16px;width:16px;height:16px}.toggle-circle svg{width:10px;height:10px}html.sidebar-collapsed .sidebar{width:52px}html.sidebar-collapsed .admin-shell,html.sidebar-collapsed .shell{margin-left:calc(2.1vw + 52px)}}@media (min-width:901px){.admin-shell{margin-left:277px!important;transition:margin-left .24s cubic-bezier(.2,.8,.2,1)}html.sidebar-collapsed .admin-shell{margin-left:96px!important}}.logout-modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(30,15,10,.58);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}@keyframes logoutModalFadeOut{0%{opacity:1;backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}to{opacity:0;backdrop-filter:blur(0);-webkit-backdrop-filter:blur(0)}}@keyframes logoutCardPopOut{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(16px) scale(.92)}}.logout-modal-backdrop.is-active:not(.is-closing){opacity:1;pointer-events:auto}.logout-modal-backdrop.is-closing{pointer-events:none;animation:logoutModalFadeOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-card{background:#fff;border-radius:20px;max-width:420px;width:100%;padding:28px 28px 24px;box-shadow:0 20px 50px rgba(45,25,18,.28);border:1px solid #ebd9cb;transform:translateY(18px) scale(.94);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease;text-align:center}.logout-modal-backdrop.is-active:not(.is-closing) .logout-modal-card{transform:translateY(0) scale(1);opacity:1}.logout-modal-backdrop.is-closing .logout-modal-card{animation:logoutCardPopOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-icon{margin:0 auto 16px;border:none;display:flex;align-items:center;justify-content:center;box-shadow:none}.logout-modal-icon,.logout-modal-logo-img{width:68px;height:68px;background:transparent;border-radius:50%}.logout-modal-logo-img{-o-object-fit:cover;object-fit:cover;display:block}.logout-modal-title{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:700;font-size:1.3rem;color:#292524;margin:0 0 8px}.logout-modal-desc{font-size:.92rem;color:#78665c;line-height:1.5;margin:0 0 24px}.logout-modal-actions{display:flex;gap:12px;justify-content:center}.btn-logout-cancel{flex:1;padding:12px 18px;border-radius:10px;background:#fbf8f5;border:1px solid #ebd9cb;color:#57534e;font-weight:600;font-size:.92rem;cursor:pointer;transition:background .18s ease,border-color .18s ease,color .18s ease;box-shadow:0 1px 3px rgba(0,0,0,.05)}.btn-logout-cancel:hover{background:#f5eeea;color:#292524}.btn-logout-cancel:active{background:#ede2db}.btn-logout-confirm{flex:1;padding:12px 18px;border-radius:10px;background:#dc2626;border:none;color:#fff!important;font-weight:600;font-size:.92rem;cursor:pointer;text-decoration:none!important;display:inline-flex;align-items:center;justify-content:center;transition:transform .18s cubic-bezier(.34,1.56,.64,1),background .18s ease,box-shadow .18s ease;box-shadow:0 2px 6px rgba(220,38,38,.25)}.btn-logout-confirm:hover{background:#b91c1c;transform:scale(1.05);box-shadow:0 6px 18px rgba(220,38,38,.35)}.btn-logout-confirm:active{background:#991b1b;transform:scale(1.09);box-shadow:0 8px 22px rgba(220,38,38,.45)}.admin-logout-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 16px;border-radius:999px;font-size:.84rem;font-weight:600;color:#7a4b3a!important;background:#fff;border:1px solid #dfc3b2;text-decoration:none!important;transition:all .18s;cursor:pointer;box-shadow:0 1px 3px rgba(60,30,20,.05)}.admin-logout-btn:hover{background:#7a4b3a;color:#fff!important;border-color:#7a4b3a;transform:translateY(-1px)}.sidebar nav a.nav-logout{margin-top:14px;border-top:1px solid hsla(0,0%,100%,.14);padding-top:12px;color:#f7dcd0}.sidebar nav a.nav-logout:hover{background:rgba(220,38,38,.22);color:#fff}.sidebar nav a.nav-logout svg{stroke:currentColor}html:not([data-theme=dark]) #notification-popover{background:#f3e8db!important;border:1px solid #e8ded6!important;color:#3b2820!important;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08)!important;z-index:10000}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover .empty strong,html:not([data-theme=dark]) #notification-popover .notification-detail h3,html:not([data-theme=dark]) #notification-popover .notification-entry strong,html:not([data-theme=dark]) #notification-popover h2,html:not([data-theme=dark]) #notification-popover strong{color:#3b2820!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty p,html:not([data-theme=dark]) #notification-popover .notification-detail p,html:not([data-theme=dark]) #notification-popover .notification-entry span,html:not([data-theme=dark]) #notification-popover p,html:not([data-theme=dark]) #notification-popover span{color:#78665c!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover #view-all,html:not([data-theme=dark]) #notification-popover .notification-entry small,html:not([data-theme=dark]) #notification-popover .view-all,html:not([data-theme=dark]) #notification-popover small{color:#7a4b3a!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty,html:not([data-theme=dark]) #notification-popover .notification-actions,html:not([data-theme=dark]) #notification-popover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-list,html:not([data-theme=dark]) #notification-popover .notification-row{background:#f3e8db!important;border-color:#e8ddd3!important}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover h2{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-entry.is-unread{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .empty-icon,html:not([data-theme=dark]) #notification-popover .notification-entry .entry-icon{background:#f0e3d4!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-actions button,html:not([data-theme=dark]) #notification-popover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-mark-read{background:transparent!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-entry:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html:not([data-theme=dark]) #notification-popover .view-all{background:#f3e8db!important;border-top:1px solid #e8ddd3!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .view-all:hover{background:#ead7c3!important;color:#5c3527!important}#notification-popover a:focus-visible,#notification-popover button:focus-visible{outline:2px solid #7a4b3a;outline-offset:-3px}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3}#notification-popover .notification-row .notification-mark-read{transition:transform .18s ease,color .18s ease;border-color:transparent}html:not([data-theme=dark]) #notification-popover .notification-row:active,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:hover{background:#ead7c3}html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3;border-color:transparent}#notification-popover .notification-detail{padding:16px;background:#f3e8db}#notification-popover .notification-detail[hidden],#notification-popover [hidden]{display:none}.notification-detail button{background:transparent;color:#7a4b3a;border:1px solid #d8c3b4;border-radius:8px;padding:7px 10px;cursor:pointer}.notification-detail h3{font-size:.95rem;color:#3b2820;margin:16px 0 10px}.notification-detail p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5;font-size:.85rem}#notification-popover .notification-list{overscroll-behavior:contain}#notification-popover .notification-detail,#notification-popover .notification-list{max-height:min(340px,45vh);overflow-y:auto}dialog.approval-contact-dialog{width:min(calc(100vw - 32px),480px);padding:0;border:0;border-radius:20px;background:#fffaf4;color:#3b2820;box-shadow:0 24px 60px rgba(55,31,22,.28)}dialog.approval-contact-dialog::backdrop{background:rgba(42,28,22,.55);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}.approval-contact-card{padding:26px}.approval-contact-icon{display:grid;place-items:center;width:52px;height:52px;margin-bottom:16px;border-radius:50%;background:#e5f4e8;color:#28733a;font-size:24px;font-weight:700}.approval-contact-card h2{margin:0 0 10px;font-family:Fraunces,Georgia,serif;font-size:1.55rem;color:#5f3425}.approval-contact-card p{margin:0;color:#6f5a4e;font-size:.92rem;line-height:1.6}.approval-contact-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.approval-contact-actions a,.approval-contact-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 16px;border-radius:9px;font:600 .85rem DM Sans,sans-serif;cursor:pointer;text-decoration:none;transition:transform .18s ease,background .18s ease,border-color .18s ease}.approval-contact-later{border:1px solid #d8c3b4;background:#fff;color:#6a4333}.approval-contact-view{border:1px solid #7a4b3a;background:#7a4b3a;color:#fff}.approval-contact-actions a:hover,.approval-contact-actions button:hover{transform:scale(1.04)}.approval-contact-view:hover{background:#5f3425;border-color:#5f3425;color:#fff}.approval-contact-actions a:active,.approval-contact-actions button:active{transform:scale(1.08)}.approval-contact-actions a:focus-visible,.approval-contact-actions button:focus-visible{outline:2px solid #7a4b3a;outline-offset:3px}@media (max-width:520px){.approval-contact-actions{flex-direction:column-reverse}.approval-contact-actions a,.approval-contact-actions button{width:100%}}.avatar span,.profile-photo .profile-initials{display:flex!important;align-items:center!important;justify-content:center!important;text-align:center!important;line-height:1!important;width:100%!important;height:100%!important}.profile-popover{width:290px;max-height:85vh;overflow-y:auto}.profile-switch-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted,#8c7569);padding:12px 16px 4px;border-top:1px solid var(--line,#ebd9cb)}.profile-accounts-list{display:flex;flex-direction:column;max-height:240px;overflow-y:auto;scrollbar-width:thin}.profile-account-item{display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer;text-decoration:none;color:inherit;transition:background .15s ease}.profile-account-item:hover{background:#fbf5ee}.profile-account-item.is-switching{opacity:.55;pointer-events:none}.profile-account-item .account-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-align:center;line-height:1;background:#f0e3d4;color:var(--brown,#7a4b3a);font-weight:700;font-size:13px;flex-shrink:0;overflow:hidden}.profile-account-item .account-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-account-item .account-details{min-width:0;flex:1}.profile-account-item .account-name{font-size:13px;font-weight:700;color:var(--head,#5f3425);display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-account-item .account-email{font-size:11px;color:var(--text-muted,#8c7569);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.account-role-badge{font-size:9px;padding:1px 6px;border-radius:999px;font-weight:600;background:#f3ece4;color:#6b3624;text-transform:capitalize}.account-role-badge.role-admin{background:#fef3c7;color:#92400e}.account-role-badge.role-landlord{background:#f0e3d4;color:#7a4b3a}.account-role-badge.role-tenant{background:#dcfce7;color:#166534}.profile-add-account{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;border-top:1px solid var(--line,#ebd9cb);text-decoration:none!important}.profile-add-account:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-add-account:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@keyframes unistayShimmer{0%{background-position:-200% 0}to{background-position:200% 0}}.skeleton-shimmer{background:linear-gradient(90deg,#ece5df 25%,#f7f2ed 37%,#ece5df 63%)!important;background-size:400% 100%!important;animation:unistayShimmer 1.5s ease-in-out infinite!important}.skeleton-card{pointer-events:none!important;cursor:default!important;transform:none!important}.skeleton-card:hover{transform:none!important;box-shadow:0 6px 20px rgba(60,30,20,.06)!important;border-color:#ebd9cb!important}.skeleton-card-thumb{width:100%;aspect-ratio:16/10;border-radius:0}.skeleton-card-body{padding:20px 20px 18px;display:flex;flex-direction:column;gap:10px}.skeleton-line{height:14px;border-radius:6px}.skeleton-line-title{width:72%;height:20px}.skeleton-line-sub{width:48%;height:13px}.skeleton-line-price{width:38%;height:16px;margin-top:4px}.skeleton-card-btn{height:40px;width:100%;border-radius:10px;margin-top:10px}.profile-account-item{border:none;background:transparent;width:100%;text-align:left;font-family:inherit;font-size:inherit;box-sizing:border-box}.profile-switch-empty{padding:8px 16px;font-size:11px;color:var(--text-muted,#8c7569);font-style:italic}.admin-user-badge{position:relative!important;cursor:pointer!important;-webkit-user-select:none!important;-moz-user-select:none!important;user-select:none!important}#notification-page,#notification-popover .notification-detail,#notification-popover .notification-list,.full-inbox,.notification-list{scrollbar-width:thin;scrollbar-color:var(--brown,#7a4b3a) transparent}#notification-page::-webkit-scrollbar,#notification-popover .notification-detail::-webkit-scrollbar,#notification-popover .notification-list::-webkit-scrollbar,.full-inbox::-webkit-scrollbar,.notification-list::-webkit-scrollbar{width:8px;height:8px}#notification-page::-webkit-scrollbar-track,#notification-popover .notification-detail::-webkit-scrollbar-track,#notification-popover .notification-list::-webkit-scrollbar-track,.full-inbox::-webkit-scrollbar-track,.notification-list::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}#notification-page::-webkit-scrollbar-thumb,#notification-popover .notification-detail::-webkit-scrollbar-thumb,#notification-popover .notification-list::-webkit-scrollbar-thumb,.full-inbox::-webkit-scrollbar-thumb,.notification-list::-webkit-scrollbar-thumb{background:var(--brown,#7a4b3a);border:2px solid #f3e9df;border-radius:999px}#notification-page::-webkit-scrollbar-thumb:hover,#notification-popover .notification-detail::-webkit-scrollbar-thumb:hover,#notification-popover .notification-list::-webkit-scrollbar-thumb:hover,.full-inbox::-webkit-scrollbar-thumb:hover,.notification-list::-webkit-scrollbar-thumb:hover{background:#5f3425}.btn-action-reject,.btn-danger-outline,.btn-decline,.btn-delete,.btn-reject,.btn-settings-danger,.dorm-card-actions .dorm-delete-btn,.dorm-delete-btn,.rv-btn-danger{background:#fff!important;border:1px solid #fca5a5!important;color:#dc2626!important;box-shadow:0 2px 6px rgba(220,38,38,.08)!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important;font-size:13px!important;padding:9px 18px!important;border-radius:10px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;transform:scale(1)!important;transform-origin:center!important;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease!important;text-decoration:none!important}.btn-action-reject:hover:not(:disabled):not(.is-loading),.btn-danger-outline:hover:not(:disabled):not(.is-loading),.btn-decline:hover:not(:disabled):not(.is-loading),.btn-delete:hover:not(:disabled):not(.is-loading),.btn-reject:hover:not(:disabled):not(.is-loading),.btn-settings-danger:hover:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:hover:not(:disabled):not(.is-loading),.dorm-delete-btn:hover:not(:disabled):not(.is-loading),.rv-btn-danger:hover:not(:disabled):not(.is-loading){background:#fee2e2!important;border-color:#f87171!important;color:#b91c1c!important;transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(220,38,38,.2)!important}.btn-action-reject:active:not(:disabled):not(.is-loading),.btn-danger-outline:active:not(:disabled):not(.is-loading),.btn-decline:active:not(:disabled):not(.is-loading),.btn-delete:active:not(:disabled):not(.is-loading),.btn-reject:active:not(:disabled):not(.is-loading),.btn-settings-danger:active:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:active:not(:disabled):not(.is-loading),.dorm-delete-btn:active:not(:disabled):not(.is-loading),.rv-btn-danger:active:not(:disabled):not(.is-loading){background:#fecaca!important;transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(220,38,38,.26)!important}.nav-badge-pill,.sidebar .nav-badge-pill{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font-family:DM Sans,sans-serif;font-size:.72rem;font-weight:700;line-height:1;margin-left:auto;box-shadow:0 1px 3px rgba(220,38,38,.35);pointer-events:none;transition:transform .2s cubic-bezier(.2,0,0,1),opacity .2s ease}.sidebar.collapsed .nav-badge-pill{display:none}@keyframes bellShake{0%{transform:rotate(0)}15%{transform:rotate(14deg)}30%{transform:rotate(-14deg)}45%{transform:rotate(10deg)}60%{transform:rotate(-10deg)}75%{transform:rotate(6deg)}to{transform:rotate(0)}}#notification-bell.bell-ringing,.bell.bell-ringing{animation:bellShake .8s ease-in-out!important}.unistay-lightbox-overlay,dialog.unistay-lightbox-modal{position:fixed;inset:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;margin:0;padding:24px;border:none;background:transparent;display:flex;align-items:center;justify-content:center;z-index:1000001;overflow:hidden;box-sizing:border-box}dialog.unistay-lightbox-modal:not([open]){display:none!important}dialog.unistay-lightbox-modal::backdrop{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);animation:backdrop-fade-in .26s ease both}dialog.unistay-lightbox-modal.is-closing::backdrop{animation:backdrop-fade-out .22s ease both}.unistay-lightbox-overlay{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}.unistay-lightbox-overlay.is-open:not(.is-closing){opacity:1;pointer-events:auto}.unistay-lightbox-overlay.is-closing{opacity:0;pointer-events:none;transition:opacity .22s ease,backdrop-filter .22s ease,-webkit-backdrop-filter .22s ease}.unistay-lightbox-content{position:relative;max-width:90vw;max-height:88vh;display:flex;flex-direction:column;align-items:center;transform:scale(.92) translateY(12px);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease}.unistay-lightbox-overlay.is-open:not(.is-closing) .unistay-lightbox-content,dialog.unistay-lightbox-modal[open]:not(.is-closing) .unistay-lightbox-content{transform:scale(1) translateY(0);opacity:1}.unistay-lightbox-overlay.is-closing .unistay-lightbox-content,dialog.unistay-lightbox-modal.is-closing .unistay-lightbox-content{transform:scale(.94) translateY(10px);opacity:0;transition:transform .22s ease,opacity .2s ease}.unistay-lightbox-img{max-width:100%;max-height:80vh;-o-object-fit:contain;object-fit:contain;border-radius:14px;box-shadow:0 24px 50px rgba(0,0,0,.45)}.unistay-lightbox-caption{color:#fff;margin-top:14px;font-weight:600;font-size:1rem;text-align:center;text-shadow:0 1px 4px rgba(0,0,0,.5);max-width:80vw}.unistay-lightbox-close{position:absolute;top:-44px;right:0;background:none;border:none;border-radius:0;width:auto;height:auto;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;cursor:pointer;line-height:1;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.unistay-lightbox-close:hover{background:none;color:#e5d8cf;transform:scale(1.1)}.unistay-lightbox-close:active{background:none;color:#c9b7ad;transform:scale(.92)}.tenant-page .topbar .btn-theme-toggle{width:28px;height:34px;padding:0;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;color:var(--ink,#3b2820);transition:transform .18s ease,color .18s ease}.tenant-page .topbar .btn-theme-toggle svg{width:27px;height:27px;stroke-width:2.1}.tenant-page .topbar .btn-theme-toggle svg circle,.tenant-page .topbar .btn-theme-toggle svg path{transition:fill .18s ease,stroke .18s ease}.tenant-page .topbar .btn-theme-toggle:focus-visible,.tenant-page .topbar .btn-theme-toggle:hover{background:transparent!important;border:0!important;box-shadow:none!important;color:#7a4b3a;transform:scale(1.08)}.tenant-page .topbar .btn-theme-toggle:focus-visible{outline:2px solid currentColor;outline-offset:3px}.tenant-page .topbar .btn-theme-toggle:active svg path,.tenant-page .topbar .btn-theme-toggle:hover svg path{fill:currentColor}.tenant-page .topbar .btn-theme-toggle:active{background:transparent!important;color:#5c3527;transform:scale(.92)}html[data-theme=dark],html[data-theme=dark] .tenant-page,html[data-theme=dark] body{background:#1e1612!important;color:#d9c8bc!important}html[data-theme=dark] .dorms-page .shell,html[data-theme=dark] .shell,html[data-theme=dark] .tenant-page .shell{background:#382a22!important;border:1px solid #3d2d24!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .dorms-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;box-shadow:none!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .sidebar,html[data-theme=dark] aside.sidebar{background:linear-gradient(135deg,#4a372c,#3a2820)!important;border-right:1px solid #553e32!important}html[data-theme=dark] .brand,html[data-theme=dark] .brand .brand-name,html[data-theme=dark] .brand span,html[data-theme=dark] .sidebar .brand span,html[data-theme=dark] .sidebar .brand-name{color:#fff7f2!important}html[data-theme=dark] .sidebar nav a,html[data-theme=dark] .sidebar nav a .nav-label,html[data-theme=dark] .sidebar nav a span{color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a svg{stroke:#d9c8bc!important}html[data-theme=dark] .sidebar nav a:hover{background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .sidebar nav a[aria-current=page]{background:transparent!important;color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a[aria-current=page]:before{background:hsla(25,28%,79%,.14)!important;opacity:1!important}html[data-theme=dark] #guide-launcher-btn,html[data-theme=dark] .btn-topbar-guide,html[data-theme=dark] .btn-topbar-guide svg,html[data-theme=dark] .btn-topbar-guide svg circle,html[data-theme=dark] .btn-topbar-guide svg line,html[data-theme=dark] .btn-topbar-guide svg path,html[data-theme=dark] .sidebar-toggle,html[data-theme=dark] .sidebar-toggle .toggle-circle,html[data-theme=dark] .sidebar-toggle .toggle-label,html[data-theme=dark] .sidebar-toggle svg{color:#d9c8bc!important;stroke:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle{background:transparent!important;border:0!important;box-shadow:none!important;color:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle svg,html[data-theme=dark] .btn-theme-toggle svg circle,html[data-theme=dark] .btn-theme-toggle svg path,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] .btn-theme-toggle:active svg circle,html[data-theme=dark] .btn-theme-toggle:hover svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover svg circle{fill:#d9c8bc!important}html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:focus-visible,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover{color:#d9c8bc!important}html[data-theme=dark] #notification-bell,html[data-theme=dark] #notification-bell svg,html[data-theme=dark] #notification-bell svg path,html[data-theme=dark] .topbar .bell,html[data-theme=dark] .topbar .bell svg,html[data-theme=dark] .topbar .bell svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] #notification-bell:active svg,html[data-theme=dark] #notification-bell:active svg path,html[data-theme=dark] #notification-bell:hover svg,html[data-theme=dark] #notification-bell:hover svg path,html[data-theme=dark] .topbar .bell:active svg,html[data-theme=dark] .topbar .bell:active svg path,html[data-theme=dark] .topbar .bell:hover svg,html[data-theme=dark] .topbar .bell:hover svg path{fill:#d9c8bc!important}@media (min-width:901px){.tenant-page.dashboard-page .topbar .btn-theme-toggle{width:24px;height:30px}.tenant-page.dashboard-page .topbar .btn-theme-toggle svg{width:22px;height:22px}}@media (min-width:761px) and (max-width:900px){.tenant-page .topbar .btn-theme-toggle{width:20px;height:24px}.tenant-page .topbar .btn-theme-toggle svg{width:18px;height:18px}}html[data-theme=dark] #notification-popover,html[data-theme=dark] .popover,html[data-theme=dark] .profile-popover{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 18px 42px rgba(0,0,0,.6),0 6px 14px rgba(0,0,0,.35)!important}html[data-theme=dark] #notification-popover h2,html[data-theme=dark] #popover-title,html[data-theme=dark] .popover h2{background:#241813!important;border-bottom:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty,html[data-theme=dark] #popover-empty{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty p,html[data-theme=dark] #notification-popover .empty strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-list{background:#241813!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-track,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-thumb,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] #notification-popover .notification-row{background:#241813!important;border-top:1px solid #453127!important}html[data-theme=dark] #notification-popover .notification-entry{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry.is-unread{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-entry:hover,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry{background:#38271e!important}html[data-theme=dark] #notification-popover .notification-entry span,html[data-theme=dark] #notification-popover .notification-entry strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry small{color:#e2ab94!important}html[data-theme=dark] #notification-popover .notification-entry .entry-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete,html[data-theme=dark] #notification-popover .notification-mark-read{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete:hover,html[data-theme=dark] #notification-popover .notification-mark-read:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail{background:#241813!important;color:#fff7f2!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail h3,html[data-theme=dark] #notification-popover .notification-detail p{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button:hover{background:hsla(25,28%,79%,.25)!important}html[data-theme=dark] #notification-popover .notification-actions,html[data-theme=dark] #notification-popover .view-all,html[data-theme=dark] #view-all,html[data-theme=dark] .profile-popover .view-all{background:#241813!important;border-top:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all:hover,html[data-theme=dark] #view-all:hover,html[data-theme=dark] .profile-popover .view-all:hover{background:#2d1f19!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all svg,html[data-theme=dark] #view-all svg{stroke:#fff7f2!important;color:#fff7f2!important}html[data-theme=dark] .map-page .shell,html[data-theme=dark] .settings-page .shell,html[data-theme=dark] .tenant-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .map-page .topbar,html[data-theme=dark] .settings-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .map-page h1,html[data-theme=dark] .settings-page h1,html[data-theme=dark] .tenant-page .topbar h1,html[data-theme=dark] .tenant-page h1{color:#fff7f2!important}html[data-theme=dark] .tenant-page .stat-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .card-label,html[data-theme=dark] .tenant-page .card-suffix,html[data-theme=dark] .tenant-page .card-value,html[data-theme=dark] .tenant-page .stat-card-label,html[data-theme=dark] .tenant-page .stat-card-value{color:#fff7f2!important}html[data-theme=dark] .tenant-page .card-subtext,html[data-theme=dark] .tenant-page .card-subtext-link,html[data-theme=dark] .tenant-page .stat-card-desc{color:#fff7f2!important;opacity:.9}html[data-theme=dark] .tenant-page .stat-card-icon,html[data-theme=dark] .tenant-page .stat-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .current-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .current-booking-header{background:#241813!important;border-bottom:1px solid #453127!important}html[data-theme=dark] .tenant-page .current-booking-body{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-detail-label,html[data-theme=dark] .tenant-page .booking-detail-value,html[data-theme=dark] .tenant-page .booking-dorm-title,html[data-theme=dark] .tenant-page .booking-room-title,html[data-theme=dark] .tenant-page .current-booking-header h2,html[data-theme=dark] .tenant-page .section-title{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill svg{color:#fff7f2!important;stroke:#fff7f2!important;fill:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn,html[data-theme=dark] .tenant-page .btn-action-outline,html[data-theme=dark] .tenant-page .btn-action-primary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn:hover,html[data-theme=dark] .tenant-page .btn-action-outline:hover,html[data-theme=dark] .tenant-page .btn-action-primary:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-message h3,html[data-theme=dark] .tenant-page .booking-empty-message p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-home{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .tenant-page .booking-dorm-sub,html[data-theme=dark] .tenant-page .booking-room-heading,html[data-theme=dark] .tenant-page .booking-room-heading span,html[data-theme=dark] .tenant-page .booking-tenant-meta,html[data-theme=dark] .tenant-page .booking-tenant-meta span,html[data-theme=dark] .tenant-page .recent-bookings-empty h3,html[data-theme=dark] .tenant-page .recent-bookings-empty p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-bookings-empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details,html[data-theme=dark] .tenant-page .btn-manage-properties,html[data-theme=dark] .tenant-page .recent-bookings-view-all{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details:hover,html[data-theme=dark] .tenant-page .btn-manage-properties:hover,html[data-theme=dark] .tenant-page .recent-bookings-view-all:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 16px 36px rgba(0,0,0,.5)!important}html[data-theme=dark] .tenant-page .profile-toast h3,html[data-theme=dark] .tenant-page .profile-toast p,html[data-theme=dark] .tenant-page .profile-toast-btn{color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail{background:#5c4538!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] .map-page .map-panel-nav{background:#5c4538!important;border-bottom:1px solid #6e5344!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav span,html[data-theme=dark] .map-page .map-panel-nav strong{color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav .room-back-icon{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card,html[data-theme=dark] .map-page .map-dorm-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .map-page .map-search-bar{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-search-bar input{background:transparent!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-tabs{background:#5c4538!important;border-top:1px solid #6e5344!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .settings-page .settings-tab-btn{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-tab-btn:hover{color:#fff7f2!important;background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .settings-page .settings-tab-btn.is-active{color:#fff7f2!important;background:hsla(25,28%,79%,.14)!important;font-weight:700!important}html[data-theme=dark] .settings-page .settings-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 4px 16px rgba(0,0,0,.4)!important}html[data-theme=dark] .settings-page .profile-view-header{border-bottom:1px solid #453127!important}html[data-theme=dark] .settings-page .field-label,html[data-theme=dark] .settings-page .form-label,html[data-theme=dark] .settings-page .profile-view-name,html[data-theme=dark] .settings-page .settings-card h2,html[data-theme=dark] .settings-page .settings-card h3,html[data-theme=dark] .settings-page .settings-card h4,html[data-theme=dark] .settings-page .settings-card label,html[data-theme=dark] .settings-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] .settings-page .field-hint,html[data-theme=dark] .settings-page .profile-view-email,html[data-theme=dark] .settings-page .settings-card p,html[data-theme=dark] .settings-page .settings-section-desc{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-card input,html[data-theme=dark] .settings-page .settings-card select,html[data-theme=dark] .settings-page .settings-card textarea{background:#19110d!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-card input:focus,html[data-theme=dark] .settings-page .settings-card select:focus,html[data-theme=dark] .settings-page .settings-card textarea:focus{border-color:#e2ab94!important;outline:none!important}html[data-theme=dark] .settings-page .btn-primary,html[data-theme=dark] .settings-page .btn-save-settings,html[data-theme=dark] .settings-page .settings-btn-primary{background:#e2ab94!important;color:#1e1612!important;font-weight:700!important}html[data-theme=dark] .settings-page .btn-secondary,html[data-theme=dark] .settings-page .settings-btn-secondary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .settings-page #settings-main{scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:3px solid #241813!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#f0ded2!important}.auth-gate-close,.cl-dialog-close,.dorm-modal-close,.drawer-close,.id-lightbox-close,.landlord-profile-close-btn,.modal-close-btn,.policy-modal-close,.profile-toast-close,.tenant-drawer-close,.toast-close,.toast-close-btn,.unistay-lightbox-close,.us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#7a4b3a;transition:color .15s ease,transform .15s ease}.auth-gate-close:hover,.cl-dialog-close:hover,.dorm-modal-close:hover,.drawer-close:hover,.id-lightbox-close:hover,.landlord-profile-close-btn:hover,.modal-close-btn:hover,.policy-modal-close:hover,.profile-toast-close:hover,.tenant-drawer-close:hover,.toast-close-btn:hover,.toast-close:hover,.unistay-lightbox-close:hover,.us-confirm-close:hover{background:transparent!important;color:#3b2820!important;transform:scale(1.1)}.auth-gate-close:active,.cl-dialog-close:active,.dorm-modal-close:active,.drawer-close:active,.id-lightbox-close:active,.landlord-profile-close-btn:active,.modal-close-btn:active,.policy-modal-close:active,.profile-toast-close:active,.tenant-drawer-close:active,.toast-close-btn:active,.toast-close:active,.unistay-lightbox-close:active,.us-confirm-close:active{background:transparent!important;color:#1a0f0a!important;transform:scale(.92)}.auth-gate-close:focus-visible,.cl-dialog-close:focus-visible,.dorm-modal-close:focus-visible,.drawer-close:focus-visible,.id-lightbox-close:focus-visible,.landlord-profile-close-btn:focus-visible,.modal-close-btn:focus-visible,.policy-modal-close:focus-visible,.profile-toast-close:focus-visible,.tenant-drawer-close:focus-visible,.toast-close-btn:focus-visible,.toast-close:focus-visible,.unistay-lightbox-close:focus-visible,.us-confirm-close:focus-visible{outline:2px solid currentColor;outline-offset:3px}html[data-theme=dark] .auth-gate-close,html[data-theme=dark] .cl-dialog-close,html[data-theme=dark] .dorm-modal-close,html[data-theme=dark] .drawer-close,html[data-theme=dark] .id-lightbox-close,html[data-theme=dark] .landlord-profile-close-btn,html[data-theme=dark] .modal-close-btn,html[data-theme=dark] .policy-modal-close,html[data-theme=dark] .profile-toast-close,html[data-theme=dark] .tenant-drawer-close,html[data-theme=dark] .toast-close,html[data-theme=dark] .toast-close-btn,html[data-theme=dark] .unistay-lightbox-close,html[data-theme=dark] .us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#fff7f2!important}html[data-theme=dark] .auth-gate-close:hover,html[data-theme=dark] .cl-dialog-close:hover,html[data-theme=dark] .dorm-modal-close:hover,html[data-theme=dark] .drawer-close:hover,html[data-theme=dark] .id-lightbox-close:hover,html[data-theme=dark] .landlord-profile-close-btn:hover,html[data-theme=dark] .modal-close-btn:hover,html[data-theme=dark] .policy-modal-close:hover,html[data-theme=dark] .profile-toast-close:hover,html[data-theme=dark] .tenant-drawer-close:hover,html[data-theme=dark] .toast-close-btn:hover,html[data-theme=dark] .toast-close:hover,html[data-theme=dark] .unistay-lightbox-close:hover,html[data-theme=dark] .us-confirm-close:hover{background:transparent!important;color:#d8c3b4!important}html[data-theme=dark] .auth-gate-close:active,html[data-theme=dark] .cl-dialog-close:active,html[data-theme=dark] .dorm-modal-close:active,html[data-theme=dark] .drawer-close:active,html[data-theme=dark] .id-lightbox-close:active,html[data-theme=dark] .landlord-profile-close-btn:active,html[data-theme=dark] .modal-close-btn:active,html[data-theme=dark] .policy-modal-close:active,html[data-theme=dark] .profile-toast-close:active,html[data-theme=dark] .tenant-drawer-close:active,html[data-theme=dark] .toast-close-btn:active,html[data-theme=dark] .toast-close:active,html[data-theme=dark] .unistay-lightbox-close:active,html[data-theme=dark] .us-confirm-close:active{background:transparent!important;color:#b99f90!important}html[data-theme=dark] .profile-summary{background:#231a14!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .profile-photo{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-identity strong{color:#fff7f2!important}html[data-theme=dark] .profile-role{color:#a8948a!important}html[data-theme=dark] .profile-role-sep{color:#6c584c!important}html[data-theme=dark] .profile-view-link{color:#e2ab94!important}html[data-theme=dark] .profile-view-link:hover{background:hsla(18,57%,73%,.16)!important;color:#fff7f2!important}html[data-theme=dark] .profile-switch-header{color:#a8948a!important;border-top-color:#3d2d24!important}html[data-theme=dark] .profile-switch-empty{color:#a8948a!important}html[data-theme=dark] .profile-account-item{color:#d9c8bc!important}html[data-theme=dark] .profile-account-item:hover{background:#34261f!important}html[data-theme=dark] .profile-account-item .account-avatar{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-account-item .account-name{color:#fff7f2!important}html[data-theme=dark] .profile-account-item .account-email{color:#a8948a!important}html[data-theme=dark] .profile-add-account,html[data-theme=dark] .profile-logout{color:#e2ab94!important}html[data-theme=dark] .profile-logout:hover{background:#34261f!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread){background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry.is-unread,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:transparent!important;border-color:transparent!important;box-shadow:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{color:#e2ab94!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read:hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{color:#fff7f2!important;background:transparent!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button{background:transparent!important;border:0!important;box-shadow:none!important;color:#fff7f2!important;text-decoration:none!important}html:not([data-theme=dark]) #notification-popover .notification-row,html:not([data-theme=dark]) #notification-popover .notification-row .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row .notification-mark-read{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread),html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html[data-theme=dark] #notification-popover .notification-row,html[data-theme=dark] #notification-popover .notification-row .notification-delete,html[data-theme=dark] #notification-popover .notification-row .notification-entry,html[data-theme=dark] #notification-popover .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread),html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-row:focus-within,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-entry,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry,html[data-theme=dark] #notification-popover .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:hover{background:transparent!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:active{background:transparent!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link,html[data-theme=dark] .profile-popover:not(#_a) .view-all{text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:hover{background:transparent!important;color:#cf967f!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:active{background:transparent!important;color:#b57f6a!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:hover{color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:active{color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all,html[data-theme=dark] #notification-popover:not(#_a) .view-all{background:#241813!important;color:#fff7f2!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover{background:#241813!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active{background:#241813!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover svg{stroke:#d8c3b4!important;color:#d8c3b4!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active svg{stroke:#b99f90!important;color:#b99f90!important}body.tenant-page #clear-all,body.tenant-page #notification-popover .notification-actions,body.tenant-page #read-all,body.tenant-page .notification-page-heading{display:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar h1{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab{color:#e2d3c8!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab:hover{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tabs-indicator,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-indicator{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 2px 8px rgba(0,0,0,.35)!important}html[data-theme=dark] body.admin-users-page .tenants-table-card,html[data-theme=dark] body.admin-verification-page .tenant-card{background:#2b1f19!important;border-color:#4b382d!important;color:#e2d3c8!important}html[data-theme=dark] body.admin-users-page .tenants-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table td{color:#e2d3c8!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table tbody tr:hover,html[data-theme=dark] body.admin-verification-page .tenant-card:hover{background:#34261f!important}html[data-theme=dark] body.admin-users-page .tenants-table td strong,html[data-theme=dark] body.admin-verification-page .meta-value,html[data-theme=dark] body.admin-verification-page .tenant-head h3{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .meta-label,html[data-theme=dark] body.admin-verification-page .tenant-sub{color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .tenant-meta{border-top-color:#4b382d!important}html[data-theme=dark] body.admin-verification-page .um-select-btn,html[data-theme=dark] body.admin-verification-page .um-select-menu,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search{background:#231a14!important;border-color:#563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .um-select-menu li,html[data-theme=dark] body.admin-verification-page .um-select-value,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search input{color:#d9c8bc!important}html[data-theme=dark] body.admin-users-page .badge-status-suspended{color:#f87171!important}#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{position:fixed!important;right:24px!important;bottom:24px!important;width:min(400px,calc(100vw - 32px))!important;max-width:min(400px,calc(100vw - 32px))!important;min-width:min(400px,calc(100vw - 32px))!important;height:116px!important;min-height:116px!important;max-height:116px!important;box-sizing:border-box!important;padding:16px 20px!important;border-radius:16px!important;z-index:100000!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}@media (max-width:520px){#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{right:16px!important;bottom:16px!important;left:16px!important;width:auto!important;min-width:0!important;max-width:none!important;height:116px!important;min-height:116px!important;max-height:116px!important}}#confirmModal,#confirmModal *,#deleteModal,#deleteModal *,#dorm-modal,#dorm-modal *,#inspectModal,#inspectModal *,#logout-modal,#logout-modal *,#rejectModal,#rejectModal *,#resultModal,#resultModal *,#room-modal,#room-modal *,#suspend-modal,#suspend-modal *,.cl-dialog,.cl-dialog *,.confirm-modal-box,.confirm-modal-box *,.cropper-modal-card,.cropper-modal-card *,.delete-account-modal,.delete-account-modal *,.dialog,.dialog *,.inspect-box,.inspect-box *,.inspect-modal,.inspect-modal *,.logout-modal-card,.logout-modal-card *,.modal,.modal *,.modal-box,.modal-box *,.modal-card,.modal-card *,.modal-panel,.modal-panel *,.policy-modal,.policy-modal *,.policy-modal-panel,.policy-modal-panel *,.ps-card,.ps-card *,.settings-modal-card,.settings-modal-card *,.suspend-modal-dialog,.suspend-modal-dialog *,dialog,dialog *,dialog.us-confirm,dialog.us-confirm *{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}.cl-dialog,.confirm-modal-box,.cropper-modal-card,.delete-account-modal,.inspect-box,.logout-modal-card,.modal-box,.modal-card,.modal-panel,.policy-modal-panel,.ps-card,.settings-modal-card,.suspend-modal-dialog,dialog,dialog.us-confirm{border-radius:24px!important}#dorm-modal h2,#dorm-modal h3,#room-details-title,.cl-dialog h2,.cl-dialog h3,.cropper-modal-title,.delete-modal-title,.inspect-box h2,.inspect-box h3,.logout-modal-title,.modal h1,.modal h2,.modal h3,.modal h4,.modal-box h1,.modal-box h2,.modal-box h3,.modal-box h4,.modal-panel h1,.modal-panel h2,.modal-panel h3,.modal-panel h4,.policy-card h2,.policy-modal-header h2,.policy-modal-panel h2,.policy-modal-panel h3,.ps-card h2,.room-modal-header-title,.room-title-heading,.settings-modal-card h2,.settings-modal-card h3,.suspend-modal-dialog h2,.suspend-modal-dialog h3,dialog h1,dialog h2,dialog h3,dialog h4,dialog.us-confirm .us-confirm-panel h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px!important;font-weight:700!important;letter-spacing:-.2px!important;line-height:1.3!important}.cl-actions button,.cropper-modal-footer button,.delete-modal-actions button,.inspect-footer button,.policy-modal-footer button,.us-confirm-actions button,dialog button:not(.us-confirm-close):not(.cl-dialog-close):not(.policy-modal-close):not(.delete-modal-close):not(.cropper-btn-round):not(.modal-close):not(.close-btn):not([aria-label*=lose]):not([aria-label*=Dismiss]){border-radius:12px!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important}.verified-check{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:14px;height:14px;margin-left:5px;vertical-align:-2px;border-radius:50%;background:#10b981;color:#fff;overflow:visible}.verified-check svg{width:8px;height:8px;stroke-width:3.4;overflow:visible}.view-all,.view-all:active,.view-all:focus,.view-all:focus-visible,.view-all:hover{text-decoration:none!important}html[data-theme=dark] .shell-orbs{display:none!important}dialog.us-confirm{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);margin:0;width:min(92vw,480px);max-width:480px;padding:0;border:none;border-radius:24px;background:#fffdf9;color:#3b2820;box-shadow:0 28px 70px rgba(55,31,22,.28);text-align:center;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden}dialog.us-confirm::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);animation:us-confirm-fade .22s ease both}dialog.us-confirm[open]{animation:us-confirm-pop .28s cubic-bezier(.16,1,.3,1) both}@keyframes us-confirm-pop{0%{opacity:0;transform:translate(-50%,-46%) scale(.97)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}dialog.us-confirm.is-closing{animation:us-confirm-out .2s cubic-bezier(.2,.8,.2,1) both;pointer-events:none}@keyframes us-confirm-out{0%{opacity:1;transform:translate(-50%,-50%) scale(1)}to{opacity:0;transform:translate(-50%,-46%) scale(.97)}}@keyframes us-confirm-fade{0%{opacity:0}to{opacity:1}}.us-confirm-panel{position:relative;padding:38px 34px 30px;display:flex;flex-direction:column;align-items:center;text-align:center;box-sizing:border-box}.us-confirm-close{position:absolute;top:18px;right:18px;display:inline-flex;align-items:center;justify-content:center;width:auto;height:auto;padding:0;border:none;border-radius:0;background:none;color:#7a4b3a;font-size:26px;line-height:1;cursor:pointer;box-shadow:none;transition:color .15s ease,transform .15s ease}.us-confirm-close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.us-confirm-close:active{background:none;color:#1a0f0a;transform:scale(.92)}.us-confirm-icon{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 20px;border-radius:50%;background:#f7efe5;color:#7a4b3a}.us-confirm-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.us-confirm-icon.is-danger{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}.us-confirm-panel h2{margin:0 0 10px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px;font-weight:700;line-height:1.3;letter-spacing:-.2px;color:#3b2820;text-align:center}.us-confirm-panel p{margin:0 auto 26px;max-width:390px;color:#745f54;line-height:1.6;font-size:14px;text-align:center}.us-confirm-actions{display:flex;align-items:center;justify-content:center;gap:12px;width:100%}.us-confirm-btn-primary,.us-confirm-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 24px;border-radius:12px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14.5px;font-weight:700;cursor:pointer;transition:all .18s ease;box-sizing:border-box;flex:1}.us-confirm-btn-secondary{border:1px solid #d8c3b4;background:#fff;color:#6b574e;font-weight:600;box-shadow:0 2px 6px rgba(0,0,0,.04)}.us-confirm-btn-secondary:hover{background:#fcf7f4;color:#3b2820;border-color:#c4aca0}.us-confirm-btn-primary{border:none;background:#7a4b3a;color:#fff;box-shadow:0 8px 20px rgba(122,75,58,.24)}.us-confirm-btn-primary:hover{background:#613829;transform:translateY(-1px);box-shadow:0 10px 24px rgba(122,75,58,.3)}.us-confirm-btn-primary.is-danger{background:#dc2626!important;border:none!important;color:#fff!important;box-shadow:0 8px 20px rgba(220,38,38,.28)!important}.us-confirm-btn-primary.is-danger:hover{background:#b91c1c!important;transform:translateY(-1px)!important;box-shadow:0 10px 24px rgba(220,38,38,.35)!important}.us-confirm-btn-primary:focus-visible,.us-confirm-btn-secondary:focus-visible,.us-confirm-close:focus-visible{outline:3px solid #ac7728;outline-offset:3px}@media (max-width:480px){.us-confirm-panel{padding:30px 22px 24px}.us-confirm-panel h2{font-size:24px}.us-confirm-actions{flex-direction:column-reverse}.us-confirm-btn-primary,.us-confirm-btn-secondary{width:100%}}@media (prefers-reduced-motion:reduce){dialog.us-confirm.is-closing,dialog.us-confirm::backdrop,dialog.us-confirm[open]{animation:none}.us-confirm-btn-primary,.us-confirm-btn-secondary{transition:none}}[data-theme=dark] dialog.us-confirm{background:#2b1f19;color:#fff7f2;border:1px solid var(--line,#4b382d);box-shadow:0 28px 70px rgba(0,0,0,.65)}[data-theme=dark] dialog.us-confirm::backdrop{background:rgba(18,12,9,.7)}[data-theme=dark] .us-confirm-panel h2{color:#fff7f2}[data-theme=dark] .us-confirm-panel p{color:#d9c8bc}[data-theme=dark] .us-confirm-close{color:#dca08b}[data-theme=dark] .us-confirm-close:hover{color:#fff7f2}[data-theme=dark] .us-confirm-icon{background:#382a22;color:#dca08b}[data-theme=dark] .us-confirm-icon.is-danger{background:rgba(197,59,42,.22);color:#f87171}[data-theme=dark] .us-confirm-btn-secondary{background:#382a22;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .us-confirm-btn-secondary:hover{background:#45332a;color:#fff7f2;border-color:#5f4435}#inspectImg[hidden],#inspectUnavailable[hidden],.btn-inspect[hidden]{display:none}.admin-verification-page .topbar h1{font-family:Fraunces,Georgia,Times New Roman,serif!important;font-weight:700!important;color:#5f3425!important;letter-spacing:-.6px;font-size:2.1rem}.shell-orbs{position:absolute;inset:0;overflow:hidden;border-radius:inherit;pointer-events:none;z-index:0}.shell-orbs i{position:absolute;display:block;border-radius:50%;filter:blur(64px)}.shell-orbs i:first-child{width:360px;height:360px;background:#f3e2ac;opacity:.3;top:-110px;left:14%}.shell-orbs i:nth-child(2){width:430px;height:430px;background:#e4cdaf;opacity:.3;bottom:-150px;right:6%}.shell-orbs i:nth-child(3){width:320px;height:320px;background:#ffba95;opacity:.2;top:36%;left:50%}@media (min-width:761px){.admin-verification-page .shell{min-height:calc(100vh - 30px);height:calc(100vh - 30px);border-radius:22px;padding:0 54px 24px;display:flex;flex-direction:column;overflow:hidden}.admin-verification-page .topbar{flex-shrink:0;position:relative;z-index:1000;height:auto!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;border-bottom:1px solid #ebd9cb!important;padding:0!important;gap:0!important}.admin-verification-page .topbar-main-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 4px}.admin-verification-page .profile{position:relative;z-index:1001}.admin-verification-page #verification-main{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding-top:20px;padding-bottom:30px;margin-right:-54px;padding-right:42px;scrollbar-color:var(--brown,#7a4b3a) transparent;scrollbar-width:thin}.admin-verification-page #verification-main::-webkit-scrollbar{width:12px}.admin-verification-page #verification-main::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}.admin-verification-page #verification-main::-webkit-scrollbar-thumb{background:var(--brown,#7a4b3a);border:3px solid #f3e9df;border-radius:999px}.admin-verification-page #verification-main::-webkit-scrollbar-thumb:hover{background:#5f3425}}.topbar-categories-bar{display:flex;width:100%;border-top:1px solid #ebd9cb;margin:0 0 -1px;padding:0;background:hsla(38,67%,98%,.7);position:relative;z-index:2}.topbar-cat-tab{flex:1 1 0;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 16px;font-family:DM Sans,sans-serif;font-size:.94rem;font-weight:600;color:#7a4b3a;text-decoration:none;text-align:center;white-space:nowrap;border-bottom:3px solid transparent;transition:color .2s ease,border-color .2s ease,background-color .2s ease}.topbar-cat-tab:hover{color:#5f3425;background:rgba(122,75,58,.04)}.topbar-cat-tab.is-active{color:#5f3425;font-weight:700;border-bottom-color:transparent;background:rgba(122,75,58,.02)}.topbar-cat-indicator{position:absolute;top:0;left:0;height:3px;background:#7a4b3a;border-radius:3px 3px 0 0;z-index:3;pointer-events:none;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}.cat-count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:999px;font-size:.72rem;font-weight:700;background:#dc2626;color:#fff}.verif-controls-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}.settings-tabs{position:relative;display:inline-flex;width:-moz-fit-content;width:fit-content;max-width:-moz-max-content;max-width:max-content;align-items:center;gap:4px;margin:0;padding:4px;border-radius:11px;background:#efebe7;border:1px solid #ebd9cb;overflow-x:auto;scrollbar-width:none}.settings-tabs::-webkit-scrollbar{display:none}.settings-tabs-indicator{position:absolute;top:4px;left:0;height:calc(100% - 8px);border-radius:8px;background:#7a4b3a;box-shadow:0 1px 4px rgba(122,75,58,.22);z-index:1;pointer-events:none;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}.settings-tab-btn{position:relative;z-index:2;display:inline-flex;align-items:center;gap:8px;padding:8px 18px;background:transparent;border:none;border-radius:8px;color:#7a4b3a;font-family:DM Sans,sans-serif;font-size:.9rem;font-weight:600;text-decoration:none;cursor:pointer;transition:color .25s ease;white-space:nowrap}.settings-tab-btn:hover{color:#3b2820}.settings-tab-btn.is-active{color:#fff!important;font-weight:700}.tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;font-size:.72rem;font-weight:700;background:rgba(122,75,58,.14);color:#7a4b3a;transition:background .25s ease,color .25s ease}.settings-tab-btn.is-active .tab-count{background:hsla(0,0%,100%,.25);color:#fff}.um-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-start;gap:12px;margin:14px 0 24px}.um-toolbar-left{flex-wrap:nowrap;gap:12px;flex:1 1 auto;width:100%;min-width:0;max-width:100%}.bulk-actions,.um-toolbar-left{display:inline-flex;align-items:center}.bulk-actions{gap:10px;flex:0 0 auto}.bulk-actions form{margin:0}.btn-bulk-action{display:inline-flex;align-items:center;justify-content:center;min-width:112px;height:44px;padding:0 16px;border-radius:12px;font:700 .86rem DM Sans,sans-serif;cursor:pointer;transition:background-color .18s ease,border-color .18s ease,transform .18s ease,opacity .18s ease}.btn-bulk-approve{border:1px solid #15803d;background:#15803d;color:#fff}.btn-bulk-approve:hover:not(:disabled){background:#166534;border-color:#166534;transform:translateY(-1px)}.btn-bulk-decline{border:1px solid #dc2626;background:#fff;color:#b91c1c}.btn-bulk-decline:hover:not(:disabled){background:#fef2f2;transform:translateY(-1px)}.btn-bulk-action:disabled{opacity:.55;cursor:not-allowed;transform:none}@media (max-width:760px){.um-toolbar-left{width:100%;flex-direction:column;align-items:stretch}.um-search,.um-select,.um-select-btn{width:100%!important;flex-basis:auto!important;min-width:0!important}.btn-bulk-action,.bulk-actions,.bulk-actions form{width:100%}}.um-search{display:flex;align-items:center;gap:10px;flex:1 1 auto;width:auto;max-width:none;min-width:200px;height:44px;padding:0 16px;border:1px solid #d9bea6;border-radius:14px;background:#fff;box-shadow:0 2px 8px rgba(84,60,48,.08);transition:border-color .18s,box-shadow .18s}.um-search svg{width:17px;height:17px;flex:none;stroke:#8b7467;stroke-width:2;fill:none}.um-search input{flex:1;min-width:0;border:0;background:none;padding:0;font:inherit;color:#5f3425;outline:none;font-family:DM Sans,sans-serif;font-size:.92rem}.um-search input::-moz-placeholder{color:#8b7467;opacity:1}.um-search input::placeholder{color:#8b7467;opacity:1}.um-search:focus-within{border-color:#7a4b3a;box-shadow:0 2px 8px rgba(84,60,48,.12),0 0 0 3px rgba(122,75,58,.14)}.um-select{position:relative}.um-select-btn{display:flex;align-items:center;justify-content:space-between;gap:12px;height:44px;min-width:240px;padding:0 16px;border:1px solid #d9bea6;border-radius:14px;background:#fff;box-shadow:0 2px 8px rgba(84,60,48,.08);font-family:DM Sans,sans-serif;font-size:.92rem;font-weight:500;color:#5f3425;cursor:pointer;transition:border-color .18s,box-shadow .18s}.um-select-btn:hover{border-color:#cbb5a9}.um-select-btn[aria-expanded=true]{border-color:#7a4b3a;box-shadow:0 2px 8px rgba(84,60,48,.12),0 0 0 3px rgba(122,75,58,.14)}.um-select-caret{width:17px;height:17px;flex:none;stroke:currentColor;stroke-width:2;fill:none;color:#8b7467;transition:transform .2s ease}.um-select-btn[aria-expanded=true] .um-select-caret{transform:rotate(180deg)}.um-select-menu{position:absolute;top:calc(100% + 8px);left:0;z-index:1200;min-width:100%;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #eadfd9;border-radius:14px;box-shadow:0 18px 44px rgba(60,30,20,.18);transform-origin:top left;animation:um-menu-in .22s cubic-bezier(.16,1,.3,1) both}.um-select-menu[hidden]{display:none}.um-select-menu.is-closing{animation:um-menu-out .18s cubic-bezier(.2,.8,.2,1) both;pointer-events:none}@keyframes um-menu-in{0%{opacity:0;transform:translateY(-8px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes um-menu-out{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(-6px) scale(.96)}}.um-select-menu li{padding:10px 14px;border-radius:10px;color:#5f3425;font-size:.92rem;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s}.um-select-menu li:hover,.um-select-menu li[aria-selected=true]{background:#fbf6f2;color:#7a4b3a;font-weight:600}.verif-cards-list{display:flex;flex-direction:column;gap:16px}.tenant-card{display:flex;align-items:flex-start;gap:20px;background:#fff;border:1px solid #ebd9cb;border-radius:20px;padding:22px 24px;transition:box-shadow .25s cubic-bezier(.2,0,0,1),transform .25s cubic-bezier(.2,0,0,1),border-color .25s ease;box-shadow:0 4px 16px rgba(92,62,48,.06)}.tenant-card:hover{border-color:#cbb5a9;box-shadow:0 12px 28px rgba(92,62,48,.12);transform:translateY(-2px)}.tenant-avatar{width:54px;height:54px;flex-shrink:0;border-radius:14px;display:grid;place-items:center;background:#fbf4ee;color:#7a4b3a;font-family:DM Sans,sans-serif;font-size:16px;font-weight:700;letter-spacing:.5px;overflow:hidden;border:1px solid #ebd9cb}.tenant-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.tenant-main{flex:1;min-width:0}.tenant-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px}.tenant-head h3{margin:0;font-family:Fraunces,Georgia,serif;font-size:18px;font-weight:700;color:#5f3425;letter-spacing:-.2px}.tenant-sub{margin:2px 0 0;font-family:DM Sans,sans-serif;font-size:13.5px;color:#78665c;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tenant-sub .dot{color:#cbbbb0}.tenant-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px 22px;margin-top:14px;padding-top:14px;border-top:1px dashed #ebd9cb}.meta{display:flex;flex-direction:column;gap:2px;min-width:0}.meta-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9a8c84}.meta-label,.meta-value{font-family:DM Sans,sans-serif}.meta-value{font-size:13.5px;font-weight:600;color:#3b2820;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tenant-actions{display:flex;flex-direction:column;gap:7px;flex-shrink:0;align-self:center;width:140px;min-width:140px;align-items:stretch}.btn-card-approve{font:600 .84rem DM Sans,sans-serif;padding:0 14px;height:36px;border-radius:8px;border:1px solid #15803d;background:#15803d;color:#fff!important;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all .18s ease;white-space:nowrap;width:100%;box-sizing:border-box}.btn-card-approve:hover:not(:disabled){background:#166534;border-color:#166534;transform:translateY(-1px);box-shadow:0 3px 8px rgba(21,128,61,.25)}.btn-card-approve:disabled{opacity:.65;cursor:default}.btn-card-decline{font:600 .84rem DM Sans,sans-serif;padding:0 14px;height:36px;border-radius:8px;border:1px solid #fecaca;background:#fdfaf5;color:#dc2626!important;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 1px 3px rgba(0,0,0,.08);transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease;white-space:nowrap;width:100%;box-sizing:border-box}.btn-card-decline:hover:not(:disabled){background:#fff5f5;border-color:#ef4444;color:#b91c1c!important;transform:scale(1.08);box-shadow:0 8px 22px rgba(220,38,38,.2)}.btn-card-decline:active:not(:disabled){transform:scale(1.12)}.btn-card-decline:disabled{opacity:.65;cursor:default;transform:none!important;box-shadow:none!important}.badge{display:inline-flex;align-items:center;gap:6px;font-family:DM Sans,sans-serif;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}.badge.pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.badge.active,.badge.approved,.badge.verified{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}.badge.declined,.badge.rejected{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.badge.unverified{background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb}.btn-booking-details{font:600 .84rem DM Sans,sans-serif;padding:0 14px;height:36px;width:100%;box-sizing:border-box;border-radius:8px;border:1px solid #e8ddd3;background:#fdfaf5;color:#7a4b3a!important;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.08);transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease;white-space:nowrap}.btn-booking-details:focus-visible,.btn-booking-details:hover{color:#5c3527!important;background:#fdfaf5;border-color:#7a4b3a;transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.2)}.btn-booking-details:active{transform:scale(1.12)}@media (max-width:860px){.topbar-categories-bar{flex-wrap:wrap}.topbar-cat-tab{flex:1 1 50%;padding:10px 8px}.tenant-card{flex-direction:column;align-items:stretch;gap:16px}.tenant-actions{width:100%;align-items:stretch}.btn-booking-details{width:100%}}.drawer-overlay{position:fixed;inset:0;background:rgba(47,32,26,.45);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:9999}.drawer-overlay.is-open{opacity:1;pointer-events:auto}.drawer{position:fixed;top:0;right:0;bottom:0;width:min(560px,calc(100vw - 20px));background:#fdfaf5;box-shadow:-22px 0 54px rgba(45,20,10,.22);border-top-left-radius:28px;border-bottom-left-radius:28px;border-top-right-radius:0;border-bottom-right-radius:0;border-left:1px solid hsla(28,40%,75%,.7);overflow:hidden;transform:translateX(100%);transition:transform .35s cubic-bezier(.16,1,.3,1);z-index:10000;display:flex;flex-direction:column}.drawer.is-open{transform:translateX(0)}.drawer-header{display:flex;align-items:center;justify-content:space-between;padding:22px 26px;border-bottom:1px solid #ebd9cb;flex-shrink:0;background:#fff;border-top-left-radius:28px;border-top-right-radius:0}.drawer-header h2{margin:0;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:21px;font-weight:700;color:#5f3425;letter-spacing:-.2px}.drawer-close{width:auto;height:auto;border:none;background:none;color:#7a4b3a;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;font-size:26px;line-height:1;margin-left:auto;transition:color .15s ease,transform .15s ease}.drawer-close:hover{color:#3b2820;transform:scale(1.1)}.drawer-close:active{color:#1a0f0a;transform:scale(.92)}.drawer-body{flex:1;overflow-y:auto;padding:24px 26px 32px;display:flex;flex-direction:column;gap:20px;scrollbar-width:thin;scrollbar-color:#7a4b3a transparent}.drawer-body::-webkit-scrollbar{width:10px}.drawer-body::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}.drawer-body::-webkit-scrollbar-thumb{background:#7a4b3a;border:2px solid #f3e9df;border-radius:999px}.drawer-body::-webkit-scrollbar-thumb:hover{background:#5f3425}.drawer-hero{background:linear-gradient(135deg,#fbf4ee,#fdfaf6);border:1px solid #ebd9cb;border-radius:20px;padding:20px 22px;box-shadow:0 4px 14px rgba(122,75,58,.05)}.drawer-hero .hero-top{display:flex;align-items:center;gap:16px;margin-bottom:12px}.drawer-hero .hero-avatar{width:64px;height:64px;border-radius:18px;font-family:DM Sans,sans-serif;font-size:22px;font-weight:700;background:#fff;border:1px solid #ebd9cb;color:#7a4b3a;display:grid;place-items:center;overflow:hidden;flex-shrink:0;box-shadow:0 2px 8px rgba(122,75,58,.08)}.drawer-hero .hero-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.drawer-hero .hero-info h3{margin:0 0 6px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:20px;font-weight:700;color:#3b2820}.drawer-hero .hero-meta{display:flex;flex-wrap:wrap;gap:8px}.drawer-hero .hero-chip{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #ebd9cb;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:600;color:#5c3e34}.detail-section{background:#fff;border:1px solid #ebd9cb;border-radius:20px;padding:20px 22px;box-shadow:0 3px 12px rgba(60,30,20,.04)}.detail-section-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #f3e9df}.detail-section-head h4{margin:0;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:16px;font-weight:700;color:#5f3425}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.detail-item{background:#fdfbf9;border:1px solid #f2e7df;border-radius:12px;padding:10px 12px;display:flex;flex-direction:column;gap:3px;min-width:0;transition:border-color .18s,background .18s}.detail-item:hover{border-color:#d9bea6;background:#fffdfb}.detail-item.full{grid-column:1/-1}.detail-label{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#8b7467}.detail-label,.detail-value{font-family:DM Sans,sans-serif}.detail-value{font-size:13.5px;font-weight:600;color:#292524;word-break:break-word;line-height:1.4}.verif-doc-box{background:#fdfbf9;border:1px solid #ebd9cb;border-radius:14px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px}.verif-doc-box.is-missing{background:#fafaf9;border:1px dashed #d6d3d1;opacity:.85}.doc-thumbnail-wrap{display:flex;align-items:center;gap:10px;min-width:0}.doc-thumb{width:48px;height:48px;border-radius:10px;background:#ebd9cb;display:grid;place-items:center;font-size:20px;overflow:hidden;cursor:pointer;flex-shrink:0;border:1px solid #dfc6b4;transition:transform .15s ease}.doc-thumb:hover{transform:scale(1.06)}.doc-thumb img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.doc-details strong{display:block;font-size:.88rem;color:#292524;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.doc-details span{font-size:.78rem;color:#8c7569;display:block}.btn-inspect{background:#fff;border:1px solid #ebd9cb;color:#7a4b3a;padding:7px 14px;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;white-space:nowrap;flex-shrink:0}.btn-inspect:hover{background:#7a4b3a;color:#fff;border-color:#7a4b3a}.drawer-footer{flex-shrink:0;padding:18px 24px;border-top:1px solid #ebd9cb;background:#fff;border-bottom-left-radius:28px;border-bottom-right-radius:0}.drawer-footer,.drawer-footer form{display:flex;flex-direction:column;gap:10px}.drawer-footer form{width:100%}.drawer-footer-actions{display:flex;gap:10px;width:100%}.btn-approve{flex:1;background:#15803d;color:#fff;border:none;padding:10px 16px;border-radius:8px;font-weight:700;font-size:.9rem;cursor:pointer;transition:all .18s;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn-approve:hover{background:#166534;transform:translateY(-1px)}.btn-reject{flex:1;background:#fff;color:#dc2626;border:1px solid #fca5a5;padding:10px 16px;border-radius:8px;font-weight:700;font-size:.9rem;cursor:pointer;transition:all .18s;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn-reject:hover{background:#fef2f2;border-color:#ef4444}.btn-approve:disabled,.btn-reject:disabled{opacity:.7;cursor:wait;transform:none}.verif-empty{background:transparent;border:none;border-radius:0;box-shadow:none;padding:60px 24px 80px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#8c7569}.verif-empty-icon{font-size:3rem;margin-bottom:12px;display:block}.inspect-modal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.65);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:24px}.inspect-modal.is-active{display:flex}.inspect-box{background:#fff;border-radius:24px;max-width:860px;width:100%;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.3);animation:zoomModal .25s cubic-bezier(.16,1,.3,1);font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}.inspect-header{padding:18px 24px;border-bottom:1px solid #ebd9cb;justify-content:space-between}.inspect-body,.inspect-header{display:flex;align-items:center}.inspect-body{padding:24px;overflow-y:auto;text-align:center;background:#f7f3ef;justify-content:center;min-height:360px}.inspect-img{max-width:100%;max-height:60vh;-o-object-fit:contain;object-fit:contain;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.12)}.inspect-footer{padding:16px 24px;border-top:1px solid #ebd9cb;display:flex;justify-content:space-between;align-items:center;background:#fff}.inspect-footer .btn-approve,.inspect-footer .btn-inspect,.inspect-footer .btn-reject{border-radius:12px!important;font-family:DM Sans,sans-serif!important;font-weight:600!important}@keyframes zoomModal{0%{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}.policy-modal{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:20px}.policy-modal-backdrop{position:fixed;inset:0;background:rgba(30,15,10,.55);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);transition:opacity .22s ease}.policy-modal-panel{position:relative;width:min(100%,640px);max-height:84vh;display:flex;flex-direction:column;background:#fdfaf5;border:1px solid #e8ddd3;border-radius:24px;box-shadow:0 20px 50px rgba(45,25,18,.28);overflow:hidden;animation:policy-pop .26s cubic-bezier(.16,1,.3,1) both;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}@keyframes policy-pop{0%{opacity:0;transform:scale(.95) translateY(14px)}to{opacity:1;transform:scale(1) translateY(0)}}.policy-modal.is-closing{pointer-events:none}.policy-modal.is-closing .policy-modal-backdrop{opacity:0}.policy-modal.is-closing .policy-modal-panel{opacity:0;transform:scale(.96) translateY(10px);transition:opacity .22s ease,transform .22s ease}.policy-modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px 16px;border-bottom:1px solid #e8ddd3;background:#fdfaf5}.policy-modal-title-group{display:flex;flex-direction:column;gap:2px}.policy-modal-crumb{font-size:12px;font-weight:600;color:#a58d84;letter-spacing:.4px;text-transform:uppercase;margin:0}.policy-modal-header h2{margin:0;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-weight:700;font-size:22px;line-height:1.3;color:#6b3624}.policy-modal-close{display:grid;place-items:center;width:36px;height:36px;border:0;background:transparent;color:#7b4a3a;font-size:24px;line-height:1;cursor:pointer;border-radius:0;box-shadow:none;transition:color .18s ease,transform .18s ease}.policy-modal-close:hover{background:transparent;color:#3b2820;transform:scale(1.1)}.policy-modal-close:active{background:transparent;color:#1a0f0a;transform:scale(.92)}#rejectModal .policy-modal-header>.btn-inspect{display:none}.policy-modal-body{padding:24px 26px;overflow-y:auto;font-family:DM Sans,sans-serif;color:#8b5a4a;scrollbar-color:#7b4a3a transparent;scrollbar-width:thin}.policy-modal-body .policy-card{background:#f7f1e7;border:1px solid #e8ddd3;border-radius:18px;padding:20px 24px}.policy-modal-body .policy-card h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-weight:700;font-size:19px;color:#6b3624;margin:0 0 8px}.policy-modal-body .policy-card p{font-size:14.5px;line-height:24px;color:#8b5a4a;margin:0}.policy-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:16px 26px;border-top:1px solid #e8ddd3;background:#fdfaf5}.policy-modal-footer-note{font-size:12.5px;color:#a58d84;font-weight:500}.btn-policy-confirm{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 24px;border:1px solid #e8ddd3;border-radius:12px;background:#7b4a3a;color:#fff;font:600 14px DM Sans,sans-serif;cursor:pointer;transition:all .2s ease}.btn-policy-confirm:hover{background:#5c3527;border-color:#5c3527}.policy-modal[hidden]{display:none!important}.btn-policy-cancel{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 22px;border:1px solid #e8ddd3;border-radius:12px;background:#fff;color:#7b4a3a;font:600 14px DM Sans,sans-serif;cursor:pointer;transition:all .2s ease}.btn-policy-cancel:hover{background:#f7f1e7;border-color:#7b4a3a}#verification-busy-overlay:not([hidden]){position:fixed;inset:0;z-index:200000;display:grid;place-items:center;background:rgba(30,22,18,.64);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);color:#fff7f2;font:700 1rem DM Sans,sans-serif;cursor:progress}#verification-busy-overlay span{padding:20px 28px;border-radius:14px;background:#5c4538;border:1px solid #a8816d;box-shadow:0 16px 40px rgba(0,0,0,.3)}.hover\:bg-slate-50:hover{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity,1))}.hover\:bg-slate-900:hover{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}.admin-title-wrap h1,.admin-topbar h1,.rv-title h1,.rv-topbar h1,.topbar h1,h1{font-family:Fraunces,Georgia,Times New Roman,serif!important;font-weight:700!important;color:#5f3425;letter-spacing:-.6px}[data-theme=dark] .admin-title-wrap h1,[data-theme=dark] .admin-topbar h1,[data-theme=dark] .rv-title h1,[data-theme=dark] .rv-topbar h1,[data-theme=dark] .topbar h1,[data-theme=dark] h1{color:#fff7f2!important}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}.admin-shell,.shell{margin-left:277px;min-height:calc(100vh - 30px);height:auto;padding:0 54px 30px;background:linear-gradient(180deg,hsla(36,71%,97%,.98),hsla(36,56%,95%,.98));border:1px solid #ebd9cb;border-radius:22px;box-shadow:0 30px 60px rgba(92,62,48,.12);position:relative;display:flex;flex-direction:column}.admin-topbar,.topbar{border-bottom:1px solid #ebd9cb}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body,html[data-theme=dark] body{background:var(--bg)!important;color:var(--text)!important}[data-theme=dark] .admin-shell,[data-theme=dark] .rv-shell,[data-theme=dark] .shell,html[data-theme=dark] body .admin-shell,html[data-theme=dark] body .rv-shell,html[data-theme=dark] body .shell,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page,.admin-dashboard-page,.settings-page,.admin-page) .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}[data-theme=dark] .admin-sidebar,[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#4a372c,#3a2820)!important;border-right:1px solid #553e32!important}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc!important}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08)!important;color:#fff7f2!important}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14)!important;color:#d9c8bc!important;font-weight:700!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .rv-topbar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar,html[data-theme=dark] body .admin-shell>.admin-topbar,html[data-theme=dark] body .rv-shell>.rv-topbar,html[data-theme=dark] body .shell>.topbar,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page,.admin-dashboard-page,.settings-page,.admin-page) .topbar{background:transparent!important;border-bottom:1px solid #3d2d24!important;box-shadow:none!important}[data-theme=dark] .btn-theme-toggle{background:#2b1f19!important;border-color:#563e33!important;color:#fbf5f0!important}[data-theme=dark] .btn-theme-toggle:hover{background:#382921!important;border-color:var(--brown)!important;color:var(--brown)!important}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .landlords-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .mailer-card,[data-theme=dark] .occupancy-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .stat-card,[data-theme=dark] .subscriber-panel,[data-theme=dark] .tenant-card,[data-theme=dark] .tenants-table-card,[data-theme=dark] .users-main-card,[data-theme=dark] dialog,html[data-theme=dark] .admin-dashboard-page .landlords-booking-card,html[data-theme=dark] .admin-dashboard-page .occupancy-card,html[data-theme=dark] body.admin-users-page .tenants-table-card,html[data-theme=dark] body.admin-verification-page .tenant-card{background:#2b1f19!important;border:1px solid #563e33!important;color:var(--text,#e2d3c8)!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important}html[data-theme=dark] .admin-dashboard-page .donut-center,html[data-theme=dark] .admin-dashboard-page .landlord-avatar,html[data-theme=dark] .admin-dashboard-page .occupancy-kicker{background:#34261f!important;border-color:#453127!important}html[data-theme=dark] .admin-dashboard-page .occupancy-card-head{border-bottom-color:#453127!important}html[data-theme=dark] .admin-dashboard-page .landlord-row:hover{background:#34261f!important}[data-theme=dark] table th,html[data-theme=dark] body.admin-users-page .tenants-table th,html[data-theme=dark] table.data th{background:#231a14!important;color:#d9c8bc!important;border-bottom:1px solid #4b382d!important}[data-theme=dark] table td,html[data-theme=dark] body.admin-users-page .tenants-table td,html[data-theme=dark] table.data td{color:#e2d3c8!important;border-bottom:1px solid #4b382d!important}[data-theme=dark] table tbody tr:hover,html[data-theme=dark] body.admin-users-page .tenants-table tbody tr:hover,html[data-theme=dark] table.data tbody tr:hover{background:#34261f!important}[data-theme=dark] table td strong,html[data-theme=dark] body.admin-users-page .tenants-table td strong{color:#fff7f2!important}[data-theme=dark] .form-input,[data-theme=dark] .form-textarea,[data-theme=dark] .um-search,[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea,html[data-theme=dark] body.admin-verification-page .um-select-btn,html[data-theme=dark] body.admin-verification-page .um-select-menu,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search{background:#231a14!important;color:var(--ink,#fff7f2)!important;border-color:#4b382d!important}[data-theme=dark] .um-search input::-moz-placeholder,[data-theme=dark] input::-moz-placeholder,[data-theme=dark] textarea::-moz-placeholder{color:#8a6c5d!important}[data-theme=dark] .um-search input::placeholder,[data-theme=dark] input::placeholder,[data-theme=dark] textarea::placeholder{color:#8a6c5d!important}[data-theme=dark] .um-select-menu li{color:#e2d3c8!important}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f!important;color:#fff7f2!important}[data-theme=dark] .settings-tabs,[data-theme=dark] .topbar-categories-bar,html[data-theme=dark] body.admin-verification-page .settings-tabs,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-categories-bar{background:#241a15!important;border-color:#4b382d!important}[data-theme=dark] .settings-tab-btn,[data-theme=dark] .topbar-cat-tab,html[data-theme=dark] body.admin-verification-page .settings-tab-btn,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab{color:#bba89d!important}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .topbar-cat-tab.is-active,[data-theme=dark] .topbar-cat-tab:hover,html[data-theme=dark] body.admin-verification-page .settings-tab-btn.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab:hover{color:#fff7f2!important}[data-theme=dark] .settings-tabs-indicator,[data-theme=dark] .topbar-cat-indicator,html[data-theme=dark] body.admin-verification-page .settings-tabs-indicator,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-indicator{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 2px 8px rgba(0,0,0,.35)!important}[data-theme=dark] .confirm-modal-overlay,[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .policy-modal-backdrop,[data-theme=dark] dialog::backdrop,html[data-theme=dark] .confirm-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal-backdrop,html[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82)!important;-webkit-backdrop-filter:blur(4px)!important;backdrop-filter:blur(4px)!important}html[data-theme=dark] #notification-popover{background:#2b1f19!important;border-color:#4b382d!important;color:#e2d3c8!important;box-shadow:0 18px 42px rgba(0,0,0,.5)!important}html[data-theme=dark] #notification-popover #popover-title,html[data-theme=dark] #notification-popover .empty strong,html[data-theme=dark] #notification-popover .notification-detail h3,html[data-theme=dark] #notification-popover .notification-entry strong,html[data-theme=dark] #notification-popover h2,html[data-theme=dark] #notification-popover strong{color:#fff7f2!important;background:transparent!important}html[data-theme=dark] #notification-popover .empty p,html[data-theme=dark] #notification-popover .notification-detail p,html[data-theme=dark] #notification-popover .notification-entry span,html[data-theme=dark] #notification-popover p,html[data-theme=dark] #notification-popover span{color:#d9c8bc!important;background:transparent!important}html[data-theme=dark] #notification-popover #view-all,html[data-theme=dark] #notification-popover .notification-entry small,html[data-theme=dark] #notification-popover .view-all,html[data-theme=dark] #notification-popover small{color:#c48974!important;background:transparent!important}html[data-theme=dark] #notification-popover .empty,html[data-theme=dark] #notification-popover .notification-actions,html[data-theme=dark] #notification-popover .notification-entry,html[data-theme=dark] #notification-popover .notification-list,html[data-theme=dark] #notification-popover .notification-row{background:#2b1f19!important;border-color:#4b382d!important}html[data-theme=dark] #notification-popover .notification-entry.is-unread{background:#34261f!important}html[data-theme=dark] #notification-popover .notification-entry:hover,html[data-theme=dark] #notification-popover .notification-row:hover{background:#3d2d24!important}#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.toast{width:min(400px,calc(100vw - 32px))!important;min-height:108px!important;box-sizing:border-box!important;border-radius:16px!important}
/* admin-tailwind:verification:end */
    .sidebar nav,.sidebar nav a,.sidebar .nav-label,.sidebar .nav-badge,.sidebar .nav-badge-pill,.sidebar-toggle,.sidebar .toggle-label{font-family:'DM Sans',-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}.sidebar .brand,.sidebar .brand-name{font-family:'Fraunces',Georgia,serif!important}</style>
    <link rel="stylesheet" href="../assets/css/admin-dark-refinements.css?v=4">
    <script src="../assets/js/theme.js"></script>
    <script>
        function showDocumentUnavailable(image) {
            const box = image.closest('.verif-doc-box');
            const thumb = image.parentElement;
            thumb.removeAttribute('onclick');
            thumb.textContent = 'Photo unavailable';
            if (box) {
                box.classList.add('is-missing');
                const label = box.querySelector('.doc-details span');
                if (label) label.textContent = 'Photo unavailable';
                const inspect = box.querySelector('.btn-inspect');
                if (inspect) inspect.hidden = true;
            }
        }
    </script>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js?v=11"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=33"></script>
    <script src="../assets/js/confirm-modal.js?v=1"></script>
    <script>
        window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id'=>(string)$adminProfile['id'],'role'=>'admin']) ?>;
        window.UNISTAY_TENANT_PROFILE = <?= json_encode([
            'name' => $adminProfile['name'],
            'role' => 'Administrator',
            'photo' => $adminProfile['photo'] ?? '',
            'initials' => $adminInitials,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        window.ADMIN_VERIF_CSRF = <?= json_encode($_SESSION['admin_verification_csrf']) ?>;
    </script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/notification-global.js?v=1"></script>
    <script defer src="../assets/js/admin-notifications.js?v=1"></script>
    
</head>
<body class="admin-verification-page admin-page">
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="mail-icon" viewBox="0 0 24 24"><path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="2"/><path d="m3 6 9 7 9-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
        <symbol id="caret-icon" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <a class="skip" href="#verification-main">Skip to verification</a>

    <!-- Admin Sidebar Navigation (4-Item Navigation) -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <a href="dashboard.php" class="brand" title="UniStay Admin Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification" aria-current="page"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="shell">
        <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
        <!-- Topbar: Main row + Full-Width Category Navigation stretched across entire horizontal line -->
        <header class="topbar">
            <div class="topbar-main-row">
                <h1>Verification</h1>
                <div class="profile">
                    <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme"></button>
                    <button
                        id="notification-bell"
                        class="bell"
                        type="button"
                        aria-label="Notifications"
                        aria-expanded="false"
                        aria-controls="notification-popover"
                    >
                        <svg aria-hidden="true"><use href="#bell-icon" /></svg>
                    </button>
                    <button
                        class="avatar"
                        type="button"
                        aria-label="<?= htmlspecialchars($adminProfile['name'], ENT_QUOTES, 'UTF-8') ?> — open profile menu"
                        aria-expanded="false"
                        aria-controls="profile-popover"
                        <?php if (!empty($adminProfile['photo'])): ?>style="background-image:url('<?= htmlspecialchars($adminProfile['photo'], ENT_QUOTES, 'UTF-8') ?>');"<?php endif; ?>
                    >
                        <?php if (!empty($adminProfile['photo'])): ?>
                            <img src="<?= htmlspecialchars($adminProfile['photo']) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                            <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars($adminInitials) ?>
                        <?php endif; ?>
                    </button>

                    <!-- Notification Dropdown Popover -->
                    <section
                        id="notification-popover"
                        class="popover"
                        aria-labelledby="popover-title"
                        hidden
                    >
                        <h2 id="popover-title">Notifications</h2>
                        <div class="empty" id="popover-empty">
                            <span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon" /></svg></span>
                            <strong>No new notifications</strong>
                            <p>We’ll let you know when new submissions arrive.</p>
                        </div>
                        <ul
                            class="notification-list"
                            id="popover-list"
                            aria-label="Unread notifications"
                            hidden
                        ></ul>
                        <a
                            class="view-all"
                            id="view-all"
                            href="notifications.php"
                            aria-label="View all notifications"
                        >
                            View all notifications
                            <svg aria-hidden="true"><use href="#arrow-icon" /></svg>
                        </a>
                    </section>
                </div>
            </div>

            <!-- Verification categories -->
            <nav class="topbar-categories-bar" aria-label="Verification sections">
                <span class="topbar-cat-indicator" aria-hidden="true"></span>
                <a href="?role=tenant" class="topbar-cat-tab <?= (!$applicationView && !$propertyView && $role === 'tenant') ? 'is-active' : '' ?>">
                    <span>Student Tenants</span>
                    <?php if ($tenantCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $tenantCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="?view=properties" class="topbar-cat-tab <?= $propertyView ? 'is-active' : '' ?>">
                    <span>Dorm Listings</span>
                    <?php if ($propertyCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $propertyCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
            </nav>
        </header>

        <main id="verification-main" tabindex="-1">

        <!-- Controls Row: Standard Unified Search Bar & Status Dropdown matching tenant/dorms.php -->
        <div class="um-toolbar">
            <div class="um-toolbar-left">
                <label class="um-search">
                    <svg aria-hidden="true"><use href="#search-icon"/></svg>
                    <input id="verif-search" type="search" placeholder="Search records..." autocomplete="off">
                </label>
                <div class="um-select" id="verif-status-dropdown">
                    <button type="button" class="um-select-btn" id="verif-select-btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="um-select-value" id="verif-select-value">
                            <?php if ($propertyView): ?>
                                <?= $propertyFilter === 'pending' ? 'Pending (' . $propertyCounts['pending'] . ')' :
                                   ($propertyFilter === 'approved' ? 'Approved (' . $propertyCounts['approved'] . ')' :
                                   (($propertyFilter === 'declined' || $propertyFilter === 'rejected') ? 'Declined (' . ($propertyCounts['declined'] ?? $propertyCounts['rejected']) . ')' : 'All Statuses (' . $propertyCounts['all'] . ')')) ?>
                            <?php elseif ($applicationView): ?>
                                <?= $applicationFilter === 'pending' ? 'Pending (' . $applicationCounts['pending'] . ')' :
                                   ($applicationFilter === 'approved' ? 'Approved (' . $applicationCounts['approved'] . ')' :
                                   ($applicationFilter === 'declined' ? 'Declined (' . $applicationCounts['declined'] . ')' : 'All Statuses (' . $applicationCounts['all'] . ')')) ?>
                            <?php else: ?>
                                <?= $filter === 'pending' ? 'Pending (' . $activeCounts['pending'] . ')' :
                                   (($filter === 'verified' || $filter === 'approved') ? 'Approved (' . $activeCounts['verified'] . ')' :
                                   (($filter === 'declined' || $filter === 'rejected') ? 'Declined (' . ($activeCounts['declined'] ?? $activeCounts['rejected']) . ')' : 'All Statuses (' . $activeCounts['all'] . ')')) ?>
                            <?php endif; ?>
                        </span>
                        <svg class="um-select-caret" aria-hidden="true"><use href="#caret-icon"/></svg>
                    </button>
                    <ul class="um-select-menu" id="verif-select-menu" role="listbox" aria-label="Filter records by status" hidden>
                        <?php if ($propertyView): ?>
                            <li role="option" data-value="all" aria-selected="<?= $propertyFilter === 'all' ? 'true' : 'false' ?>">All Statuses (<?= $propertyCounts['all'] ?>)</li>
                            <li role="option" data-value="pending" aria-selected="<?= $propertyFilter === 'pending' ? 'true' : 'false' ?>">Pending (<?= $propertyCounts['pending'] ?>)</li>
                            <li role="option" data-value="approved" aria-selected="<?= $propertyFilter === 'approved' ? 'true' : 'false' ?>">Approved (<?= $propertyCounts['approved'] ?>)</li>
                            <li role="option" data-value="declined" aria-selected="<?= ($propertyFilter === 'declined' || $propertyFilter === 'rejected') ? 'true' : 'false' ?>">Declined (<?= $propertyCounts['declined'] ?? $propertyCounts['rejected'] ?>)</li>
                        <?php elseif ($applicationView): ?>
                            <li role="option" data-value="all" aria-selected="<?= $applicationFilter === 'all' ? 'true' : 'false' ?>">All Statuses (<?= $applicationCounts['all'] ?>)</li>
                            <li role="option" data-value="pending" aria-selected="<?= $applicationFilter === 'pending' ? 'true' : 'false' ?>">Pending (<?= $applicationCounts['pending'] ?>)</li>
                            <li role="option" data-value="approved" aria-selected="<?= $applicationFilter === 'approved' ? 'true' : 'false' ?>">Approved (<?= $applicationCounts['approved'] ?>)</li>
                            <li role="option" data-value="declined" aria-selected="<?= $applicationFilter === 'declined' ? 'true' : 'false' ?>">Declined (<?= $applicationCounts['declined'] ?>)</li>
                        <?php else: ?>
                            <li role="option" data-value="all" aria-selected="<?= $filter === 'all' ? 'true' : 'false' ?>">All Statuses (<?= $activeCounts['all'] ?>)</li>
                            <li role="option" data-value="pending" aria-selected="<?= $filter === 'pending' ? 'true' : 'false' ?>">Pending (<?= $activeCounts['pending'] ?>)</li>
                            <li role="option" data-value="verified" aria-selected="<?= ($filter === 'verified' || $filter === 'approved') ? 'true' : 'false' ?>">Approved (<?= $activeCounts['verified'] ?>)</li>
                            <li role="option" data-value="declined" aria-selected="<?= ($filter === 'rejected' || $filter === 'declined') ? 'true' : 'false' ?>">Declined (<?= $activeCounts['declined'] ?? $activeCounts['rejected'] ?>)</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="bulk-actions" aria-label="Bulk verification actions">
                    <form method="POST" data-confirm-name="all pending <?= htmlspecialchars($bulkCategoryLabel, ENT_QUOTES, 'UTF-8') ?>" data-confirm-text="Approve all <?= number_format($bulkPendingCount) ?> pending <?= htmlspecialchars($bulkCategoryLabel, ENT_QUOTES, 'UTF-8') ?>?">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="role" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="bulk_approve">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-bulk-action btn-bulk-approve" <?= $bulkPendingCount === 0 ? 'disabled' : '' ?>>Approve All</button>
                    </form>
                    <button
                        type="button"
                        class="btn-bulk-action btn-bulk-decline"
                        id="bulkDeclineButton"
                        data-pending-count="<?= $bulkPendingCount ?>"
                        <?= $bulkPendingCount === 0 ? 'disabled' : '' ?>
                    >Decline All</button>
                </div>
            </div>
        </div>

        <!-- Application Alerts if applicable -->
        <?php if ($applicationView): ?>
            <?php if (!mail_is_configured()): ?>
                <div style="margin-bottom:18px;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:14px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                    <span><strong>Admin email is not configured.</strong> Applications still create an in-app alert, but Gmail delivery needs a sending address and app password.</span>
                    <a href="../tools/mail-settings.php" style="color:#7a4b3a;font-weight:700;">Open Mail Settings</a>
                </div>
            <?php endif; ?>
            <?php if ($invitationUrl !== ''): ?>
                <div class="notice-banner is-success" style="margin-bottom:18px;word-break:break-all;background:#e5f4e8;color:#245c31;border:1px solid #b9d8bf;border-radius:14px;padding:16px 18px;">
                    <strong style="display:block;margin-bottom:8px;font-size:1rem;">One-time landlord invitation generated:</strong>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" readonly value="<?= htmlspecialchars($invitationUrl, ENT_QUOTES, 'UTF-8') ?>" style="flex:1;min-width:0;padding:9px 12px;border:1px solid #b9d8bf;border-radius:8px;background:#fff;font:inherit;">
                        <button type="button" class="btn-inspect" onclick="const input=this.previousElementSibling;input.select();navigator.clipboard?navigator.clipboard.writeText(input.value):document.execCommand('copy');this.textContent='Copied';">Copy invitation</button>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Empty State container (displayed dynamically or statically when no cards match) -->
        <div class="verif-empty" id="verifEmptyState" style="display:none;">
            <span class="verif-empty-icon">&#128269;</span>
            <h3 style="margin:0 0 6px;color:#292524;font-family:'Fraunces',Georgia,serif;font-size:20px;">No verification records found</h3>
            <p style="margin:0;font-size:0.92rem;">Try picking another status tab or entering a different search term.</p>
        </div>

        <!-- Horizontal Cards (Styled EXACTLY like landlord/requests.php) -->
        <?php if ($propertyView): ?>
            <?php if (empty($properties)): ?>
                <div class="verif-empty" style="display:flex;">
                    <span class="verif-empty-icon">&#127970;</span>
                    <h3 style="margin:0 0 6px;color:#292524;font-family:'Fraunces',Georgia,serif;font-size:20px;">No property listings found</h3>
                    <p style="margin:0;font-size:0.92rem;">There are no property listings currently in the system.</p>
                </div>
            <?php else: ?>
                <div class="verif-cards-list" id="verifCardsList">
                <?php foreach ($properties as $prop):
                    $propId = (int)$prop['id'];
                    $propName = (string)$prop['name'];
                    $landlordFullName = trim(($prop['first_name'] ?? '') . ' ' . ($prop['last_name'] ?? ''));
                    if ($landlordFullName === '') $landlordFullName = (string)($prop['personal_email'] ?? 'Landlord');
                    $status = (string)$prop['approval_status'];
                    $proofPath = (string)($prop['proof_of_ownership'] ?? '');
                    $proofUrl = $proofPath !== '' ? '../' . ltrim($proofPath, '/') : '';
                    $permitPath = (string)($prop['business_permit'] ?? '');
                    $permitUrl = $permitPath !== '' ? '../' . ltrim($permitPath, '/') : '';
                    
                    // Photos
                    $photos = json_decode((string)($prop['photo'] ?? '[]'), true);
                    if (!is_array($photos)) $photos = [];
                    $coverPhoto = !empty($photos[0]) ? '../' . ltrim($photos[0], '/') : '../assets/images/tenant/hero-1.webp';
                    
                    $statusLabel = ($status === 'approved' ? 'Approved' : ($status === 'declined' || $status === 'rejected' ? 'Declined' : 'Pending'));
                    $badgeClass = ($status === 'approved' ? 'approved' : ($status === 'declined' || $status === 'rejected' ? 'declined' : 'pending'));

                    $recordData = [
                        'type' => 'property',
                        'id' => $propId,
                        'name' => $propName,
                        'coverPhoto' => $coverPhoto,
                        'status' => $status,
                        'statusLabel' => $statusLabel,
                        'badgeClass' => $badgeClass,
                        'university' => (string)($prop['nearest_uni'] ?? $prop['university'] ?? 'University'),
                        'nearest_uni' => (string)($prop['nearest_uni'] ?? $prop['university'] ?? 'University'),
                        'address' => (string)($prop['address'] ?? 'No address provided'),
                        'roomCount' => (int)$prop['room_count'],
                        'totalSlots' => (int)$prop['total_slots'],
                        'landlordName' => $landlordFullName,
                        'landlordEmail' => (string)($prop['email'] ?? ($prop['personal_email'] ?? '')),
                        'landlordPhone' => (string)($prop['phone_number'] ?? 'No phone'),
                        'description' => (string)($prop['description'] ?? ''),
                        'submittedAt' => !empty($prop['created_at']) ? date('M d, Y', strtotime($prop['created_at'])) : 'Recent',
                        'proofUrl' => $proofUrl,
                        'proofName' => $proofPath !== '' ? basename($proofPath) : '',
                        'proofHas' => ($proofUrl !== ''),
                        'permitUrl' => $permitUrl,
                        'permitName' => $permitPath !== '' ? basename($permitPath) : '',
                        'permitHas' => ($permitUrl !== ''),
                    ];
                    $searchTerms = strtolower($propName . ' ' . $landlordFullName . ' ' . ($prop['email'] ?? ($prop['personal_email'] ?? '')) . ' ' . ($prop['university'] ?? '') . ' ' . ($prop['address'] ?? ''));
                    $isInitVisible = ($propertyFilter === 'all' || $status === $propertyFilter);
                ?>
                    <article class="tenant-card" data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchTerms, ENT_QUOTES, 'UTF-8') ?>" data-record='<?= json_encode($recordData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' style="<?= $isInitVisible ? '' : 'display:none;' ?>">
                        <div class="tenant-avatar" aria-hidden="true">
                            <img src="<?= htmlspecialchars($coverPhoto) ?>" alt="" onerror="this.style.display='none';this.parentElement.textContent='P';">
                        </div>

                        <div class="tenant-main">
                            <div class="tenant-head">
                                <h3><?= htmlspecialchars($propName) ?></h3>
                                <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                            </div>

                            <p class="tenant-sub">
                                <span>📍 <?= htmlspecialchars($prop['university'] ?? 'University') ?></span>
                                <span class="dot">&bull;</span>
                                <span>Property #<?= $propId ?></span>
                                <span class="dot">&bull;</span>
                                <span><?= htmlspecialchars($prop['address'] ?? '') ?></span>
                            </p>

                            <div class="tenant-meta">
                                <div class="meta">
                                    <span class="meta-label">Near University</span>
                                    <span class="meta-value"><?= htmlspecialchars($prop['university'] ?? 'University') ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Capacity</span>
                                    <span class="meta-value"><?= (int)$prop['room_count'] ?> Rooms (<?= (int)$prop['total_slots'] ?> Beds)</span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Landlord</span>
                                    <span class="meta-value"><?= htmlspecialchars($landlordFullName) ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Submitted On</span>
                                    <span class="meta-value"><?= !empty($prop['created_at']) ? date('M d, Y', strtotime($prop['created_at'])) : 'Recent' ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="tenant-actions">
                            <?php if ($status === 'approved'): ?>
                                <button type="button" class="btn-card-approve" disabled>Approved</button>
                            <?php elseif ($status === 'declined' || $status === 'rejected'): ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="listing <?= htmlspecialchars($propName) ?>" data-confirm-text="Approve listing <?= htmlspecialchars(addslashes($propName)) ?>? It will become discoverable by students.">
                                    <input type="hidden" name="view" value="properties">
                                    <input type="hidden" name="action" value="approve_property">
                                    <input type="hidden" name="property_id" value="<?= $propId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" disabled>Declined</button>
                            <?php else: ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="listing <?= htmlspecialchars($propName) ?>" data-confirm-text="Approve listing <?= htmlspecialchars(addslashes($propName)) ?>? It will become discoverable by students.">
                                    <input type="hidden" name="view" value="properties">
                                    <input type="hidden" name="action" value="approve_property">
                                    <input type="hidden" name="property_id" value="<?= $propId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" onclick='openDeclineModal({type:"property",id:<?= json_encode($propId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,name:<?= json_encode($propName, JSON_HEX_APOS | JSON_HEX_QUOT) ?>})'>Decline</button>
                            <?php endif; ?>
                            <button type="button" class="btn-booking-details view-details-btn">View details</button>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($applicationView): ?>
            <?php if (empty($applications)): ?>
                <div class="verif-empty" style="display:flex;">
                    <span class="verif-empty-icon">&#128203;</span>
                    <h3 style="margin:0 0 6px;color:#292524;font-family:'Fraunces',Georgia,serif;font-size:20px;">No landlord applications found</h3>
                    <p style="margin:0;font-size:0.92rem;">There are no landlord applications in the system.</p>
                </div>
            <?php else: ?>
                <div class="verif-cards-list" id="verifCardsList">
                <?php foreach ($applications as $application):
                    $appId = (string)$application['id'];
                    $applicationName = trim(($application['first_name'] ?? '') . ' ' . ($application['middle_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
                    if ($applicationName === '') $applicationName = (string)($application['full_name'] ?? $application['email']);
                    $applicationStatus = (string)$application['status'];
                    $idPath = (string)($application['government_id_file'] ?? '');
                    $documentUrl = $idPath !== '' ? 'application-document.php?id=' . rawurlencode($appId) : '';
                    $emailStatus = (string)($application['admin_email_status'] ?? 'unconfigured');

                    $statusLabel = ucfirst($applicationStatus);
                    $badgeClass = ($applicationStatus === 'approved' ? 'approved' : ($applicationStatus === 'declined' ? 'declined' : 'pending'));

                    $initials = strtoupper(substr($applicationName, 0, 1));
                    $avatarUrl = resolve_user_profile_photo_url(
                        $application['user_profile_photo'] ?? null,
                        $application['user_photo'] ?? null,
                        (string)($application['user_role'] ?? 'landlord')
                    );
                    if ($avatarUrl === '' && !empty($application['email']) && filter_var($application['email'], FILTER_VALIDATE_EMAIL)) {
                        $avatarUrl = 'https://unavatar.io/' . rawurlencode(strtolower(trim($application['email']))) . '?fallback=false';
                    }

                    $recordData = [
                        'type' => 'application',
                        'id' => $appId,
                        'name' => $applicationName,
                        'photo' => $avatarUrl,
                        'initials' => $initials,
                        'status' => $applicationStatus,
                        'statusLabel' => $statusLabel,
                        'badgeClass' => $badgeClass,
                        'email' => (string)$application['email'],
                        'phone' => (string)$application['phone'],
                        'address' => (string)($application['residential_address'] ?? 'No address provided'),
                        'experience' => (string)($application['experience_summary'] ?? ''),
                        'adminEmailStatus' => $emailStatus,
                        'adminEmailSentAt' => !empty($application['admin_email_sent_at']) ? date('M d, Y g:i A', strtotime($application['admin_email_sent_at'])) : '',
                        'decisionNote' => (string)($application['feedback'] ?? $application['decision_note'] ?? ''),
                        'approvedBy' => (string)($application['approved_by'] ?? ''),
                        'submittedAt' => !empty($application['created_at']) ? date('M d, Y', strtotime($application['created_at'])) : 'Recent',
                        'registeredAt' => (string)($application['registered_at'] ?? ''),
                        'docUrl' => $documentUrl,
                        'docName' => $idPath !== '' ? basename($idPath) : '',
                        'docHas' => ($documentUrl !== ''),
                    ];
                    $searchTerms = strtolower($applicationName . ' ' . ($application['email'] ?? '') . ' ' . ($application['phone'] ?? '') . ' ' . ($application['residential_address'] ?? ''));
                    $isInitVisible = ($applicationFilter === 'all' || $applicationStatus === $applicationFilter);
                ?>
                    <article class="tenant-card" data-status="<?= htmlspecialchars($applicationStatus, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchTerms, ENT_QUOTES, 'UTF-8') ?>" data-record='<?= json_encode($recordData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' style="<?= $isInitVisible ? '' : 'display:none;' ?>">
                        <div class="tenant-avatar" aria-hidden="true">
                            <?php if (!empty($avatarUrl)): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="" onerror="this.style.display='none';this.parentElement.textContent='<?= htmlspecialchars($initials) ?>';">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>

                        <div class="tenant-main">
                            <div class="tenant-head">
                                <h3><?= htmlspecialchars($applicationName) ?><?php if ($applicationStatus === 'approved'): ?><span class="verified-check" role="img" aria-label="Verified" title="Verified"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span><?php endif; ?></h3>
                                <?php if ($applicationStatus !== 'approved'): ?>
                                <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                <?php endif; ?>
                            </div>

                            <p class="tenant-sub">
                                <span>🏠 Landlord Applicant</span>
                                <span class="dot">&bull;</span>
                                <span><?= htmlspecialchars($application['email']) ?></span>
                                <span class="dot">&bull;</span>
                                <span><?= htmlspecialchars($application['phone']) ?></span>
                            </p>

                            <div class="tenant-meta">
                                <div class="meta">
                                    <span class="meta-label">Email Address</span>
                                    <span class="meta-value"><?= htmlspecialchars($application['email']) ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Phone Number</span>
                                    <span class="meta-value"><?= htmlspecialchars($application['phone']) ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Residential Address</span>
                                    <span class="meta-value"><?= htmlspecialchars($application['residential_address'] ?? 'Not specified') ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-label">Admin Email</span>
                                    <span class="meta-value"><?= ucfirst($emailStatus) ?><?= !empty($application['admin_email_sent_at']) ? ' (' . date('M d', strtotime($application['admin_email_sent_at'])) . ')' : '' ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="tenant-actions">
                            <?php if ($applicationStatus === 'approved'): ?>
                                <?php // Verified status is shown by the green check beside the name. ?>
                            <?php elseif ($applicationStatus === 'declined'): ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="application from <?= htmlspecialchars($applicationName) ?>" data-confirm-text="Approve application from <?= htmlspecialchars(addslashes($applicationName)) ?>?">
                                    <input type="hidden" name="view" value="applications">
                                    <input type="hidden" name="action" value="approve_application">
                                    <input type="hidden" name="application_id" value="<?= htmlspecialchars($appId, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" disabled>Declined</button>
                            <?php else: ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="application from <?= htmlspecialchars($applicationName) ?>" data-confirm-text="Approve application from <?= htmlspecialchars(addslashes($applicationName)) ?>?">
                                    <input type="hidden" name="view" value="applications">
                                    <input type="hidden" name="action" value="approve_application">
                                    <input type="hidden" name="application_id" value="<?= htmlspecialchars($appId, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" onclick='openDeclineModal({type:"application",id:<?= json_encode($appId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,name:<?= json_encode($applicationName, JSON_HEX_APOS | JSON_HEX_QUOT) ?>})'>Decline</button>
                            <?php endif; ?>
                            <button type="button" class="btn-booking-details view-details-btn">View details</button>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <?php if (empty($requests)): ?>
                <div class="verif-empty" style="display:flex;">
                    <span class="verif-empty-icon">&#128737;&#65039;</span>
                    <h3 style="margin:0 0 6px; color:#292524; font-family:'Fraunces',Georgia,serif; font-size:20px;">No <?= htmlspecialchars($role) ?> verification requests found</h3>
                    <p style="margin:0; font-size:0.92rem;">There are currently no <?= htmlspecialchars($role) ?> accounts in the system.</p>
                </div>
            <?php else: ?>
                <div class="verif-cards-list" id="verifCardsList">
                <?php foreach ($requests as $item): 
                    $userId = (string)$item['id'];
                    $name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                    if ($name === '') $name = (string)($item['email'] ?? ($item['personal_email'] ?? ucfirst($role)));
                    $initials = strtoupper(substr($name, 0, 1));
                    $st = $item['verification_status'] ?? 'unverified';
                    
                    if ($role === 'landlord') {
                        $doc1Title = 'Government ID';
                        $doc1Path = $item['gov_id_file'] ?? '';
                        $doc2Title = '';
                        $doc2Path = '';
                    } else {
                        $doc1Title = 'Student / School ID';
                        $doc1Path = $item['school_id'] ?? $item['school_id_file'] ?? '';
                        $doc2Title = 'Government ID';
                        $doc2Path = $item['gov_id'] ?? $item['gov_id_file'] ?? '';
                    }

                    $webDoc1 = resolve_doc_web_url($doc1Path, $role);
                    $webDoc2 = resolve_doc_web_url($doc2Path, $role);
                    $hasDoc1 = ($webDoc1 !== '');
                    $hasDoc2 = ($webDoc2 !== '');

                    $avatarUrl = resolve_user_profile_photo_url(
                        $item['profile_photo'] ?? null,
                        $item['photo'] ?? null,
                        $role
                    );

                    $statusLabel = (in_array($st, ['verified', 'approved'], true) ? 'Approved' : (in_array($st, ['declined', 'rejected'], true) ? 'Declined' : ($st === 'pending' ? 'Pending' : 'Incomplete')));
                    $badgeClass = (in_array($st, ['verified', 'approved'], true) ? 'approved' : (in_array($st, ['declined', 'rejected'], true) ? 'declined' : ($st === 'pending' ? 'pending' : 'unverified')));

                    $recordData = [
                        'type' => $role,
                        'id' => $userId,
                        'name' => $name,
                        'photo' => $avatarUrl,
                        'initials' => $initials,
                        'status' => $st,
                        'statusLabel' => $statusLabel,
                        'badgeClass' => $badgeClass,
                        'email' => (string)($item['email'] ?? ($item['personal_email'] ?? 'No email')),
                        'phone' => (string)($item['phone_number'] ?? 'No phone'),
                        'school' => (string)($item['school'] ?? ''),
                        'emergencyName' => (string)($item['emergency_name'] ?? ''),
                        'emergencyRelationship' => (string)($item['emergency_relationship'] ?? ''),
                        'emergencyPhone' => (string)($item['emergency_phone'] ?? ''),
                        'facebook' => (string)($item['facebook_link'] ?? ''),
                        'verifiedAt' => !empty($item['verified_at']) ? date('M d, Y', strtotime($item['verified_at'])) : '',
                        'verificationNotes' => (string)($item['feedback'] ?? $item['verification_notes'] ?? ''),
                        'doc1Title' => $doc1Title,
                        'doc1Url' => $webDoc1,
                        'doc1Name' => $hasDoc1 ? basename($doc1Path) : '',
                        'doc1Has' => $hasDoc1,
                        'doc2Title' => $doc2Title,
                        'doc2Url' => $webDoc2,
                        'doc2Name' => $hasDoc2 ? basename($doc2Path) : '',
                        'doc2Has' => $hasDoc2,
                    ];
                    $searchTerms = strtolower($name . ' ' . ($item['email'] ?? ($item['personal_email'] ?? '')) . ' ' . ($item['phone_number'] ?? '') . ' ' . ($item['school'] ?? ''));
                    $isInitVisible = ($filter === 'all' || $st === $filter || (($filter === 'approved' || $filter === 'verified') && in_array($st, ['verified', 'approved'], true)) || (in_array($filter, ['declined', 'rejected'], true) && in_array($st, ['declined', 'rejected'], true)));
                ?>
                    <article class="tenant-card" data-status="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchTerms, ENT_QUOTES, 'UTF-8') ?>" data-record='<?= json_encode($recordData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' style="<?= $isInitVisible ? '' : 'display:none;' ?>">
                        <div class="tenant-avatar" aria-hidden="true">
                            <?php if (!empty($avatarUrl)): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="" onerror="this.style.display='none';this.parentElement.textContent='<?= htmlspecialchars($initials) ?>';">
                            <?php else: ?>
                                <?= htmlspecialchars($initials) ?>
                            <?php endif; ?>
                        </div>

                        <div class="tenant-main">
                            <div class="tenant-head">
                                <h3><?= htmlspecialchars($name) ?><?php if (in_array($st, ['verified', 'approved'], true)): ?><span class="verified-check" role="img" aria-label="Verified" title="Verified"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span><?php endif; ?></h3>
                                <?php if (!in_array($st, ['verified', 'approved'], true)): ?>
                                <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                <?php endif; ?>
                            </div>

                            <p class="tenant-sub">
                                <?php if ($role === 'tenant'): ?>
                                    <span>🎓 Student Tenant</span>
                                    <span class="dot">&bull;</span>
                                    <span><?= htmlspecialchars($item['school'] ?? 'University Student') ?></span>
                                    <span class="dot">&bull;</span>
                                    <span><?= htmlspecialchars($item['email'] ?? ($item['personal_email'] ?? '')) ?></span>
                                <?php else: ?>
                                    <span>🏠 Dorm Landlord</span>
                                    <?php if (!empty($item['facebook_link'])): ?>
                                        <span class="dot">&bull;</span>
                                        <span>🔗 Facebook Linked</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>

                            <div class="tenant-meta">
                                <?php if ($role === 'tenant'): ?>
                                    <div class="meta">
                                        <span class="meta-label">College / School</span>
                                        <span class="meta-value"><?= htmlspecialchars($item['school'] ?? 'Not specified') ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Email Address</span>
                                        <span class="meta-value"><?= htmlspecialchars($item['email'] ?? ($item['personal_email'] ?? 'No email')) ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Phone Number</span>
                                        <span class="meta-value"><?= htmlspecialchars($item['phone_number'] ?? 'No phone') ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Guardian / Emergency</span>
                                        <span class="meta-value"><?= !empty($item['emergency_name']) ? htmlspecialchars($item['emergency_name'] . ' (' . ($item['emergency_relationship'] ?? 'Guardian') . ')') : 'Not specified' ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="meta">
                                        <span class="meta-label">Email Address</span>
                                        <span class="meta-value"><?= htmlspecialchars($item['email'] ?? ($item['personal_email'] ?? 'No email')) ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Phone Number</span>
                                        <span class="meta-value"><?= htmlspecialchars($item['phone_number'] ?? 'No phone') ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Government ID</span>
                                        <span class="meta-value"><?= $hasDoc1 ? htmlspecialchars(basename($doc1Path)) : 'Not uploaded' ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label"><?= $st === 'verified' ? 'Verified Date' : 'Registration Date' ?></span>
                                        <span class="meta-value"><?= !empty($item['verified_at']) ? date('M d, Y', strtotime($item['verified_at'])) : (!empty($item['created_at']) ? date('M d, Y', strtotime($item['created_at'])) : 'Recent') ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tenant-actions">
                            <?php if ($st === 'verified' || $st === 'approved'): ?>
                                <?php // Verified status is shown by the green check beside the name. ?>
                            <?php elseif ($st === 'declined' || $st === 'rejected'): ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="<?= htmlspecialchars($name) ?>">
                                    <input type="hidden" name="action" value="approve_user">
                                    <input type="hidden" name="target_user_id" value="<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" disabled>Declined</button>
                            <?php else: ?>
                                <form method="POST" style="margin:0; width:100%;" data-confirm-name="<?= htmlspecialchars($name) ?>">
                                    <input type="hidden" name="action" value="approve_user">
                                    <input type="hidden" name="target_user_id" value="<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <button type="submit" class="btn-card-approve">Approve</button>
                                </form>
                                <button type="button" class="btn-card-decline" onclick='openDeclineModal({type:<?= json_encode($role, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,id:<?= json_encode($userId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,name:<?= json_encode($name, JSON_HEX_APOS | JSON_HEX_QUOT) ?>})'>Decline</button>
                            <?php endif; ?>
                            <button type="button" class="btn-booking-details view-details-btn">View details</button>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        </main>
    </div>

    <!-- SLIDE-OVER DRAWER -->
    <div class="drawer-overlay" id="drawerOverlay"></div>
    <aside class="drawer" id="verifDrawer" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
        <div class="drawer-header">
            <h2 id="drawerTitle">Verification Details</h2>
            <button type="button" class="drawer-close" id="drawerClose" aria-label="Close details">&#x2715;</button>
        </div>
        <div class="drawer-body" id="drawerBody">
            <!-- Rendered dynamically via JavaScript -->
        </div>
        <div class="drawer-footer" id="drawerFooter">
            <!-- Action buttons rendered dynamically based on status -->
        </div>
    </aside>

    <!-- System Result Modal -->
    <?php
    $actionResult = null;
    if ($successMessage !== '') {
        $actionResult = ['type' => 'success', 'title' => 'Verification Updated', 'message' => $successMessage];
    } elseif ($errorMessage !== '') {
        $actionResult = ['type' => 'error', 'title' => 'Action Failed', 'message' => $errorMessage];
    }
    if ($actionResult !== null):
        $isError = ($actionResult['type'] === 'error');
        $resultTitle = (string)$actionResult['title'];
        $resultMessage = (string)$actionResult['message'];
    ?>
    <div class="policy-modal" id="resultModal">
        <div class="policy-modal-backdrop" data-policy-close></div>
        <div class="policy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="resultModalTitle">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 id="resultModalTitle"><?= htmlspecialchars($resultTitle) ?></h2>
                </div>
                <button type="button" class="policy-modal-close" data-policy-close aria-label="Close modal">&times;</button>
            </header>
            <div class="policy-modal-body">
                <section class="policy-card">
                    <h2><?= $isError ? 'Something went wrong' : 'All done' ?></h2>
                    <p><?= htmlspecialchars($resultMessage) ?></p>
                </section>
            </div>
            <footer class="policy-modal-footer">
                <span class="policy-modal-footer-note">&copy; 2026 UniStay Legal Standards</span>
                <button type="button" class="btn-policy-confirm" data-policy-close>Understood</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>

    <!-- Confirm Approval Modal -->
    <div class="policy-modal" id="confirmModal" hidden>
        <div class="policy-modal-backdrop" data-confirm-close></div>
        <div class="policy-modal-panel" style="width:min(100%,520px);" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 id="confirmModalTitle">Approve Verification</h2>
                </div>
                <button type="button" class="policy-modal-close" data-confirm-close aria-label="Close modal">&times;</button>
            </header>
            <div class="policy-modal-body">
                <section class="policy-card">
                    <h2>Please confirm</h2>
                    <p id="confirmModalText">Approve verification for this account?</p>
                </section>
            </div>
            <footer class="policy-modal-footer">
                <button type="button" class="btn-policy-cancel" data-confirm-close>Cancel</button>
                <button type="button" class="btn-policy-confirm" id="confirmModalOk">Approve</button>
            </footer>
        </div>
    </div>

    <!-- Bulk Decline Modal -->
    <div class="policy-modal" id="bulkDeclineModal" hidden>
        <div class="policy-modal-backdrop" data-bulk-decline-close></div>
        <form method="POST" class="policy-modal-panel" style="width:min(100%,560px);" role="dialog" aria-modal="true" aria-labelledby="bulkDeclineTitle">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="role" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="bulk_decline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 id="bulkDeclineTitle">Decline All Pending Records</h2>
                </div>
                <button type="button" class="policy-modal-close" data-bulk-decline-close aria-label="Close modal">&times;</button>
            </header>
            <div class="policy-modal-body">
                <section class="policy-card">
                    <h2>Shared decline reason</h2>
                    <p>This feedback will be sent to all <?= number_format($bulkPendingCount) ?> pending <?= htmlspecialchars($bulkCategoryLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
                    <label for="bulkDeclineNotes" style="display:block;margin:16px 0 6px;font-weight:700;color:#5f3425;">Reason</label>
                    <textarea id="bulkDeclineNotes" name="rejection_notes" rows="5" maxlength="500" required style="width:100%;box-sizing:border-box;border:1px solid #d9bea6;border-radius:10px;padding:11px 12px;font:inherit;resize:vertical;" placeholder="Explain what needs to be corrected before resubmission."></textarea>
                </section>
            </div>
            <footer class="policy-modal-footer">
                <button type="button" class="btn-policy-cancel" data-bulk-decline-close>Cancel</button>
                <button type="submit" class="btn-policy-confirm" style="background:#dc2626;border-color:#dc2626;">Decline All</button>
            </footer>
        </form>
    </div>

    <!-- Document Inspection Modal -->
    <div id="inspectModal" class="inspect-modal" onclick="if(event.target===this)closeInspectModal()">
        <div class="inspect-box">
            <header class="inspect-header">
                <div>
                    <h3 id="inspectTitle" style="margin:0; color:#292524; font-size:1.15rem;">Review Document</h3>
                    <span id="inspectSub" style="font-size:0.85rem; color:#78665c;">Verify document authenticity, clarity, and account ownership.</span>
                </div>
                <button type="button" class="btn-inspect" onclick="closeInspectModal()">✕ Close</button>
            </header>
            <div class="inspect-body">
                <img id="inspectImg" class="inspect-img" src="" alt="Credential Full Resolution" onerror="this.hidden=true;document.getElementById('inspectUnavailable').hidden=false">
                <p id="inspectUnavailable" hidden role="status">Photo unavailable. The uploaded file could not be loaded.</p>
            </div>
            <footer class="inspect-footer">
                <div style="font-size:0.88rem; color:#78665c;">
                    UniStay Identity &amp; Property Verification
                </div>
                <div style="display:flex; gap:10px;">
                    <form id="inspectApproveForm" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="approve_user">
                        <input type="hidden" name="target_user_id" id="inspectApproveId" value="0">
                        <input type="hidden" name="target_role" id="inspectApproveRole" value="<?= htmlspecialchars($role) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                        <button type="submit" class="btn-approve" style="padding:7px 16px;">Approve</button>
                    </form>
                    <button type="button" class="btn-reject" id="inspectRejectBtn" style="padding:7px 16px;">Decline</button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div id="rejectModal" class="policy-modal" hidden>
        <div class="policy-modal-backdrop" data-decline-close></div>
        <div class="policy-modal-panel" style="width:min(100%,560px);">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 style="margin:0;">Decline Verification</h2>
                </div>
                <button type="button" class="btn-inspect" onclick="closeRejectModal()">✕</button>
                <button type="button" class="policy-modal-close" data-decline-close aria-label="Close modal">&times;</button>
            </header>
            <form id="declineForm" method="POST" style="margin:0;">
                <input type="hidden" name="action" id="declineAction" value="reject_user">
                <input type="hidden" name="view" id="declineView" value="accounts">
                <input type="hidden" name="role" id="declineRole" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="target_user_id" id="rejectTargetId" value="">
                <input type="hidden" name="target_role" id="rejectTargetRole" value="<?= htmlspecialchars($role) ?>">
                <input type="hidden" name="application_id" id="declineApplicationId" value="">
                <input type="hidden" name="property_id" id="declinePropertyId" value="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                <div class="policy-modal-body">
                  <section class="policy-card">
                    <p style="margin:0 0 12px; font-size:0.92rem; color:#57534e;">
                        Please provide a reason for rejecting <strong id="rejectTargetName">this user</strong>'s verification. This message will be displayed in their account settings.
                    </p>
                    
                    <label style="display:block; font-size:0.86rem; font-weight:600; color:#292524; margin-bottom:6px;">Common Reasons:</label>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; font-size:0.84rem;">
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="Document photo is blurry, unreadable, or cut off. Please upload a clear, full document photo."> Blurry / Unreadable Document</label>
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="The name or details on the ID do not match the account registration profile."> Name / Details Mismatch</label>
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="Document appears expired or is not an acceptable government / school credential."> Expired or Invalid Credential</label>
                    </div>

                    <label style="display:block; font-size:0.86rem; font-weight:600; color:#292524; margin-bottom:6px;">Custom Feedback / Instructions:</label>
                    <textarea id="rejectionNotesText" name="rejection_notes" rows="4" maxlength="500" style="box-sizing:border-box;width:100%; border:1px solid #d9bea6; border-radius:10px; padding:11px 12px; font-family:inherit; font-size:0.9rem;" required></textarea>
                  </section>
                </div>
                <footer class="policy-modal-footer">
                    <button type="button" class="btn-policy-cancel" data-decline-close>Cancel</button>
                    <button type="submit" class="btn-policy-confirm" style="background:#dc2626;border-color:#dc2626;">Confirm Decline</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Client-side Drawer, Tabs, and Modals Script -->
    <script>
        var currentModalUserId = 0;
        var currentModalUserName = '';
        var currentModalRole = '<?= htmlspecialchars($role) ?>';
        var currentModalRecordStatus = '';
        var adminVerificationCsrf = <?= json_encode($_SESSION['admin_verification_csrf']) ?>;

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || form.querySelector('input[name="csrf_token"]')) return;
            var token = document.createElement('input');
            token.type = 'hidden';
            token.name = 'csrf_token';
            token.value = adminVerificationCsrf;
            form.appendChild(token);
        }, true);

        function openInspectModal(imgUrl, docTitle, name, userId, role) {
            currentModalUserId = userId;
            currentModalUserName = name;
            currentModalRole = role || '<?= htmlspecialchars($role) ?>';

            document.getElementById('inspectTitle').textContent = docTitle + ' · ' + name;
            var approveForm = document.getElementById('inspectApproveForm');
            var rejectBtn = document.getElementById('inspectRejectBtn');
            
            if (docTitle === 'Proof of Ownership') {
                document.getElementById('inspectSub').textContent = 'Property Listing #' + userId + ' · Proof of Ownership inspection';
                if (approveForm) approveForm.style.display = 'none';
                if (rejectBtn) rejectBtn.style.display = 'none';
            } else {
                document.getElementById('inspectSub').textContent = 'Role: ' + (role === 'landlord' ? 'Dorm Landlord' : 'Student Tenant');
                var canDecide = userId && !['verified', 'approved'].includes(currentModalRecordStatus);
                if (approveForm) approveForm.style.display = canDecide ? '' : 'none';
                if (rejectBtn) rejectBtn.style.display = canDecide ? '' : 'none';
            }

            document.getElementById('inspectImg').hidden = false;
            document.getElementById('inspectUnavailable').hidden = true;
            document.getElementById('inspectImg').src = imgUrl;
            document.getElementById('inspectApproveId').value = userId;
            document.getElementById('inspectApproveRole').value = currentModalRole;
            document.getElementById('inspectModal').classList.add('is-active');

            if (rejectBtn) {
                rejectBtn.onclick = function() {
                    closeInspectModal();
                    openDeclineModal({type: currentModalRole, id: userId, name: name});
                };
            }
        }

        function closeInspectModal() {
            document.getElementById('inspectModal').classList.remove('is-active');
            var approveForm = document.getElementById('inspectApproveForm');
            var rejectBtn = document.getElementById('inspectRejectBtn');
            if (approveForm) approveForm.style.display = '';
            if (rejectBtn) rejectBtn.style.display = '';
        }

        function openDeclineModal(config) {
            config = config || {};
            var type = config.type || '<?= htmlspecialchars($role) ?>';
            document.getElementById('declineAction').value = type === 'property' ? 'decline_property' : (type === 'application' ? 'decline_application' : 'reject_user');
            document.getElementById('declineView').value = type === 'property' ? 'properties' : (type === 'application' ? 'applications' : 'accounts');
            document.getElementById('declineRole').value = (type === 'tenant' || type === 'landlord') ? type : '<?= htmlspecialchars($role) ?>';
            document.getElementById('rejectTargetId').value = (type === 'tenant' || type === 'landlord') ? config.id : '';
            document.getElementById('rejectTargetRole').value = (type === 'tenant' || type === 'landlord') ? type : '<?= htmlspecialchars($role) ?>';
            document.getElementById('declineApplicationId').value = type === 'application' ? config.id : '';
            document.getElementById('declinePropertyId').value = type === 'property' ? config.id : '';
            document.getElementById('rejectTargetName').textContent = config.name || 'this request';
            document.getElementById('rejectionNotesText').value = '';
            var modal = document.getElementById('rejectModal');
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            setTimeout(function () { document.getElementById('rejectionNotesText').focus(); }, 0);
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').hidden = true;
            document.body.style.overflow = '';
        }

        document.getElementById('rejectModal').addEventListener('click', function (event) {
            if (event.target.closest('[data-decline-close]')) closeRejectModal();
        });

        function applyPresetReason(text) {
            document.getElementById('rejectionNotesText').value = text;
        }

        // Status Pill Indicator positioning (matching tenant/settings.php)
        // Category Navigation Moving Brown Underline
        var catBar = document.querySelector('.topbar-categories-bar');
        var catIndicator = catBar ? catBar.querySelector('.topbar-cat-indicator') : null;
        var catTabs = catBar ? Array.from(catBar.querySelectorAll('.topbar-cat-tab')) : [];
        var catIndicatorReady = false;

        function placeCatIndicator(targetTab, instant) {
            if (!catIndicator) return;
            var active = targetTab || (catBar ? catBar.querySelector('.topbar-cat-tab.is-active') : null);
            if (!active) return;

            var noAnim = instant || !catIndicatorReady;
            if (noAnim) catIndicator.style.transition = 'none';

            var yPos = Math.max(0, active.offsetTop + active.offsetHeight - 3);
            catIndicator.style.width = active.offsetWidth + 'px';
            catIndicator.style.transform = 'translate(' + active.offsetLeft + 'px, ' + yPos + 'px)';

            if (noAnim) {
                void catIndicator.offsetWidth;
                catIndicator.style.transition = '';
                catIndicatorReady = true;
            }
        }

        function initCatIndicator() {
            if (!catBar || !catIndicator || catTabs.length === 0) return;
            var activeTab = catBar.querySelector('.topbar-cat-tab.is-active') || catTabs[0];
            var activeIdx = catTabs.indexOf(activeTab);

            try {
                var prevIdxRaw = sessionStorage.getItem('admin_verif_prev_cat_idx');
                var prevTimeRaw = sessionStorage.getItem('admin_verif_prev_cat_time');
                if (prevIdxRaw !== null && prevTimeRaw) {
                    var prevIdx = parseInt(prevIdxRaw, 10);
                    var prevTime = parseInt(prevTimeRaw, 10);
                    if (Date.now() - prevTime < 6000 && prevIdx >= 0 && prevIdx < catTabs.length && prevIdx !== activeIdx) {
                        var startTab = catTabs[prevIdx];
                        var startY = Math.max(0, startTab.offsetTop + startTab.offsetHeight - 3);
                        catIndicator.style.transition = 'none';
                        catIndicator.style.width = startTab.offsetWidth + 'px';
                        catIndicator.style.transform = 'translate(' + startTab.offsetLeft + 'px, ' + startY + 'px)';
                        void catIndicator.offsetWidth;

                        requestAnimationFrame(function() {
                            catIndicator.style.transition = '';
                            catIndicatorReady = true;
                            placeCatIndicator(activeTab, false);
                        });

                        sessionStorage.removeItem('admin_verif_prev_cat_idx');
                        sessionStorage.removeItem('admin_verif_prev_cat_time');
                        return;
                    }
                }
            } catch (_) {}

            placeCatIndicator(activeTab, true);
        }

        // Wire up Category Navigation tabs with smooth sliding brown underline and seamless content loading
        var isCategoryNavigating = false;
        catTabs.forEach(function(tab, idx) {
            tab.addEventListener('click', function(e) {
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
                var href = tab.getAttribute('href');
                if (!href || href === '#' || tab.classList.contains('is-active')) {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();

                // 1. Instantly slide the brown underline!
                catTabs.forEach(function(t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                placeCatIndicator(tab, false);

                // 2. Record index for reload fallback animation
                try {
                    sessionStorage.setItem('admin_verif_prev_cat_idx', idx);
                    sessionStorage.setItem('admin_verif_prev_cat_time', Date.now());
                } catch (_) {}

                // 3. Load category content seamlessly
                loadCategoryPage(href, true);
            });
        });

        function loadCategoryPage(url, pushState) {
            if (isCategoryNavigating) return;
            isCategoryNavigating = true;

            var mainContainer = document.getElementById('verification-main');
            if (mainContainer) {
                mainContainer.style.opacity = '0.4';
                mainContainer.style.transition = 'opacity 0.15s ease';
            }

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.text();
                })
                .then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');

                    var newMain = doc.getElementById('verification-main');
                    if (!newMain) {
                        window.location.href = url;
                        return;
                    }

                    if (mainContainer) {
                        mainContainer.innerHTML = newMain.innerHTML;
                        mainContainer.style.opacity = '';
                        mainContainer.scrollTop = 0;
                    }

                    // Sync category badges
                    var newCatBar = doc.querySelector('.topbar-categories-bar');
                    if (newCatBar && catBar) {
                        var newTabs = newCatBar.querySelectorAll('.topbar-cat-tab');
                        catTabs.forEach(function(currTab, i) {
                            var newBadge = newTabs[i] ? newTabs[i].querySelector('.cat-count-badge') : null;
                            var currBadge = currTab.querySelector('.cat-count-badge');
                            if (newBadge) {
                                if (currBadge) {
                                    currBadge.textContent = newBadge.textContent;
                                } else {
                                    var b = document.createElement('span');
                                    b.className = 'cat-count-badge';
                                    b.textContent = newBadge.textContent;
                                    currTab.appendChild(b);
                                }
                            } else if (currBadge) {
                                currBadge.remove();
                            }
                        });
                    }

                    if (doc.title) document.title = doc.title;

                    if (pushState && window.history && window.history.pushState) {
                        window.history.pushState({ verifUrl: url }, '', url);
                    }

                    rebindPageControls();
                    isCategoryNavigating = false;
                })
                .catch(function(err) {
                    console.warn('Seamless category navigation failed, reloading page...', err);
                    window.location.href = url;
                });
        }

        window.addEventListener('popstate', function(e) {
            var url = window.location.href;
            var params = new URL(url).searchParams;
            var viewParam = params.get('view');
            var roleParam = params.get('role');

            var matchedTab = null;
            catTabs.forEach(function(tab) {
                var tHref = tab.getAttribute('href') || '';
                if (viewParam && tHref.indexOf('view=' + viewParam) !== -1) {
                    matchedTab = tab;
                } else if (!viewParam && roleParam && tHref.indexOf('role=' + roleParam) !== -1) {
                    matchedTab = tab;
                }
            });

            if (!matchedTab) matchedTab = catTabs[0];

            catTabs.forEach(function(t) { t.classList.remove('is-active'); });
            matchedTab.classList.add('is-active');
            placeCatIndicator(matchedTab, false);

            loadCategoryPage(url, false);
        });

        // Status Tabs & Cards Filtering variables
        var statusTabs = null;
        var statusPill = null;
        var statusButtons = [];
        var statusPillReady = false;
        var currentStatusFilter = '<?= htmlspecialchars($filter) ?>';
        var searchInput = null;
        var cards = [];
        var emptyState = null;

        function placeStatusPill(instant) {
            var active = statusTabs ? statusTabs.querySelector('.settings-tab-btn.is-active') : null;
            if (!statusPill || !active) return;
            var noAnim = instant || !statusPillReady;
            if (noAnim) statusPill.style.transition = 'none';
            statusPill.style.width = active.offsetWidth + 'px';
            statusPill.style.transform = 'translateX(' + active.offsetLeft + 'px)';
            if (noAnim) {
                void statusPill.offsetWidth;
                statusPill.style.transition = '';
                statusPillReady = true;
            }
        }

        function updateCardFilter() {
            var query = (searchInput ? searchInput.value.trim().toLowerCase() : '');
            var visibleCount = 0;

            cards.forEach(function(card) {
                var cardStatus = (card.dataset.status || '').toLowerCase();
                var searchTarget = card.dataset.search || '';

                // Match status
                var statusMatch = false;
                if (currentStatusFilter === 'all') {
                    statusMatch = true;
                } else if (currentStatusFilter === 'pending' && cardStatus === 'pending') {
                    statusMatch = true;
                } else if ((currentStatusFilter === 'approved' || currentStatusFilter === 'verified') && (cardStatus === 'approved' || cardStatus === 'verified')) {
                    statusMatch = true;
                } else if ((currentStatusFilter === 'declined' || currentStatusFilter === 'rejected') && (cardStatus === 'declined' || cardStatus === 'rejected')) {
                    statusMatch = true;
                }

                var searchMatch = (!query || searchTarget.indexOf(query) !== -1);
                var isVisible = (statusMatch && searchMatch);

                card.style.display = isVisible ? 'flex' : 'none';
                if (isVisible) visibleCount++;
            });

            if (emptyState) {
                emptyState.style.display = (visibleCount === 0 && cards.length > 0) ? 'flex' : 'none';
            }
        }

        function rebindPageControls() {
            var selectBtn = document.getElementById('verif-select-btn');
            var selectMenu = document.getElementById('verif-select-menu');
            var selectValue = document.getElementById('verif-select-value');

            // Find initially selected option
            var activeLi = selectMenu ? selectMenu.querySelector('li[aria-selected="true"]') : null;
            currentStatusFilter = activeLi ? (activeLi.getAttribute('data-value') || 'all') : 'all';

            searchInput = document.getElementById('verif-search');
            cards = Array.from(document.querySelectorAll('.tenant-card'));
            emptyState = document.getElementById('verifEmptyState');

            if (searchInput) {
                searchInput.addEventListener('input', updateCardFilter);
            }

            if (selectBtn && selectMenu) {
                selectBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isExpanded = selectBtn.getAttribute('aria-expanded') === 'true';
                    if (isExpanded) {
                        closeSelectMenu();
                    } else {
                        openSelectMenu();
                    }
                });

                function openSelectMenu() {
                    selectBtn.setAttribute('aria-expanded', 'true');
                    selectMenu.removeAttribute('hidden');
                    selectMenu.classList.remove('is-closing');
                }

                function closeSelectMenu() {
                    if (selectBtn.getAttribute('aria-expanded') !== 'true') return;
                    selectMenu.classList.add('is-closing');
                    setTimeout(function() {
                        selectBtn.setAttribute('aria-expanded', 'false');
                        selectMenu.setAttribute('hidden', '');
                        selectMenu.classList.remove('is-closing');
                    }, 170);
                }

                document.addEventListener('click', function(e) {
                    if (!selectBtn.contains(e.target) && !selectMenu.contains(e.target)) {
                        closeSelectMenu();
                    }
                });

                selectMenu.querySelectorAll('li').forEach(function(li) {
                    li.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var val = li.getAttribute('data-value') || 'all';
                        currentStatusFilter = val;

                        selectMenu.querySelectorAll('li').forEach(function(item) {
                            item.setAttribute('aria-selected', 'false');
                        });
                        li.setAttribute('aria-selected', 'true');
                        if (selectValue) {
                            selectValue.textContent = li.textContent.trim();
                        }
                        closeSelectMenu();
                        updateCardFilter();

                        if (window.history && window.history.replaceState) {
                            var u = new URL(window.location.href);
                            u.searchParams.set('filter', val);
                            window.history.replaceState(null, '', u.toString());
                        }
                    });
                });
            }

            updateCardFilter();
        }

        // Slide-Over Drawer Controller
        var drawer = document.getElementById('verifDrawer');
        var drawerOverlay = document.getElementById('drawerOverlay');
        var drawerClose = document.getElementById('drawerClose');
        var drawerBody = document.getElementById('drawerBody');
        var drawerFooter = document.getElementById('drawerFooter');
        var drawerTitle = document.getElementById('drawerTitle');

        function openVerifDrawer(data) {
            if (!data) return;
            currentModalRecordStatus = data.status || '';

            // Header Title
            if (data.type === 'property') {
                drawerTitle.textContent = 'Property Listing Details';
            } else if (data.type === 'application') {
                drawerTitle.textContent = 'Landlord Application Details';
            } else if (data.type === 'landlord') {
                drawerTitle.textContent = 'Landlord Verification Details';
            } else {
                drawerTitle.textContent = 'Student Verification Details';
            }

            // Build Hero Section
            var avatarHtml = '';
            if (data.type === 'property') {
                avatarHtml = '<img src="' + escapeHtml(data.coverPhoto) + '" alt="' + escapeHtml(data.name) + '">';
            } else if (data.photo) {
                avatarHtml = '<img src="' + escapeHtml(data.photo) + '" alt="" onerror="this.style.display=\'none\';if(this.nextElementSibling)this.nextElementSibling.style.display=\'inline-flex\';"><span style="display:none;">' + escapeHtml(data.initials || 'U') + '</span>';
            } else {
                avatarHtml = '<span>' + escapeHtml(data.initials || 'U') + '</span>';
            }

            var roleChipText = (data.type === 'property') ? 'Property Listing' :
                               (data.type === 'application' ? 'Landlord Applicant' :
                               (data.type === 'landlord' ? 'Dorm Landlord' : 'Student Tenant'));

            var heroChipsHtml = '';
            if (data.type === 'property') {
                heroChipsHtml = '<span class="hero-chip">📍 ' + escapeHtml(data.university) + '</span>' +
                                '<span class="hero-chip">🚪 ' + data.roomCount + ' Rooms · ' + data.totalSlots + ' Beds</span>' +
                                '<span class="hero-chip">🗓 Submitted ' + escapeHtml(data.submittedAt) + '</span>';
            } else if (data.type === 'application') {
                heroChipsHtml = '<span class="hero-chip">✉ ' + escapeHtml(data.email) + '</span>' +
                                '<span class="hero-chip">📞 ' + escapeHtml(data.phone) + '</span>' +
                                '<span class="hero-chip">🗓 Submitted ' + escapeHtml(data.submittedAt) + '</span>';
            } else if (data.type === 'tenant') {
                heroChipsHtml = '<span class="hero-chip">✉ ' + escapeHtml(data.email) + '</span>' +
                                '<span class="hero-chip">📞 ' + escapeHtml(data.phone) + '</span>' +
                                (data.school ? '<span class="hero-chip">🏫 ' + escapeHtml(data.school) + '</span>' : '');
            } else {
                heroChipsHtml = '<span class="hero-chip">✉ ' + escapeHtml(data.email) + '</span>' +
                                '<span class="hero-chip">📞 ' + escapeHtml(data.phone) + '</span>' +
                                (data.facebook ? '<span class="hero-chip">🔗 Facebook Linked</span>' : '');
            }

            var heroVerified = data.type !== 'property' && (data.status === 'approved' || data.status === 'verified');
            var heroCheck = heroVerified
                ? '<span class="verified-check" role="img" aria-label="Verified" title="Verified"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span>'
                : '';
            var heroSection = '<div class="drawer-hero">' +
                '<div class="hero-top">' +
                    '<div class="hero-avatar">' + avatarHtml + '</div>' +
                    '<div class="hero-info">' +
                        '<h3>' + escapeHtml(data.name) + heroCheck + '</h3>' +
                        '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">' +
                            '<span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:3px 8px; border-radius:6px; background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe;">' + roleChipText + '</span>' +
                            (heroVerified ? '' : '<span class="badge ' + escapeHtml(data.badgeClass) + '">' + escapeHtml(data.statusLabel) + '</span>') +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="hero-meta">' + heroChipsHtml + '</div>' +
            '</div>';

            // Build Body Sections
            var bodySectionsHtml = '';

            if (data.type === 'property') {
                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Property Overview</h4></div>' +
                    '<div class="detail-grid">' +
                        '<div class="detail-item full"><span class="detail-label">Property Name</span><span class="detail-value">' + escapeHtml(data.name) + '</span></div>' +
                        '<div class="detail-item full"><span class="detail-label">Complete Address</span><span class="detail-value">' + escapeHtml(data.address) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Near University</span><span class="detail-value">' + escapeHtml(data.university) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Capacity</span><span class="detail-value">' + data.roomCount + ' Rooms (' + data.totalSlots + ' Beds)</span></div>' +
                    '</div>' +
                '</div>';

                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Landlord Information</h4></div>' +
                    '<div class="detail-grid">' +
                        '<div class="detail-item"><span class="detail-label">Landlord Name</span><span class="detail-value">' + escapeHtml(data.landlordName) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Phone</span><span class="detail-value">' + escapeHtml(data.landlordPhone) + '</span></div>' +
                        '<div class="detail-item full"><span class="detail-label">Personal Email</span><span class="detail-value">' + escapeHtml(data.landlordEmail) + '</span></div>' +
                    '</div>' +
                '</div>';

                if (data.description) {
                    bodySectionsHtml += '<div class="detail-section">' +
                        '<div class="detail-section-head"><h4>Listing Description</h4></div>' +
                        '<div style="font-size:0.88rem; line-height:1.5; color:#5f4a40;">' + escapeHtml(data.description).replace(/\n/g, '<br>') + '</div>' +
                    '</div>';
                }

                // Proof of Ownership document
                var proofThumb = data.proofHas
                    ? '<img src="' + escapeHtml(data.proofUrl) + '" alt="Proof">'
                    : '&#10060;';
                var proofInspectBtn = data.proofHas
                    ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.proofUrl) + '\', \'Proof of Ownership\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'landlord\')">Inspect 🔍</button>'
                    : '';

                // Business Permit document
                var permitThumb = data.permitHas
                    ? '<img src="' + escapeHtml(data.permitUrl) + '" alt="Business Permit">'
                    : '&#10060;';
                var permitInspectBtn = data.permitHas
                    ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.permitUrl) + '\', \'Business Permit\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'landlord\')">Inspect 🔍</button>'
                    : '';

                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Verification Documents</h4></div>' +
                    '<div class="verif-doc-box ' + (!data.proofHas ? 'is-missing' : '') + '">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.proofHas ? 'onclick="openInspectModal(\'' + escapeHtml(data.proofUrl) + '\', \'Proof of Ownership\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'landlord\')"' : '') + '>' + proofThumb + '</div>' +
                            '<div class="doc-details"><strong>Proof of Ownership</strong><span>' + (data.proofHas ? escapeHtml(data.proofName) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        proofInspectBtn +
                    '</div>' +
                    '<div class="verif-doc-box ' + (!data.permitHas ? 'is-missing' : '') + '" style="margin-top: 10px;">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.permitHas ? 'onclick="openInspectModal(\'' + escapeHtml(data.permitUrl) + '\', \'Business Permit\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'landlord\')"' : '') + '>' + permitThumb + '</div>' +
                            '<div class="doc-details"><strong>Business Permit / Mayor\'s Permit</strong><span>' + (data.permitHas ? escapeHtml(data.permitName) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        permitInspectBtn +
                    '</div>' +
                '</div>';

            } else if (data.type === 'application') {
                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Applicant Profile</h4></div>' +
                    '<div class="detail-grid">' +
                        '<div class="detail-item"><span class="detail-label">Full Name</span><span class="detail-value">' + escapeHtml(data.name) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Phone</span><span class="detail-value">' + escapeHtml(data.phone) + '</span></div>' +
                        '<div class="detail-item full"><span class="detail-label">Email Address</span><span class="detail-value">' + escapeHtml(data.email) + '</span></div>' +
                        '<div class="detail-item full"><span class="detail-label">Residential Address</span><span class="detail-value">' + escapeHtml(data.address) + '</span></div>' +
                    '</div>' +
                '</div>';

                if (data.experience) {
                    bodySectionsHtml += '<div class="detail-section">' +
                        '<div class="detail-section-head"><h4>Experience &amp; Responsibility</h4></div>' +
                        '<div style="font-size:0.88rem; line-height:1.5; color:#5f4a40; margin-bottom:10px;">' + escapeHtml(data.experience).replace(/\n/g, '<br>') + '</div>' +
                        '<div style="color:#15803d; font-weight:600; font-size:0.84rem;">&#10003; Landlord responsibilities acknowledged</div>' +
                    '</div>';
                }

                // ID document
                var docThumb = data.docHas
                    ? '<img src="' + escapeHtml(data.docUrl) + '" alt="ID">'
                    : '&#10060;';
                var docInspectBtn = data.docHas
                    ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.docUrl) + '\', \'Government ID\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', 0, \'landlord\')">Inspect 🔍</button>'
                    : '';

                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Government ID Document</h4></div>' +
                    '<div class="verif-doc-box ' + (!data.docHas ? 'is-missing' : '') + '">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.docHas ? 'onclick="openInspectModal(\'' + escapeHtml(data.docUrl) + '\', \'Government ID\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', 0, \'landlord\')"' : '') + '>' + docThumb + '</div>' +
                            '<div class="doc-details"><strong>Government ID</strong><span>' + (data.docHas ? escapeHtml(data.docName) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        docInspectBtn +
                    '</div>' +
                '</div>';

                if (data.approvedBy || data.decisionNote) {
                    bodySectionsHtml += '<div class="detail-section">' +
                        '<div class="detail-section-head"><h4>Review &amp; Decision</h4></div>' +
                        (data.approvedBy ? '<div style="font-size:0.86rem; color:#2d2420; margin-bottom:4px;"><strong>Reviewed by:</strong> ' + escapeHtml(data.approvedBy) + '</div>' : '') +
                        (data.decisionNote ? '<div style="font-size:0.86rem; color:#5f4a40;">' + escapeHtml(data.decisionNote) + '</div>' : '') +
                    '</div>';
                }

            } else {
                // Landlord or Tenant User
                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Profile &amp; Contact Details</h4></div>' +
                    '<div class="detail-grid">' +
                        '<div class="detail-item"><span class="detail-label">Full Name</span><span class="detail-value">' + escapeHtml(data.name) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Account Role</span><span class="detail-value">' + (data.type === 'landlord' ? 'Dorm Landlord' : 'Student Tenant') + '</span></div>' +
                        '<div class="detail-item full"><span class="detail-label">Email Address</span><span class="detail-value">' + escapeHtml(data.email) + '</span></div>' +
                        '<div class="detail-item"><span class="detail-label">Phone</span><span class="detail-value">' + escapeHtml(data.phone) + '</span></div>' +
                        (data.school ? '<div class="detail-item"><span class="detail-label">University / College</span><span class="detail-value">' + escapeHtml(data.school) + '</span></div>' : '') +
                        (data.facebook ? '<div class="detail-item full"><span class="detail-label">Facebook Profile</span><span class="detail-value"><a href="' + escapeHtml(data.facebook) + '" target="_blank" style="color:#7a4b3a;text-decoration:underline;">View Facebook Profile ↗</a></span></div>' : '') +
                    '</div>' +
                '</div>';

                if (data.type === 'tenant' && data.emergencyName) {
                    bodySectionsHtml += '<div class="detail-section">' +
                        '<div class="detail-section-head"><h4>Emergency Contact</h4></div>' +
                        '<div class="detail-grid">' +
                            '<div class="detail-item"><span class="detail-label">Guardian / Contact</span><span class="detail-value">' + escapeHtml(data.emergencyName) + '</span></div>' +
                            '<div class="detail-item"><span class="detail-label">Relationship</span><span class="detail-value">' + escapeHtml(data.emergencyRelationship || 'Guardian') + '</span></div>' +
                            '<div class="detail-item full"><span class="detail-label">Emergency Phone</span><span class="detail-value">' + escapeHtml(data.emergencyPhone || 'N/A') + '</span></div>' +
                        '</div>' +
                    '</div>';
                }

                // Documents section
                var docsListHtml = '';
                if (data.doc1Title) {
                    var d1Thumb = data.doc1Has
                        ? '<img src="' + escapeHtml(data.doc1Url) + '" alt="' + escapeHtml(data.doc1Title) + '">'
                        : '&#10060;';
                    var d1Btn = data.doc1Has
                        ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.doc1Url) + '\', \'' + escapeHtml(data.doc1Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'' + data.type + '\')">Inspect 🔍</button>'
                        : '';
                    docsListHtml += '<div class="verif-doc-box ' + (!data.doc1Has ? 'is-missing' : '') + '">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.doc1Has ? 'onclick="openInspectModal(\'' + escapeHtml(data.doc1Url) + '\', \'' + escapeHtml(data.doc1Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'' + data.type + '\')"' : '') + '>' + d1Thumb + '</div>' +
                            '<div class="doc-details"><strong>' + escapeHtml(data.doc1Title) + '</strong><span>' + (data.doc1Has ? escapeHtml(data.doc1Name) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        d1Btn +
                    '</div>';
                }

                if (data.doc2Title) {
                    var d2Thumb = data.doc2Has
                        ? '<img src="' + escapeHtml(data.doc2Url) + '" alt="' + escapeHtml(data.doc2Title) + '">'
                        : '&#10060;';
                    var d2Btn = data.doc2Has
                        ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.doc2Url) + '\', \'' + escapeHtml(data.doc2Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'' + data.type + '\')">Inspect 🔍</button>'
                        : '';
                    docsListHtml += '<div class="verif-doc-box ' + (!data.doc2Has ? 'is-missing' : '') + '" style="margin-top:10px;">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.doc2Has ? 'onclick="openInspectModal(\'' + escapeHtml(data.doc2Url) + '\', \'' + escapeHtml(data.doc2Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + JSON.stringify(data.id) + ', \'' + data.type + '\')"' : '') + '>' + d2Thumb + '</div>' +
                            '<div class="doc-details"><strong>' + escapeHtml(data.doc2Title) + '</strong><span>' + (data.doc2Has ? escapeHtml(data.doc2Name) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        d2Btn +
                    '</div>';
                }

                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Verification Credentials</h4></div>' +
                    docsListHtml +
                '</div>';

                if (data.verificationNotes) {
                    bodySectionsHtml += '<div class="detail-section">' +
                        '<div class="detail-section-head"><h4>Verification Notes</h4></div>' +
                        '<div style="font-size:0.86rem; color:#991b1b;">' + escapeHtml(data.verificationNotes) + '</div>' +
                    '</div>';
                }
            }

            drawerBody.innerHTML = heroSection + bodySectionsHtml;

            // Build Footer Actions
            var footerHtml = '';
            var csrfToken = window.ADMIN_VERIF_CSRF || '';

            if (data.type === 'property') {
                if (data.status === 'pending') {
                    footerHtml = '<form method="post" data-confirm-name="' + escapeHtml(data.name) + '">' +
                        '<input type="hidden" name="view" value="properties">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="property_id" value="' + data.id + '">' +
                        '<input type="hidden" name="action" value="approve_property">' +
                        '<div class="drawer-footer-actions">' +
                            '<button class="btn-approve" type="submit">Approve Listing</button>' +
                            '<button class="btn-reject" type="button" onclick=\'closeVerifDrawer();openDeclineModal({type:"property",id:' + JSON.stringify(data.id) + ',name:' + JSON.stringify(data.name) + '})\'>Decline</button>' +
                        '</div>' +
                    '</form>';
                } else if (data.status === 'approved') {
                    footerHtml = '<div style="color:#15803d;font-weight:600;font-size:0.9rem;text-align:center;padding:6px 0;">&#10003; Discoverable by students</div>';
                } else {
                    footerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">' +
                        '<span style="color:#991b1b; font-weight:600; font-size:0.88rem;">&#10005; Hidden from students</span>' +
                        '<form method="post" style="margin:0; width:auto;">' +
                            '<input type="hidden" name="view" value="properties">' +
                            '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                            '<input type="hidden" name="property_id" value="' + data.id + '">' +
                            '<button class="btn-inspect" name="action" value="reset_property" type="submit">Reset to Pending</button>' +
                        '</form>' +
                    '</div>';
                }
            } else if (data.type === 'application') {
                if (data.status === 'pending') {
                    footerHtml = '<form method="post" data-confirm-name="' + escapeHtml(data.name) + '">' +
                        '<input type="hidden" name="view" value="applications">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="application_id" value="' + data.id + '">' +
                        '<input type="hidden" name="action" value="approve_application">' +
                        '<div class="drawer-footer-actions">' +
                            '<button class="btn-approve" type="submit">Approve Application</button>' +
                            '<button class="btn-reject" type="button" onclick=\'closeVerifDrawer();openDeclineModal({type:"application",id:' + JSON.stringify(data.id) + ',name:' + JSON.stringify(data.name) + '})\'>Decline</button>' +
                        '</div>' +
                    '</form>';
                } else if (data.status === 'approved' && !data.registeredAt) {
                    footerHtml = '<form method="post">' +
                        '<input type="hidden" name="view" value="applications">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="application_id" value="' + data.id + '">' +
                        '<button class="btn-inspect" style="width:100%; padding:10px;" name="action" value="regenerate_invite" type="submit">Generate New Invitation Link</button>' +
                    '</form>';
                } else if (data.registeredAt) {
                    footerHtml = '<div style="color:#15803d; font-weight:600; font-size:0.9rem; text-align:center; padding:6px 0;">&#10003; Applicant has registered account</div>';
                } else {
                    footerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">' +
                        '<span style="color:#991b1b; font-weight:600; font-size:0.88rem;">Application was declined</span>' +
                        '<form method="post" style="margin:0; width:auto;">' +
                            '<input type="hidden" name="view" value="applications">' +
                            '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                            '<input type="hidden" name="application_id" value="' + data.id + '">' +
                            '<button class="btn-inspect" name="action" value="reset_application" type="submit">Reset to Pending</button>' +
                        '</form>' +
                    '</div>';
                }
            } else {
                // Landlord or Tenant
                if (data.status === 'pending' || data.status === 'unverified') {
                    footerHtml = '<div class="drawer-footer-actions">' +
                        '<form method="POST" style="margin:0; flex:1;" data-confirm-name="' + escapeHtml(data.name) + '">' +
                            '<input type="hidden" name="action" value="approve_user">' +
                            '<input type="hidden" name="target_user_id" value="' + data.id + '">' +
                            '<input type="hidden" name="target_role" value="' + data.type + '">' +
                            '<button type="submit" class="btn-approve" style="width:100%;">Approve Verification</button>' +
                        '</form>' +
                        '<button type="button" class="btn-reject" onclick=\'closeVerifDrawer();openDeclineModal({type:' + JSON.stringify(data.type) + ',id:' + JSON.stringify(data.id) + ',name:' + JSON.stringify(data.name) + '})\'>Decline</button>' +
                    '</div>';
                } else if (data.status === 'verified' || data.status === 'approved') {
                    footerHtml = '';
                } else {
                    footerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">' +
                        '<span style="color:#991b1b; font-weight:600; font-size:0.88rem;">⚠ Verification Rejected</span>' +
                        '<form method="POST" style="margin:0; width:auto;">' +
                            '<input type="hidden" name="action" value="reset_user">' +
                            '<input type="hidden" name="target_user_id" value="' + data.id + '">' +
                            '<input type="hidden" name="target_role" value="' + data.type + '">' +
                            '<button type="submit" class="btn-inspect">Reset to Pending</button>' +
                        '</form>' +
                    '</div>';
                }
            }

            drawerFooter.innerHTML = footerHtml;

            // Open Drawer
            drawerOverlay.classList.add('is-open');
            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeVerifDrawer() {
            drawerOverlay.classList.remove('is-open');
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        if (drawerOverlay) drawerOverlay.addEventListener('click', closeVerifDrawer);
        if (drawerClose) drawerClose.addEventListener('click', closeVerifDrawer);

        // Escape key closes modals and drawer
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('inspectModal').classList.contains('is-active')) {
                    closeInspectModal();
                } else if (!document.getElementById('rejectModal').hidden) {
                    closeRejectModal();
                } else if (drawer && drawer.classList.contains('is-open')) {
                    closeVerifDrawer();
                }
            }
        });

        // View details click delegation
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.view-details-btn');
            if (!btn) return;
            var card = btn.closest('.tenant-card');
            if (!card) return;
            try {
                var record = JSON.parse(card.dataset.record);
                openVerifDrawer(record);
            } catch (err) {
                console.error('Failed to parse record:', err);
            }
        });

        // Helper to escape HTML characters
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // On DOM ready and window resize
        document.addEventListener('DOMContentLoaded', function() {
            initCatIndicator();
            rebindPageControls();
        });
        window.addEventListener('resize', function() {
            placeCatIndicator(null, true);
            placeStatusPill(true);
        });
        window.addEventListener('load', function() {
            placeCatIndicator(null, true);
            placeStatusPill(true);
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function() {
                placeCatIndicator(null, true);
                placeStatusPill(true);
            });
        }

        // System Result Modal closing
        (function () {
            var modal = document.getElementById('resultModal');
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            function closeResult() {
                modal.classList.add('is-closing');
                setTimeout(function () {
                    modal.remove();
                    document.body.style.overflow = '';
                }, 220);
            }
            modal.addEventListener('click', function (e) {
                if (e.target.closest('[data-policy-close]')) closeResult();
            });
            window.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeResult();
            });
        })();

        // Bulk decline modal
        (function () {
            var openButton = document.getElementById('bulkDeclineButton');
            var modal = document.getElementById('bulkDeclineModal');
            if (!openButton || !modal) return;
            var form = modal.querySelector('form');
            var notes = document.getElementById('bulkDeclineNotes');

            function closeBulkDecline() {
                modal.classList.add('is-closing');
                setTimeout(function () {
                    modal.hidden = true;
                    modal.classList.remove('is-closing');
                    document.body.style.overflow = '';
                }, 220);
            }

            openButton.addEventListener('click', function () {
                modal.classList.remove('is-closing');
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                setTimeout(function () { notes.focus(); }, 0);
            });
            modal.addEventListener('click', function (event) {
                if (event.target.closest('[data-bulk-decline-close]')) closeBulkDecline();
            });
            form.addEventListener('submit', function () {
                var submitButton = form.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.textContent = 'Declining...';
            });
            window.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) closeBulkDecline();
            });
        })();

        // Approval Confirmation Modal
        (function () {
            var modal = document.getElementById('confirmModal');
            var okBtn = document.getElementById('confirmModalOk');
            var text = document.getElementById('confirmModalText');
            var pendingForm = null, pendingSubmitter = null, bypass = false;
            if (!modal) return;

            document.addEventListener('submit', function (e) {
                if (bypass) return;
                var form = e.target;
                var action = form.querySelector('input[name="action"]');
                if (!action || !/^(approve_(user|landlord|tenant|property|application)|bulk_approve)$/.test(action.value)) return;
                e.preventDefault();
                pendingForm = form;
                pendingSubmitter = e.submitter || form.querySelector('button[type="submit"]');
                var who = form.dataset.confirmName || currentModalUserName || 'this account';
                text.textContent = form.dataset.confirmText || ('Approve verification for ' + who + '?');
                modal.classList.remove('is-closing');
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            }, true);

            function closeConfirm() {
                modal.classList.add('is-closing');
                setTimeout(function () {
                    modal.hidden = true;
                    modal.classList.remove('is-closing');
                    document.body.style.overflow = '';
                }, 220);
            }

            okBtn.addEventListener('click', function () {
                if (!pendingForm) return;
                var form = pendingForm;
                pendingForm = null;
                if (!form.querySelector('input[name="csrf_token"]')) {
                    var token = document.createElement('input');
                    token.type = 'hidden';
                    token.name = 'csrf_token';
                    token.value = adminVerificationCsrf;
                    form.appendChild(token);
                }
                if (pendingSubmitter) {
                    pendingSubmitter.disabled = true;
                    pendingSubmitter.textContent = 'Approving...';
                }
                modal.hidden = true;
                document.body.style.overflow = '';
                if (window.setVerificationBusy) window.setVerificationBusy(true, 'Approving request...');
                bypass = true;
                window.requestAnimationFrame(function () {
                    window.setTimeout(function () {
                        form.submit();
                        bypass = false;
                    }, 40);
                });
            });

            modal.addEventListener('click', function (e) {
                if (e.target.closest('[data-confirm-close]')) { pendingForm = null; pendingSubmitter = null; closeConfirm(); }
            });
            window.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { pendingForm = null; pendingSubmitter = null; closeConfirm(); }
            });
        })();
    </script>
<div id="verification-busy-overlay" role="status" aria-live="assertive" hidden><span>Processing request...</span></div>

<script src="../assets/js/admin-verification-actions.js?v=2"></script>
</body>
</html>
