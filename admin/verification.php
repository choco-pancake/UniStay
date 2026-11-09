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
$role = $_GET['role'] ?? 'landlord';
if (!in_array($role, ['landlord', 'tenant'], true)) {
    $role = 'landlord';
}

$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'pending', 'approved', 'verified', 'declined', 'rejected'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

// Handle Admin Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $propertyView) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $propertyId = (int)($_POST['property_id'] ?? 0);
        $propAction = (string)$_POST['action'];
        $notes = trim((string)($_POST['rejection_notes'] ?? ''));

        $pStmt = $pdo->prepare('SELECT p.*, u.first_name, u.last_name, u.email, u.email AS personal_email FROM dorms p JOIN landlords u ON u.id = p.landlord_id WHERE p.id = ? LIMIT 1');
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
                    (int)$prop['landlord_id'],
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
                    (int)$prop['landlord_id'],
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
        $applicationId = (int)($_POST['application_id'] ?? $_POST['target_user_id'] ?? 0);
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
            $upd = $pdo->prepare("UPDATE landlords SET status = 'pending', approved_by = NULL, feedback = NULL, decided_at = NULL WHERE id = ?");
            if ($upd->execute([$applicationId])) {
                $successMessage = "Landlord application #{$applicationId} was reset to pending review.";
            }
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    $targetRole = in_array($_POST['target_role'] ?? '', ['landlord', 'tenant'], true) ? $_POST['target_role'] : $role;
    $action = $_POST['action'];

    if ($targetId > 0) {
        if ($action === 'approve_user' || $action === 'approve_landlord' || $action === 'approve_tenant') {
            if (admin_set_verification_status($pdo, $targetId, 'verified', null, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} has been successfully verified! " . 
                    ($targetRole === 'landlord' ? 'Their listings are now active and can receive booking requests.' : 'They can now submit room booking requests.');
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
    $appSql = "SELECT id, first_name, middle_name, last_name, email, email AS personal_email, phone_number AS phone, phone_number, facebook_link, residential_address, experience_summary, gov_id AS government_id_file, gov_id, status, feedback, approved_by, decided_at, created_at FROM landlords";
    if ($applicationFilter !== 'all') {
        $appSql .= " WHERE status = " . $pdo->quote($applicationFilter);
    }
    $appSql .= " ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, id DESC";
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
        JOIN landlords u ON u.id = p.landlord_id
        ORDER BY CASE p.approval_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, p.id DESC
    ";
    $properties = $pdo->query($propSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

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
    <link rel="stylesheet" href="../assets/css/common.css?v=41">
    <link rel="stylesheet" href="../assets/css/confirm-modal.css?v=1">
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
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
    <script src="../assets/js/confirm-modal.js?v=1"></script>
    <script>
        window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id'=>(int)$adminProfile['id'],'role'=>'admin']) ?>;
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
    <style>
        #inspectImg[hidden], #inspectUnavailable[hidden], .btn-inspect[hidden] { display: none; }

        .admin-verification-page .topbar h1 {
            font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
            font-weight: 700 !important;
            color: #5f3425 !important;
            letter-spacing: -0.6px;
            font-size: 2.1rem;
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

        @media (min-width: 761px) {
            .admin-verification-page .shell {
                min-height: calc(100vh - 30px);
                height: calc(100vh - 30px);
                border-radius: 22px;
                padding: 0 54px 24px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .admin-verification-page .topbar {
                flex-shrink: 0;
                position: relative;
                z-index: 1000;
                height: auto !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
                border-bottom: 1px solid #ebd9cb !important;
                padding: 0 !important;
                gap: 0 !important;
            }

            .admin-verification-page .topbar-main-row {
                height: 76px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
                padding: 0 4px;
            }

            .admin-verification-page .profile {
                position: relative;
                z-index: 1001;
            }

            .admin-verification-page #verification-main {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                overflow-x: hidden;
                padding-top: 20px;
                padding-bottom: 30px;
                margin-right: -54px;
                padding-right: 42px;
                scrollbar-color: var(--brown, #7a4b3a) #F3E9DF;
                scrollbar-width: thin;
            }

            .admin-verification-page #verification-main::-webkit-scrollbar {
                width: 12px;
            }

            .admin-verification-page #verification-main::-webkit-scrollbar-track {
                background: #F3E9DF;
                border-radius: 999px;
            }

            .admin-verification-page #verification-main::-webkit-scrollbar-thumb {
                background: var(--brown, #7a4b3a);
                border: 3px solid #F3E9DF;
                border-radius: 999px;
            }

            .admin-verification-page #verification-main::-webkit-scrollbar-thumb:hover {
                background: #5f3425;
            }
        }

        /* Full-Width Categories Bar (Stretched to the very end of the horizontal line) */
        .topbar-categories-bar {
            display: flex;
            width: 100%;
            border-top: 1px solid #ebd9cb;
            margin: 0 0 -1px 0;
            padding: 0;
            background: rgba(253, 250, 245, 0.7);
            position: relative;
            z-index: 2;
        }

        .topbar-cat-tab {
            flex: 1 1 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.94rem;
            font-weight: 600;
            color: #7a4b3a;
            text-decoration: none;
            text-align: center;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
            transition: color 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
        }

        .topbar-cat-tab:hover {
            color: #5f3425;
            background: rgba(122, 75, 58, 0.04);
        }

        .topbar-cat-tab.is-active {
            color: #5f3425;
            font-weight: 700;
            border-bottom-color: transparent;
            background: rgba(122, 75, 58, 0.02);
        }

        .topbar-cat-indicator {
            position: absolute;
            top: 0;
            left: 0;
            height: 3px;
            background: #7a4b3a;
            border-radius: 3px 3px 0 0;
            z-index: 3;
            pointer-events: none;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .cat-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: #dc2626;
            color: #ffffff;
        }

        /* Controls Row: Status Pills on Left, Gallery Search Bar on Far Right */
        .verif-controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        /* Status Pills with Moving Brown Pill Indicator (matching tenant/settings.php) */
        .settings-tabs {
            position: relative;
            display: inline-flex;
            width: fit-content;
            max-width: max-content;
            align-items: center;
            gap: 4px;
            margin: 0;
            padding: 4px;
            border-radius: 11px;
            background: #EFEBE7;
            border: 1px solid #ebd9cb;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .settings-tabs::-webkit-scrollbar {
            display: none;
        }

        .settings-tabs-indicator {
            position: absolute;
            top: 4px;
            left: 0;
            height: calc(100% - 8px);
            border-radius: 8px;
            background: #7a4b3a;
            box-shadow: 0 1px 4px rgba(122, 75, 58, 0.22);
            z-index: 1;
            pointer-events: none;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .settings-tab-btn {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: #7a4b3a;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.90rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.25s ease;
            white-space: nowrap;
        }

        .settings-tab-btn:hover {
            color: #3b2820;
        }

        .settings-tab-btn.is-active {
            color: #ffffff !important;
            font-weight: 700;
        }

        .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: rgba(122, 75, 58, 0.14);
            color: #7a4b3a;
            transition: background 0.25s ease, color 0.25s ease;
        }

        .settings-tab-btn.is-active .tab-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Search Bar (matching gallery of dorms and landlord properties) */
        .um-search {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 240px;
            width: 240px;
            max-width: 240px;
            min-width: 0;
            height: 44px;
            padding: 0 16px;
            border: 1px solid #d9bea6;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(84, 60, 48, 0.08);
            transition: border-color 0.18s, box-shadow 0.18s;
        }

        .um-search svg {
            width: 17px;
            height: 17px;
            flex: none;
            stroke: #8b7467;
            stroke-width: 2;
            fill: none;
        }

        .um-search input {
            flex: 1;
            min-width: 0;
            border: 0;
            background: none;
            padding: 0;
            font: inherit;
            color: #5f3425;
            outline: none;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.92rem;
        }

        .um-search input::placeholder {
            color: #8b7467;
            opacity: 1;
        }

        .um-search:focus-within {
            border-color: #7a4b3a;
            box-shadow: 0 2px 8px rgba(84, 60, 48, 0.12), 0 0 0 3px rgba(122, 75, 58, 0.14);
        }

        /* Horizontal Cards (Styled EXACTLY like landlord/tenants.php requests cards) */
        .verif-cards-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .tenant-card {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
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

        .tenant-avatar {
            width: 54px;
            height: 54px;
            flex-shrink: 0;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #fbf4ee;
            color: #7a4b3a;
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .5px;
            overflow: hidden;
            border: 1px solid #ebd9cb;
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
            color: #5f3425;
            letter-spacing: -0.2px;
        }

        .tenant-sub {
            margin: 2px 0 0;
            font-family: 'DM Sans', sans-serif;
            font-size: 13.5px;
            color: #78665c;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tenant-sub .dot {
            color: #cbbbb0;
        }

        .tenant-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px 22px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed #ebd9cb;
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
            color: #3b2820;
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
            align-items: flex-end;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'DM Sans', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }

        .badge.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .badge.verified,
        .badge.approved,
        .badge.active {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge.rejected,
        .badge.declined {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .badge.unverified {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        /* View Details Button (Signature enlarging effect from landlord/tenants.php) */
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
            white-space: nowrap;
        }

        .btn-booking-details:hover,
        .btn-booking-details:focus-visible {
            color: #ffffff !important;
            background: #5f3425;
            border-color: #5f3425;
            transform: scale(1.08);
            box-shadow: 0 8px 22px rgba(122, 75, 58, 0.24);
        }

        .btn-booking-details:active {
            transform: scale(1.12);
            box-shadow: 0 10px 28px rgba(122, 75, 58, 0.3);
        }

        @media (max-width: 860px) {
            .topbar-categories-bar {
                flex-wrap: wrap;
            }
            .topbar-cat-tab {
                flex: 1 1 50%;
                padding: 10px 8px;
            }
            .tenant-card {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .tenant-actions {
                width: 100%;
                align-items: stretch;
            }
            .btn-booking-details {
                width: 100%;
            }
        }

        /* Slide-over Drawer (Matching landlord/tenants.php) */
        .drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(47, 32, 26, .45);
            backdrop-filter: blur(3px);
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease;
            z-index: 9999;
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
            border-top-left-radius: 28px;
            border-bottom-left-radius: 28px;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-left: 1px solid rgba(217, 190, 166, 0.7);
            overflow: hidden;
            transform: translateX(100%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 10000;
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
            border-bottom: 1px solid #ebd9cb;
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
            color: #5f3425;
            letter-spacing: -0.3px;
        }

        .drawer-close {
            width: auto;
            height: auto;
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
            margin-left: auto;
            transition: color 0.15s ease, transform 0.15s ease;
        }

        .drawer-close:hover {
            color: #3b2820;
            transform: scale(1.1);
        }

        .drawer-close:active {
            color: #1a0f0a;
            transform: scale(0.92);
        }

        .drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px 26px 32px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            scrollbar-width: thin;
            scrollbar-color: #7a4b3a #F3E9DF;
        }

        .drawer-body::-webkit-scrollbar {
            width: 10px;
        }

        .drawer-body::-webkit-scrollbar-track {
            background: #F3E9DF;
            border-radius: 999px;
        }

        .drawer-body::-webkit-scrollbar-thumb {
            background: #7a4b3a;
            border: 2px solid #F3E9DF;
            border-radius: 999px;
        }

        .drawer-body::-webkit-scrollbar-thumb:hover {
            background: #5f3425;
        }

        /* Hero in Drawer */
        .drawer-hero {
            background: linear-gradient(135deg, #fbf4ee 0%, #fdfaf6 100%);
            border: 1px solid #ebd9cb;
            border-radius: 20px;
            padding: 20px 22px;
            box-shadow: 0 4px 14px rgba(122, 75, 58, 0.05);
        }

        .drawer-hero .hero-top {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
        }

        .drawer-hero .hero-avatar {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            font-family: 'DM Sans', sans-serif;
            font-size: 22px;
            font-weight: 700;
            background: #fff;
            border: 1px solid #ebd9cb;
            color: #7a4b3a;
            display: grid;
            place-items: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(122, 75, 58, .08);
        }

        .drawer-hero .hero-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .drawer-hero .hero-info h3 {
            margin: 0 0 6px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 20px;
            font-weight: 700;
            color: #3b2820;
        }

        .drawer-hero .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .drawer-hero .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            border: 1px solid #ebd9cb;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #5c3e34;
        }

        /* Drawer Structured Tiles */
        .detail-section {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 20px;
            padding: 20px 22px;
            box-shadow: 0 3px 12px rgba(60, 30, 20, 0.04);
        }

        .detail-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f3e9df;
        }

        .detail-section-head h4 {
            margin: 0;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 16px;
            font-weight: 700;
            color: #5f3425;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .detail-item {
            background: #fdfbf9;
            border: 1px solid #f2e7df;
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 3px;
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
            color: #292524;
            word-break: break-word;
            line-height: 1.4;
        }

        /* Document inspect box inside drawer */
        .verif-doc-box {
            background: #fdfbf9;
            border: 1px solid #ebd9cb;
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .verif-doc-box.is-missing {
            background: #fafaf9;
            border: 1px dashed #d6d3d1;
            opacity: 0.85;
        }

        .doc-thumbnail-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .doc-thumb {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #ebd9cb;
            display: grid;
            place-items: center;
            font-size: 20px;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            border: 1px solid #dfc6b4;
            transition: transform 0.15s ease;
        }

        .doc-thumb:hover {
            transform: scale(1.06);
        }

        .doc-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .doc-details strong {
            display: block;
            font-size: 0.88rem;
            color: #292524;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-details span {
            font-size: 0.78rem;
            color: #8c7569;
            display: block;
        }

        .btn-inspect {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            color: #7a4b3a;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-inspect:hover {
            background: #7a4b3a;
            color: #fff;
            border-color: #7a4b3a;
        }

        /* Drawer Footer Actions */
        .drawer-footer {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 18px 24px;
            border-top: 1px solid #ebd9cb;
            background: #fff;
            border-bottom-left-radius: 28px;
            border-bottom-right-radius: 0;
        }

        .drawer-footer form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
        }

        .drawer-footer-actions {
            display: flex;
            gap: 10px;
            width: 100%;
        }

        .btn-approve {
            flex: 1;
            background: #15803d;
            color: #ffffff;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.90rem;
            cursor: pointer;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-approve:hover {
            background: #166534;
            transform: translateY(-1px);
        }

        .btn-reject {
            flex: 1;
            background: #ffffff;
            color: #dc2626;
            border: 1px solid #fca5a5;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.90rem;
            cursor: pointer;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-reject:hover {
            background: #fef2f2;
            border-color: #ef4444;
        }

        .btn-approve:disabled, .btn-reject:disabled { opacity: .7; cursor: wait; transform: none; }

        /* Empty State (Plainly on cream card, no white container card) */
        .verif-empty {
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
            padding: 60px 24px 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #8c7569;
        }

        .verif-empty-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            display: block;
        }

        /* Inspect Modal */
        .inspect-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .inspect-modal.is-active {
            display: flex;
        }

        .inspect-box {
            background: #ffffff;
            border-radius: 20px;
            max-width: 860px;
            width: 100%;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            animation: zoomModal 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .inspect-header {
            padding: 18px 24px;
            border-bottom: 1px solid #ebd9cb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .inspect-body {
            padding: 24px;
            overflow-y: auto;
            text-align: center;
            background: #f7f3ef;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 360px;
        }

        .inspect-img {
            max-width: 100%;
            max-height: 60vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .inspect-footer {
            padding: 16px 24px;
            border-top: 1px solid #ebd9cb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
        }

        @keyframes zoomModal {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .policy-modal { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; padding: 20px; }
        .policy-modal-backdrop { position: fixed; inset: 0; background: rgba(30, 15, 10, 0.55); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); transition: opacity 0.22s ease; }
        .policy-modal-panel { position: relative; width: min(100%, 640px); max-height: 84vh; display: flex; flex-direction: column; background: #FDFAF5; border: 1px solid #E8DDD3; border-radius: 20px; box-shadow: 0 20px 50px rgba(45, 25, 18, 0.28); overflow: hidden; animation: policy-pop 0.26s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes policy-pop { from { opacity: 0; transform: scale(0.95) translateY(14px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .policy-modal.is-closing { pointer-events: none; }
        .policy-modal.is-closing .policy-modal-backdrop { opacity: 0; }
        .policy-modal.is-closing .policy-modal-panel { opacity: 0; transform: scale(0.96) translateY(10px); transition: opacity 0.22s ease, transform 0.22s ease; }
        .policy-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 26px 16px; border-bottom: 1px solid #E8DDD3; background: #FDFAF5; }
        .policy-modal-title-group { display: flex; flex-direction: column; gap: 2px; }
        .policy-modal-crumb { font-size: 12px; font-weight: 600; color: #A58D84; letter-spacing: 0.4px; text-transform: uppercase; margin: 0; }
        .policy-modal-header h2 { margin: 0; font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 24px; line-height: 1.2; color: #6B3624; }
        .policy-modal-close { display: grid; place-items: center; width: 36px; height: 36px; border: 1px solid #E8DDD3; background: #F7F1E7; color: #7B4A3A; font-size: 24px; line-height: 1; cursor: pointer; border-radius: 50%; transition: all 0.18s ease; }
        .policy-modal-close:hover { background: #7B4A3A; color: #fff; border-color: #7B4A3A; }
        .policy-modal-body { padding: 24px 26px; overflow-y: auto; font-family: 'DM Sans', sans-serif; color: #8B5A4A; scrollbar-color: #7B4A3A #F3E9DF; scrollbar-width: thin; }
        .policy-modal-body .policy-card { background: #F7F1E7; border: 1px solid #E8DDD3; border-radius: 18px; padding: 20px 24px; }
        .policy-modal-body .policy-card h2 { font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 19px; color: #6B3624; margin: 0 0 8px; }
        .policy-modal-body .policy-card p { font-size: 14.5px; line-height: 24px; color: #8B5A4A; margin: 0; }
        .policy-modal-footer { display: flex; align-items: center; justify-content: space-between; padding: 16px 26px; border-top: 1px solid #E8DDD3; background: #FDFAF5; }
        .policy-modal-footer-note { font-size: 12.5px; color: #A58D84; font-weight: 500; }
        .btn-policy-confirm { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 24px; border: 1px solid #E8DDD3; border-radius: 999px; background: #7B4A3A; color: #fff; font: 500 13.5px 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; }
        .btn-policy-confirm:hover { background: #5C3527; border-color: #5C3527; }
        .policy-modal[hidden] { display: none !important; }
        .btn-policy-cancel { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 22px; border: 1px solid #E8DDD3; border-radius: 999px; background: #fff; color: #7B4A3A; font: 500 13.5px 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; }
        .btn-policy-cancel:hover { background: #F7F1E7; border-color: #7B4A3A; }
    </style>
</head>
<body class="admin-verification-page">
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
            <a href="subscribers.php" title="Subscribers"><svg aria-hidden="true"><use href="#mail-icon"/></svg><span class="nav-label">Subscribers</span></a>
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

            <!-- Full-Width Category Navigation stretched across entire horizontal line (No Icons) -->
            <nav class="topbar-categories-bar" aria-label="Verification sections">
                <span class="topbar-cat-indicator" aria-hidden="true"></span>
                <a href="?role=landlord" class="topbar-cat-tab <?= (!$applicationView && !$propertyView && $role === 'landlord') ? 'is-active' : '' ?>">
                    <span>Dorm Landlords</span>
                    <?php if ($landlordCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $landlordCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="?role=tenant" class="topbar-cat-tab <?= (!$applicationView && !$propertyView && $role === 'tenant') ? 'is-active' : '' ?>">
                    <span>Student Tenants</span>
                    <?php if ($tenantCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $tenantCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="?view=applications" class="topbar-cat-tab <?= $applicationView ? 'is-active' : '' ?>">
                    <span>Landlord Applications</span>
                    <?php if ($applicationCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $applicationCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="?view=properties" class="topbar-cat-tab <?= $propertyView ? 'is-active' : '' ?>">
                    <span>Property Listings</span>
                    <?php if ($propertyCounts['pending'] > 0): ?>
                        <span class="cat-count-badge"><?= $propertyCounts['pending'] ?></span>
                    <?php endif; ?>
                </a>
            </nav>
        </header>

        <main id="verification-main" tabindex="-1">

        <!-- Controls Row: Status Pills on Left with Moving Brown Pill, Gallery of Dorms Search Bar on Right -->
        <div class="verif-controls-row">
            <!-- Status Filter Pills: All first, followed by Pending, Approved, and Declined ONLY -->
            <nav class="settings-tabs" role="tablist" aria-label="Status filter">
                <span class="settings-tabs-indicator" aria-hidden="true"></span>
                <?php if ($propertyView): ?>
                    <button type="button" class="settings-tab-btn <?= $propertyFilter === 'all' ? 'is-active' : '' ?>" data-status="all">
                        <span>All</span>
                        <span class="tab-count"><?= $propertyCounts['all'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $propertyFilter === 'pending' ? 'is-active' : '' ?>" data-status="pending">
                        <span>Pending</span>
                        <span class="tab-count"><?= $propertyCounts['pending'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $propertyFilter === 'approved' ? 'is-active' : '' ?>" data-status="approved">
                        <span>Approved</span>
                        <span class="tab-count"><?= $propertyCounts['approved'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= ($propertyFilter === 'declined' || $propertyFilter === 'rejected') ? 'is-active' : '' ?>" data-status="declined">
                        <span>Declined</span>
                        <span class="tab-count"><?= $propertyCounts['declined'] ?? $propertyCounts['rejected'] ?></span>
                    </button>
                <?php elseif ($applicationView): ?>
                    <button type="button" class="settings-tab-btn <?= $applicationFilter === 'all' ? 'is-active' : '' ?>" data-status="all">
                        <span>All</span>
                        <span class="tab-count"><?= $applicationCounts['all'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $applicationFilter === 'pending' ? 'is-active' : '' ?>" data-status="pending">
                        <span>Pending</span>
                        <span class="tab-count"><?= $applicationCounts['pending'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $applicationFilter === 'approved' ? 'is-active' : '' ?>" data-status="approved">
                        <span>Approved</span>
                        <span class="tab-count"><?= $applicationCounts['approved'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $applicationFilter === 'declined' ? 'is-active' : '' ?>" data-status="declined">
                        <span>Declined</span>
                        <span class="tab-count"><?= $applicationCounts['declined'] ?></span>
                    </button>
                <?php else: ?>
                    <button type="button" class="settings-tab-btn <?= $filter === 'all' ? 'is-active' : '' ?>" data-status="all">
                        <span>All</span>
                        <span class="tab-count"><?= $activeCounts['all'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= $filter === 'pending' ? 'is-active' : '' ?>" data-status="pending">
                        <span>Pending</span>
                        <span class="tab-count"><?= $activeCounts['pending'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= ($filter === 'verified' || $filter === 'approved') ? 'is-active' : '' ?>" data-status="verified">
                        <span>Approved</span>
                        <span class="tab-count"><?= $activeCounts['verified'] ?></span>
                    </button>
                    <button type="button" class="settings-tab-btn <?= ($filter === 'rejected' || $filter === 'declined') ? 'is-active' : '' ?>" data-status="declined">
                        <span>Declined</span>
                        <span class="tab-count"><?= $activeCounts['declined'] ?? $activeCounts['rejected'] ?></span>
                    </button>
                <?php endif; ?>
            </nav>

            <!-- Search Bar (Styled exactly like the gallery of dorms) -->
            <label class="um-search">
                <svg aria-hidden="true"><use href="#search-icon"/></svg>
                <input id="verif-search" type="search" placeholder="Search records..." autocomplete="off">
            </label>
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

        <!-- Horizontal Cards (Styled EXACTLY like landlord/tenants.php) -->
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
                    $appId = (int)$application['id'];
                    $applicationName = trim(($application['first_name'] ?? '') . ' ' . ($application['middle_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
                    if ($applicationName === '') $applicationName = (string)($application['full_name'] ?? $application['email']);
                    $applicationStatus = (string)$application['status'];
                    $idPath = (string)($application['government_id_file'] ?? '');
                    $documentUrl = $idPath !== '' ? 'application-document.php?id=' . $appId : '';
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
                                <h3><?= htmlspecialchars($applicationName) ?></h3>
                                <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
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
                    $userId = (int)$item['id'];
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

                    $statusLabel = ($st === 'verified' ? 'Approved' : ($st === 'rejected' ? 'Declined' : ($st === 'pending' ? 'Pending' : 'Incomplete')));
                    $badgeClass = ($st === 'verified' ? 'approved' : ($st === 'rejected' ? 'declined' : ($st === 'pending' ? 'pending' : 'unverified')));

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
                    $isInitVisible = ($filter === 'all' || $st === $filter || ($filter === 'approved' && $st === 'verified') || ($filter === 'declined' && $st === 'rejected'));
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
                                <h3><?= htmlspecialchars($name) ?></h3>
                                <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
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
                                    <span class="dot">&bull;</span>
                                    <span>Account #<?= $userId ?></span>
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
                        <button type="submit" class="btn-approve" style="padding:7px 16px;">Approve</button>
                    </form>
                    <button type="button" class="btn-reject" id="inspectRejectBtn" style="padding:7px 16px;">Decline</button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div id="rejectModal" class="inspect-modal" onclick="if(event.target===this)closeRejectModal()">
        <div class="inspect-box" style="max-width: 520px;">
            <header class="inspect-header">
                <h3 style="margin:0; color:#991b1b; font-size:1.15rem;">Decline Verification</h3>
                <button type="button" class="btn-inspect" onclick="closeRejectModal()">✕</button>
            </header>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="reject_user">
                <input type="hidden" name="target_user_id" id="rejectTargetId" value="0">
                <input type="hidden" name="target_role" id="rejectTargetRole" value="<?= htmlspecialchars($role) ?>">
                <div style="padding: 20px 24px;">
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
                    <textarea id="rejectionNotesText" name="rejection_notes" rows="4" style="width:100%; border:1px solid #ebd9cb; border-radius:10px; padding:10px; font-family:inherit; font-size:0.9rem;" required>Document photo is blurry or illegible. Please submit a high-quality scan or photo of your credentials.</textarea>
                </div>
                <footer class="inspect-footer">
                    <button type="button" class="btn-inspect" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn-reject" style="background:#dc2626; color:#fff; border:none; padding:8px 16px;">Confirm Decline</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Client-side Drawer, Tabs, and Modals Script -->
    <script>
        var currentModalUserId = 0;
        var currentModalUserName = '';
        var currentModalRole = '<?= htmlspecialchars($role) ?>';

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
                document.getElementById('inspectSub').textContent = 'Role: ' + (role === 'landlord' ? 'Dorm Landlord' : 'Student Tenant') + ' (ID #' + userId + ')';
                if (approveForm) approveForm.style.display = (userId > 0) ? '' : 'none';
                if (rejectBtn) rejectBtn.style.display = (userId > 0) ? '' : 'none';
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
                    openRejectModal(userId, name, currentModalRole);
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

        function openRejectModal(userId, name, role) {
            document.getElementById('rejectTargetId').value = userId;
            document.getElementById('rejectTargetName').textContent = name;
            document.getElementById('rejectTargetRole').value = role || '<?= htmlspecialchars($role) ?>';
            document.getElementById('rejectModal').classList.add('is-active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('is-active');
        }

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
            statusTabs = document.querySelector('.settings-tabs');
            statusPill = statusTabs ? statusTabs.querySelector('.settings-tabs-indicator') : null;
            statusButtons = statusTabs ? Array.from(statusTabs.querySelectorAll('.settings-tab-btn')) : [];
            statusPillReady = false;

            var activeBtn = statusTabs ? statusTabs.querySelector('.settings-tab-btn.is-active') : null;
            currentStatusFilter = activeBtn ? (activeBtn.dataset.status || 'all') : 'all';

            searchInput = document.getElementById('verif-search');
            cards = Array.from(document.querySelectorAll('.tenant-card'));
            emptyState = document.getElementById('verifEmptyState');

            if (searchInput) {
                searchInput.addEventListener('input', updateCardFilter);
            }

            statusButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    statusButtons.forEach(function(b) {
                        b.classList.remove('is-active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    btn.classList.add('is-active');
                    btn.setAttribute('aria-selected', 'true');

                    placeStatusPill(false);

                    var st = btn.dataset.status || 'all';
                    currentStatusFilter = st;
                    updateCardFilter();

                    if (window.history && window.history.replaceState) {
                        var u = new URL(window.location.href);
                        u.searchParams.set('filter', st);
                        window.history.replaceState(null, '', u.toString());
                    }
                });
            });

            placeStatusPill(true);
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

            var heroSection = '<div class="drawer-hero">' +
                '<div class="hero-top">' +
                    '<div class="hero-avatar">' + avatarHtml + '</div>' +
                    '<div class="hero-info">' +
                        '<h3>' + escapeHtml(data.name) + '</h3>' +
                        '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">' +
                            '<span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:3px 8px; border-radius:6px; background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe;">' + roleChipText + '</span>' +
                            '<span class="badge ' + escapeHtml(data.badgeClass) + '">' + escapeHtml(data.statusLabel) + '</span>' +
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
                    ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.proofUrl) + '\', \'Proof of Ownership\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'landlord\')">Inspect 🔍</button>'
                    : '';

                // Business Permit document
                var permitThumb = data.permitHas
                    ? '<img src="' + escapeHtml(data.permitUrl) + '" alt="Business Permit">'
                    : '&#10060;';
                var permitInspectBtn = data.permitHas
                    ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.permitUrl) + '\', \'Business Permit\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'landlord\')">Inspect 🔍</button>'
                    : '';

                bodySectionsHtml += '<div class="detail-section">' +
                    '<div class="detail-section-head"><h4>Verification Documents</h4></div>' +
                    '<div class="verif-doc-box ' + (!data.proofHas ? 'is-missing' : '') + '">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.proofHas ? 'onclick="openInspectModal(\'' + escapeHtml(data.proofUrl) + '\', \'Proof of Ownership\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'landlord\')"' : '') + '>' + proofThumb + '</div>' +
                            '<div class="doc-details"><strong>Proof of Ownership</strong><span>' + (data.proofHas ? escapeHtml(data.proofName) : 'Not uploaded') + '</span></div>' +
                        '</div>' +
                        proofInspectBtn +
                    '</div>' +
                    '<div class="verif-doc-box ' + (!data.permitHas ? 'is-missing' : '') + '" style="margin-top: 10px;">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.permitHas ? 'onclick="openInspectModal(\'' + escapeHtml(data.permitUrl) + '\', \'Business Permit\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'landlord\')"' : '') + '>' + permitThumb + '</div>' +
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
                        ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.doc1Url) + '\', \'' + escapeHtml(data.doc1Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'' + data.type + '\')">Inspect 🔍</button>'
                        : '';
                    docsListHtml += '<div class="verif-doc-box ' + (!data.doc1Has ? 'is-missing' : '') + '">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.doc1Has ? 'onclick="openInspectModal(\'' + escapeHtml(data.doc1Url) + '\', \'' + escapeHtml(data.doc1Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'' + data.type + '\')"' : '') + '>' + d1Thumb + '</div>' +
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
                        ? '<button type="button" class="btn-inspect" onclick="openInspectModal(\'' + escapeHtml(data.doc2Url) + '\', \'' + escapeHtml(data.doc2Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'' + data.type + '\')">Inspect 🔍</button>'
                        : '';
                    docsListHtml += '<div class="verif-doc-box ' + (!data.doc2Has ? 'is-missing' : '') + '" style="margin-top:10px;">' +
                        '<div class="doc-thumbnail-wrap">' +
                            '<div class="doc-thumb" ' + (data.doc2Has ? 'onclick="openInspectModal(\'' + escapeHtml(data.doc2Url) + '\', \'' + escapeHtml(data.doc2Title) + '\', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', ' + data.id + ', \'' + data.type + '\')"' : '') + '>' + d2Thumb + '</div>' +
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
                    footerHtml = '<form method="post">' +
                        '<input type="hidden" name="view" value="properties">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="property_id" value="' + data.id + '">' +
                        '<textarea name="rejection_notes" rows="2" maxlength="300" placeholder="Optional notes for landlord if declining..." style="width:100%; padding:9px 12px; border:1px solid #d8c3b4; border-radius:8px; font:inherit; font-size:0.85rem; resize:vertical;"></textarea>' +
                        '<div class="drawer-footer-actions">' +
                            '<button class="btn-approve" name="action" value="approve_property" type="submit">Approve Listing</button>' +
                            '<button class="btn-reject" name="action" value="decline_property" type="submit">Decline</button>' +
                        '</div>' +
                    '</form>';
                } else if (data.status === 'approved') {
                    footerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">' +
                        '<span style="color:#15803d; font-weight:600; font-size:0.88rem;">&#10003; Discoverable by students</span>' +
                        '<form method="post" style="margin:0; width:auto;">' +
                            '<input type="hidden" name="view" value="properties">' +
                            '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                            '<input type="hidden" name="property_id" value="' + data.id + '">' +
                            '<button class="btn-reject" name="action" value="decline_property" type="submit">Revoke / Decline</button>' +
                        '</form>' +
                    '</div>';
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
                    footerHtml = '<form method="post">' +
                        '<input type="hidden" name="view" value="applications">' +
                        '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                        '<input type="hidden" name="application_id" value="' + data.id + '">' +
                        '<textarea name="feedback" rows="2" maxlength="500" placeholder="Optional decision feedback..." style="width:100%; padding:9px 12px; border:1px solid #d8c3b4; border-radius:8px; font:inherit; font-size:0.85rem; resize:vertical;"></textarea>' +
                        '<div class="drawer-footer-actions">' +
                            '<button class="btn-approve" name="action" value="approve_application" type="submit">Approve Application</button>' +
                            '<button class="btn-reject" name="action" value="decline_application" type="submit">Decline</button>' +
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
                        '<button type="button" class="btn-reject" onclick="closeVerifDrawer(); openRejectModal(' + data.id + ', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', \'' + data.type + '\');">Decline</button>' +
                    '</div>';
                } else if (data.status === 'verified') {
                    footerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">' +
                        '<span style="color:#15803d; font-weight:600; font-size:0.88rem;">&#10003; Verified ' + (data.verifiedAt ? 'on ' + escapeHtml(data.verifiedAt) : '') + '</span>' +
                        '<button type="button" class="btn-reject" style="flex:none; padding:8px 16px;" onclick="closeVerifDrawer(); openRejectModal(' + data.id + ', \'' + escapeHtml(data.name).replace(/'/g, "\\'") + '\', \'' + data.type + '\');">Revoke / Decline</button>' +
                    '</div>';
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
                } else if (document.getElementById('rejectModal').classList.contains('is-active')) {
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

        // Approval Confirmation Modal
        (function () {
            var modal = document.getElementById('confirmModal');
            var okBtn = document.getElementById('confirmModalOk');
            var text = document.getElementById('confirmModalText');
            var pendingForm = null, bypass = false;
            if (!modal) return;

            document.addEventListener('submit', function (e) {
                if (bypass) return;
                var form = e.target;
                var action = form.querySelector('input[name="action"]');
                if (!action || !/^approve_(user|landlord|tenant)$/.test(action.value)) return;
                e.preventDefault();
                pendingForm = form;
                var who = form.dataset.confirmName || currentModalUserName || 'this account';
                text.textContent = 'Approve verification for ' + who + '?';
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
                bypass = true;
                form.requestSubmit();
                bypass = false;
                closeConfirm();
            });

            modal.addEventListener('click', function (e) {
                if (e.target.closest('[data-confirm-close]')) { pendingForm = null; closeConfirm(); }
            });
            window.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { pendingForm = null; closeConfirm(); }
            });
        })();
    </script>
<script src="../assets/js/admin-verification-actions.js?v=1"></script>
</body>
</html>
