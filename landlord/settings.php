<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/notifications.php';

$authenticatedUser = require_signed_in('landlord');
$mandatoryProfileSetup = ($_SESSION['profile_onboarding_phase'] ?? '') === 'editing';
$pdo = database();
$user_id = (string)($_SESSION['user_id'] ?? '');

$successMessage = get_profile_success_flash() ?: '';
$passwordSuccessMessage = '';
$errorMessage = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function validate_landlord_id_image(array $file): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "An error occurred during file upload (Upload error code: {$file['error']}).";
    }
    if ($file['size'] < 10 * 1024) {
        return "Uploaded ID file is too small or blank (" . round($file['size'] / 1024, 1) . " KB). Please upload a clear photo of your ID (minimum 10 KB).";
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return "Uploaded ID file exceeds the 10 MB limit. Please upload a smaller image file.";
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
        }
    }
    if (empty($mime) && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }
    if (!empty($mime) && !in_array($mime, $allowedMimes, true)) {
        return "Invalid file format. Only JPG, PNG, or WEBP photos are accepted for ID verification.";
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) {
        return "The uploaded file is not a valid image. Please upload a genuine, legible photo of your ID.";
    }

    $width = (int)($imgInfo[0] ?? 0);
    $height = (int)($imgInfo[1] ?? 0);
    if ($width < 200 || $height < 120) {
        return "The image resolution ({$width}×{$height}px) is too small to verify as a legible ID. Minimum required resolution is 200×120 pixels.";
    }

    return null;
}


// Handle Actions
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $middleName    = trim($_POST['middle_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $phoneNumber   = trim($_POST['phone_number'] ?? '');
        $facebookLink  = trim($_POST['facebook_link'] ?? '');
        $personalEmail = trim($_POST['email'] ?? $_POST['personal_email'] ?? '');

        $phoneDigits = preg_replace('/[^\d]/', '', $phoneNumber);
        $phoneDigits = preg_replace('/^(?:63|0)/', '', $phoneDigits);
        $phoneNumber = $phoneDigits !== '' ? '+63' . $phoneDigits : '';
        $facebookHandle = preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $facebookLink);
        $facebookHandle = ltrim($facebookHandle, '/');
        $facebookLink = $facebookHandle !== '' ? 'https://facebook.com/' . $facebookHandle : '';

        // Validation
        if ($firstName === '') {
            $errorMessage = "First name is required.";
        } elseif ($lastName === '') {
            $errorMessage = "Last name is required.";
        } elseif (!preg_match('/^\+639\d{9}$/', $phoneNumber)) {
            $errorMessage = "Enter a valid 10-digit Philippine mobile number beginning with 9.";
        } elseif ($facebookLink === '') {
            $errorMessage = "Facebook link is required so tenants can contact you.";
        } elseif ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "A valid email address is required.";
        }

        if (empty($errorMessage)) {
            $existingUser = find_user_by_email($pdo, $personalEmail);
            if ($existingUser && !($existingUser['role'] === 'landlord' && (string)$existingUser['id'] === (string)$user_id)) {
                $errorMessage = "An account with this email address already exists.";
            }
        }

        if (empty($errorMessage) && isset($_FILES['gov_id'])) {
            $errorMessage = validate_landlord_id_image($_FILES['gov_id']) ?? '';
        }
        $currentFiles = $pdo->prepare('SELECT * FROM landlords WHERE landlord_id = ? LIMIT 1');
        $currentFiles->execute([$user_id]);
        $storedFiles = $currentFiles->fetch(PDO::FETCH_ASSOC) ?: [];
        $profilePhotoPath = (string)($storedFiles['profile_photo'] ?? '');
        $govIdPath = (string)($storedFiles['gov_id'] ?? '');
        $newGovIdUploaded = false;
        $newProfilePhotoUploaded = false;
        $removeGovId = ($_POST['remove_gov_id'] ?? '0') === '1';
        $uploadDir = __DIR__ . '/../uploads/';
        if (empty($errorMessage) && !is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $croppedData = (string)($_POST['cropped_profile_photo'] ?? '');
        if (empty($errorMessage) && $croppedData !== '') {
            $decoded = preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i', $croppedData)
                ? base64_decode(explode(',', $croppedData, 2)[1], true) : false;
            if ($decoded === false || strlen($decoded) > 10 * 1024 * 1024 || @getimagesizefromstring($decoded) === false) {
                $errorMessage = 'Please choose a valid profile image under 10 MB.';
            } else {
                $fileName = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents($uploadDir . $fileName, $decoded) === false) $errorMessage = 'Profile photo could not be saved.';
                else {
                    $profilePhotoPath = 'uploads/' . $fileName;
                    $newProfilePhotoUploaded = true;
                }
            }
        } elseif (empty($errorMessage) && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($_FILES['profile_photo']['tmp_name']) === false) {
                $errorMessage = 'Please choose a valid profile image.';
            } else {
                $fileName = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName)) {
                    $profilePhotoPath = 'uploads/' . $fileName;
                    $newProfilePhotoUploaded = true;
                } else $errorMessage = 'Profile photo could not be saved.';
            }
        }
        if (empty($errorMessage) && isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['gov_id']['name'], PATHINFO_EXTENSION));
            $fileName = 'govid_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $uploadDir . $fileName)) {
                $govIdPath = 'uploads/' . $fileName;
                $newGovIdUploaded = true;
            } else $errorMessage = 'Government ID could not be saved.';
        } elseif (empty($errorMessage) && $removeGovId) $govIdPath = '';

        $hasProfileChanges = (
            trim((string)($storedFiles['first_name'] ?? '')) !== $firstName ||
            trim((string)($storedFiles['middle_name'] ?? '')) !== $middleName ||
            trim((string)($storedFiles['last_name'] ?? '')) !== $lastName ||
            trim((string)($storedFiles['phone_number'] ?? '')) !== $phoneNumber ||
            trim((string)($storedFiles['facebook_link'] ?? '')) !== $facebookLink ||
            trim((string)($storedFiles['email'] ?? '')) !== $personalEmail ||
            $newProfilePhotoUploaded ||
            $newGovIdUploaded ||
            $removeGovId
        );

        if (empty($errorMessage) && $hasProfileChanges) {
        try {
            $updateStmt = $pdo->prepare("UPDATE landlords SET 
                first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                facebook_link = ?, email = ?,
                profile_photo = ?, gov_id = ?
                WHERE landlord_id = ?");

            $updateStmt->execute([
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName !== '' ? $lastName : null,
                $phoneNumber,
                $facebookLink,
                $personalEmail,
                $profilePhotoPath,
                $govIdPath,
                $user_id
            ]);

            // Update session
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName) ?: $firstName;
                $_SESSION['user']['phone_number'] = $phoneNumber;
                $_SESSION['user']['phone'] = $phoneNumber;
                if ($profilePhotoPath !== '') {
                    $_SESSION['user']['photo'] = $profilePhotoPath;
                }
            }

            $successMessage = "Profile updated successfully!";
            set_profile_success_flash("Profile updated successfully!");
        } catch (\PDOException $e) {
            $errorMessage = "Database update failed: " . $e->getMessage();
        }
        }
    } elseif ($_POST['action'] === 'update_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || strlen($newPassword) < 8) {
            $errorMessage = "New password must be at least 8 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirmation do not match.";
        } else {
            try {
                $st = $pdo->prepare("SELECT password_hash FROM landlords WHERE landlord_id = ? LIMIT 1");
                $st->execute([$user_id]);
                $dbPass = (string)$st->fetchColumn();

                $canUpdate = false;
                if ($dbPass === '' || password_verify($currentPassword, $dbPass)) {
                    $canUpdate = true;
                }

                if ($canUpdate) {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $up = $pdo->prepare("UPDATE landlords SET password_hash = ? WHERE landlord_id = ?");
                    $up->execute([$newHash, $user_id]);
                    $passwordSuccessMessage = "Password successfully updated!";
                } else {
                    $errorMessage = "The current password you entered is incorrect.";
                }
            } catch (\Exception $e) {
                $errorMessage = "Failed to update password: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_account') {
        $confirmEmail = strtolower(trim((string)($_POST['confirm_email'] ?? '')));
        try {
            $st = $pdo->prepare("SELECT email FROM landlords WHERE landlord_id = ? LIMIT 1");
            $st->execute([$user_id]);
            $uRow = $st->fetch();
            $realEmail = strtolower(trim((string)($uRow['email'] ?? ($uRow['personal_email'] ?? ''))));

            if ($confirmEmail !== '' && $confirmEmail === $realEmail) {
                $delRes = delete_user_account_completely($pdo, $user_id, 'landlord');
                if ($delRes['ok']) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    header('Location: ../index.php');
                    exit;
                } else {
                    $errorMessage = $delRes['message'];
                }
            } else {
                $errorMessage = "Email confirmation did not match your account email.";
            }
        } catch (\Exception $e) {
            $errorMessage = "Could not delete account: " . $e->getMessage();
        }
    }
}

// Fetch Current Landlord Data
$user = [
    'first_name'          => '',
    'middle_name'         => '',
    'last_name'           => '',
    'phone_number'        => '',
    'facebook_link'       => '',
    'email'               => '',
    'personal_email'      => '',
    'profile_photo'       => '',
    'photo'               => '',
    'gov_id'              => '',
    'gov_id_file'         => '',
    'status'              => 'approved',
    'role'                => 'Landlord'
];

try {
    $stmt = $pdo->prepare("SELECT * FROM landlords WHERE landlord_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['gov_id_file'] = $row['gov_id'] ?? '';
        $row['photo'] = $row['profile_photo'] ?? '';
        $row['personal_email'] = $row['email'] ?? '';
        $user = array_merge($user, $row);
    }
    foreach ($user as $k => $v) {
        if ($v === null) $user[$k] = '';
    }
} catch (\Exception $e) {}

$govIdDisplayUrl = (string)($user['gov_id'] ?: ($user['gov_id_file'] ?? ''));
if ($govIdDisplayUrl !== '' && str_contains(str_replace('\\', '/', $govIdDisplayUrl), 'uploads/landlord-applications/')) {
    $govIdDisplayUrl = '../admin/application-document.php?id=' . $user_id;
}

$rawStatus = strtolower(trim((string)($user['status'] ?? 'pending')));
$vStatus = ($rawStatus === 'declined' || $rawStatus === 'rejected') ? 'rejected' : (($rawStatus === 'approved') ? 'approved' : 'pending');

$landlordProfile = get_authenticated_profile($pdo, $user_id, 'landlord');
$sidebarPendingCount = get_landlord_pending_requests_count($pdo, $user_id);
$userInitials = $landlordProfile['initials'] ?? 'L';
$effectiveAvatar = $landlordProfile['photo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings · UniStay Landlord</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style id="landlord-tailwind-settings">
    /* landlord-tailwind:settings:start */
*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.\!visible{visibility:visible!important}.visible{visibility:visible}.collapse{visibility:collapse}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.-right-1{right:-.25rem}.-right-1\.5{right:-.375rem}.-top-1{top:-.25rem}.-top-1\.5{top:-.375rem}.block{display:block}.inline{display:inline}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.\!grid{display:grid!important}.grid{display:grid}.hidden{display:none}.h-24{height:6rem}.h-5{height:1.25rem}.w-5{width:1.25rem}.w-full{width:100%}.flex-shrink{flex-shrink:1}.border-collapse{border-collapse:collapse}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.flex-wrap{flex-wrap:wrap}.rounded{border-radius:.25rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.border{border-width:1px}.bg-indigo-600{--tw-bg-opacity:1;background-color:rgb(79 70 229/var(--tw-bg-opacity,1))}.bg-slate-900{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}.bg-slate-900\/70{background-color:rgba(15,23,42,.7)}.object-cover{-o-object-fit:cover;object-fit:cover}.text-xs{font-size:.75rem;line-height:1rem}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.italic{font-style:italic}.leading-none{line-height:1}.text-slate-600{--tw-text-opacity:1;color:rgb(71 85 105/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.underline{text-decoration-line:underline}.outline{outline-style:solid}.ring{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(3px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.blur{--tw-blur:blur(8px)}.blur,.drop-shadow{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.drop-shadow{--tw-drop-shadow:drop-shadow(0 1px 2px rgba(0,0,0,.1)) drop-shadow(0 1px 1px rgba(0,0,0,.06))}.grayscale{--tw-grayscale:grayscale(100%)}.filter,.grayscale{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}:root{color-scheme:light;--brown:#7a4b3a;--ink:#5f3425;--cream:#fcf9f4;--text:#584b45;--line:#e8ded6;--nav-dur:0.35s}*{box-sizing:border-box}html{scrollbar-width:none}html::-webkit-scrollbar{display:none}body.unistay-scroll-locked,html.unistay-scroll-locked{overflow:hidden!important;overscroll-behavior:none}[hidden]{display:none!important}body{margin:0;padding:18px 14px 0;background:var(--cream);color:var(--ink);font:15px/1.5 DM Sans,Segoe UI,system-ui,sans-serif}a,button,input{-webkit-tap-highlight-color:transparent}button{font:inherit;cursor:pointer}a{text-decoration:none;color:inherit}:focus-visible{outline:3px solid #ac7728;outline-offset:3px}svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}.symbols{position:absolute;width:0;height:0;overflow:hidden}.skip{position:fixed;top:-100px;left:20px;z-index:50;padding:10px;background:#fff}.skip:focus{top:20px}.sidebar{position:fixed;top:18px;bottom:12px;left:14px;width:259px;padding:20px 19px;background:radial-gradient(55% 55% at 78% 8%,rgba(176,117,92,.85) 0,rgba(176,117,92,0) 100%),radial-gradient(60% 55% at 12% 100%,rgba(166,107,82,.9) 0,rgba(166,107,82,0) 100%),#7a4b3a;color:var(--cream);border-radius:21px;display:flex;flex-direction:column;height:calc(100vh - 30px)}.brand,a.brand{display:flex;align-items:center;gap:10px;margin:0 1px 21px;font:700 22px Georgia,Times New Roman,serif;text-decoration:none;color:inherit;cursor:pointer;transition:opacity .2s ease}.brand:hover,a.brand:hover{opacity:.92}.brand img{width:50px;height:50px}nav{display:grid;gap:10px}.sidebar nav a{display:grid;grid-template-columns:19px minmax(0,1fr) auto;align-items:center;justify-content:normal;gap:12px;min-height:45px;padding:11px 15px;border-radius:11px;color:var(--cream);text-align:left;font-size:14px;font-weight:600;letter-spacing:.2px}.sidebar nav a svg{width:19px;height:19px}.sidebar nav a:hover{background:#ffffff12}.sidebar nav,.sidebar nav a{position:relative}.sidebar nav a{z-index:1;background:transparent;transition:color .24s ease,background .15s ease}.sidebar nav a:before{content:"";position:absolute;inset:0;border-radius:inherit;background:var(--cream);box-shadow:inset 5px 0 #e4cbbf;z-index:-1;opacity:0;transform:scale(.97);pointer-events:none;transition:opacity .24s cubic-bezier(.16,1,.3,1),transform .24s cubic-bezier(.16,1,.3,1);will-change:opacity,transform}.sidebar nav a[aria-current=page]{background:transparent!important;color:var(--brown)!important}.sidebar nav a[aria-current=page]:before{opacity:1;transform:scale(1)}.sidebar nav a.nav-fading-out{color:var(--cream)!important;transition:color .3s ease!important}.sidebar nav a.nav-fading-out:before{opacity:0!important;transform:scale(.96)!important;transition:opacity .32s ease,transform .32s ease!important}.sidebar nav a.nav-fading-out .nav-badge{background:var(--cream)!important;color:var(--brown)!important;transition:background .3s ease,color .3s ease!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]{color:var(--cream)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page]:before{opacity:0!important;transform:scale(.96)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page] .nav-badge{background:var(--cream)!important;color:var(--brown)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running{color:var(--brown)!important;transition:color .3s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running:before{opacity:1!important;transform:scale(1)!important;transition:opacity .32s cubic-bezier(.16,1,.3,1),transform .32s cubic-bezier(.16,1,.3,1)!important}html[data-nav-fadein] .sidebar nav a[aria-current=page].nav-fade-running .nav-badge{background:var(--brown)!important;color:var(--cream)!important;transition:background .3s cubic-bezier(.16,1,.3,1),color .3s cubic-bezier(.16,1,.3,1)!important}.nav-badge{display:grid;place-items:center;min-width:24px;height:20px;padding:0 8px;border-radius:999px;background:var(--cream);color:var(--brown);font-size:12px;font-weight:700;line-height:1;transition:background .22s ease,color .22s ease}.sidebar nav a[aria-current=page] .nav-badge{background:var(--brown);color:var(--cream)}.shell{margin-left:277px;min-height:calc(100vh - 30px);height:auto;padding:0 54px 30px;background:linear-gradient(180deg,hsla(36,71%,97%,.98),hsla(36,56%,95%,.98));border-radius:22px;box-shadow:0 30px 60px rgba(92,62,48,.12);flex-direction:column}.shell,.topbar{position:relative;display:flex}.topbar{z-index:1000;height:80px;align-items:center;justify-content:space-between;border-bottom:1px solid #c9a797;gap:20px}h1{margin:0;font:700 34px/1.2 DM Sans,Segoe UI,system-ui,sans-serif;letter-spacing:-1.2px}.dorms-page h1,.map-page h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-weight:700;letter-spacing:-.6px}.profile{display:flex;align-items:center;gap:26px;padding-right:3px;position:relative;z-index:1001}.btn-topbar-guide{position:relative;display:grid;place-items:center;width:28px;height:34px;padding:0;border:0;border-radius:50%;color:var(--brown);cursor:pointer;transition:color .18s ease,transform .18s ease;margin-right:0;text-decoration:none}.btn-topbar-guide,.btn-topbar-guide:hover{background:transparent!important;box-shadow:none!important}.btn-topbar-guide:hover{border-color:transparent!important;transform:scale(1.08);color:#5c3528}.btn-topbar-guide svg{width:27px;height:27px;stroke-width:2.1;color:var(--brown);transition:transform .18s ease}.btn-topbar-guide svg circle{transition:fill .18s ease,stroke .18s ease}.btn-topbar-guide svg line,.btn-topbar-guide svg path{transition:stroke .18s ease}.btn-topbar-guide:active svg circle,.btn-topbar-guide:hover svg circle,.btn-topbar-guide[aria-expanded=true] svg circle{fill:var(--brown);stroke:var(--brown)}.btn-topbar-guide:active svg line,.btn-topbar-guide:active svg path,.btn-topbar-guide:hover svg line,.btn-topbar-guide:hover svg path,.btn-topbar-guide[aria-expanded=true] svg line,.btn-topbar-guide[aria-expanded=true] svg path{stroke:#fff}.btn-topbar-guide:active{color:#4a2a1e;transform:scale(.92)}.btn-topbar-guide:active svg circle{fill:#4a2a1e;stroke:#4a2a1e}.btn-topbar-guide span{display:none!important}.topbar-auth{display:flex;align-items:center;gap:12px}.topbar-auth .btn-topbar-login{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--brown);background:var(--brown);color:#fff;cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-login:hover{color:#fff;background:#5c3527;border-color:#5c3527;transform:scale(1.1)}.topbar-auth .btn-topbar-login:active{color:#fff;background:#452618;border-color:#452618}.topbar-auth .btn-topbar-signup{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid #e8ddd3;background:#fdfaf5;color:var(--brown);cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;text-decoration:none}.topbar-auth .btn-topbar-signup:hover{filter:brightness(.95);transform:scale(1.1)}.bell{position:relative;display:grid;place-items:center;width:28px;height:34px;border:0;border-radius:50%;padding:0;color:var(--brown);background:transparent;cursor:pointer;transition:color .18s ease,background-color .18s ease,box-shadow .18s ease,transform .18s ease}.bell svg{width:27px;height:27px;stroke-width:2.1;transition:fill .18s ease,transform .18s ease}.notification-badge{position:absolute;top:-4px;right:-8px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#a33d2d;color:#fff;font:600 9px/1 Segoe UI,system-ui,sans-serif}.notification-badge[hidden]{display:none}.bell:hover svg{fill:currentColor}.bell:active{color:#4a2a1e;transform:scale(.92)}.bell[aria-expanded=true] svg{fill:currentColor}.avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;border:0;padding:0;background:#f0e3d4 50%/cover no-repeat;color:var(--brown);font-weight:700;font-size:14px;cursor:pointer;overflow:hidden;position:relative;transition:box-shadow .18s ease,transform .18s ease}.avatar img,.avatar-img{width:100%;height:100%;border-radius:50%;-o-object-fit:cover;object-fit:cover;display:block}.avatar:hover{box-shadow:0 0 0 3px var(--line)}.avatar[aria-expanded=true]{box-shadow:0 0 0 3px #e4cbbf}.avatar:active{transform:scale(.94)}main{padding-top:38px}.summary{display:grid;width:min(100%,600px);margin-inline:auto;grid-template-columns:repeat(4,minmax(0,1fr));gap:30px}.card{position:relative;min-height:142px;padding:26px 20px 20px;border-radius:21px;background:var(--cream);box-shadow:6px 7px 9px #00000006}.card>svg{position:absolute;top:23px;right:20px;width:18px;height:18px}.card:nth-child(n+3)>svg{color:#75652e}.label{color:var(--text);font-size:12px;font-weight:500;padding-right:24px;text-transform:uppercase}.value{font-size:20px;font-weight:600;margin:5px 0 7px;line-height:1.4}.value small{font-weight:400}.detail,.value small{font-size:14px;color:var(--text)}.dashboard-grid{display:grid;width:min(100%,600px);grid-template-columns:1.55fr 1fr;gap:44px;margin:25px auto 0}h2{margin:0 0 4px 4px;font-size:26px;font-weight:650;letter-spacing:-.8px;line-height:1.45}.placeholder{height:333px;background:#d9d9d9;border-radius:16px}a.placeholder{display:block}.dormitories{margin-top:22px}.popover{position:absolute;z-index:1;top:calc(100% + 14px);right:0;width:340px;max-width:calc(100vw - 72px);border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08);overflow:hidden}html body .topbar:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}html body .profile:not(.is-popover-open):not(:has(.popover:not([hidden]))){z-index:1!important}.topbar.is-popover-open,.topbar:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.profile.is-popover-open,.profile:has(.popover:not([hidden]):not(.is-closing)){z-index:999999!important}.popover:not([hidden]):not(.is-closing){z-index:1000000!important}.popover h2{margin:0;padding:12px 16px;background:var(--cream);border-bottom:1px solid var(--line);font-size:13px;letter-spacing:0}.empty{text-align:center;padding:26px 16px 24px}.empty-icon{display:grid;place-items:center;margin:0 auto 12px;width:38px;height:38px;border-radius:50%;color:#93867e;background:var(--cream)}.empty strong{display:block;font-size:13px;font-weight:500}.empty p{margin:4px 0 0;color:var(--text);font-size:11px}.notification-list{list-style:none;margin:0;padding:0;max-height:min(340px,45vh);overflow:auto}.notification-row{display:flex;align-items:stretch;border-top:1px solid var(--line);background:#fff}.notification-entry{display:grid;grid-template-columns:28px minmax(0,1fr);gap:4px 10px;flex:1;min-width:0;text-align:left;padding:14px 16px;background:#fff;color:var(--ink);border:0}.notification-entry.is-unread{background:#fcf7ef}.notification-entry:hover{background:var(--cream)}.notification-entry .entry-icon{grid-column:1;grid-row:1/4;display:grid;place-items:center;width:28px;height:28px;border-radius:9px;background:#f0e3d4;color:var(--brown);font-style:normal}.notification-entry strong{grid-column:2;display:block;font-size:13px}.notification-entry span{grid-column:2;display:block;font-size:12px;color:var(--text);margin-top:0}.notification-entry small{grid-column:2;display:block;font-size:10px;color:var(--brown);margin-top:3px}.notification-delete{margin:0 10px 0 0;padding:6px 8px}.notification-delete,.notification-mark-read{align-self:center;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--brown);font-size:11px}.notification-mark-read{margin:0 12px 0 4px;padding:5px 10px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .18s ease;flex-shrink:0}.notification-mark-read:hover{background:#f0e3d4;border-color:#d1bba4;color:#4a2511;transform:translateY(-1px)}.notification-delete:hover{background:#f3e8db}.notification-actions{display:flex;justify-content:flex-end;gap:8px;padding:8px 12px;border-top:1px solid var(--line);background:var(--cream)}.notification-actions button{border:0;padding:5px 7px;background:transparent;color:var(--brown);font-size:11px}.notification-actions button:hover{text-decoration:underline}.view-all{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;border:0;border-top:1px solid var(--line);padding:10px 16px;background:var(--cream);color:var(--text);font-size:12px}.view-all svg{width:15px;height:15px}.view-all:hover{color:var(--brown);text-decoration:none}::-webkit-scrollbar-track{background:transparent!important}*{scrollbar-color:#7a4b3a transparent}.profile-popover{width:260px}.profile-summary{display:flex;align-items:center;gap:12px;padding:16px}.profile-photo{position:relative;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#f0e3d4;color:var(--brown);font-weight:700;font-size:15px}.profile-photo img{position:absolute;inset:0;width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-identity{display:grid;gap:2px;min-width:0}.profile-identity strong{font-size:14px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-role{font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--text)}.profile-role-row{display:flex;align-items:center;gap:6px;min-width:0;line-height:1.2}.profile-role-sep{color:#a89487;font-size:8px;line-height:1}.profile-view-link{font-size:12px;font-weight:700;color:var(--brown,#7a4b3a);text-decoration:none!important;white-space:nowrap;transition:color .15s ease,background .15s ease;padding:2px 6px;border-radius:6px}.profile-view-link:hover{text-decoration:none!important;color:#3b1e10;background:rgba(122,75,58,.08)}.profile-view-link:active{text-decoration:none!important;color:#261109;background:rgba(122,75,58,.16)}.profile-popover .view-all{text-decoration:none!important;transition:background .15s ease,color .15s ease}.profile-popover .view-all:hover{text-decoration:none!important;background:#f5ece2;color:#3b1e10}.profile-popover .view-all:active{text-decoration:none!important;background:#ebdec9;color:#261109}.profile-logout{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;text-decoration:none!important}.profile-logout:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-logout:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@media (prefers-reduced-motion:no-preference){.popover{animation:popover-in .18s ease}.popover.is-closing{animation:popover-out .16s ease forwards}.bell[aria-expanded=true] svg{animation:bell-pop .45s ease}}@keyframes popover-in{0%{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}@keyframes popover-out{0%{opacity:1;transform:none}to{opacity:0;transform:translateY(-6px) scale(.98)}}@keyframes bell-pop{0%{transform:scale(1)}35%{transform:scale(1.18) rotate(-8deg)}70%{transform:scale(.94) rotate(4deg)}to{transform:scale(1)}}.notification-toast{position:fixed;z-index:100000;right:24px;bottom:24px;display:flex;flex-direction:column;justify-content:center;gap:8px;width:min(400px,calc(100vw - 32px));min-height:108px;box-sizing:border-box;padding:16px 20px;border:none;border-radius:16px;background:#ecdccb;color:#3b2820;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);opacity:0;transform:translateY(24px) scale(.96);pointer-events:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1)}.notification-toast.is-visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}.notification-toast.is-hiding{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.notification-toast .toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%}.notification-toast strong{font-size:15px;font-weight:700;color:#3b2820;line-height:1.35}.notification-toast span{font-size:13.5px;font-weight:400;color:#5a4236;line-height:1.45;display:block}.notification-toast .toast-close-btn{background:transparent;border:none;color:#7a4b3a;font-size:24px;line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;transition:color .15s,transform .15s}.notification-toast .toast-close-btn:hover{color:#3b2820;transform:scale(1.15)}.notification-toast .toast-actions{display:flex;align-items:center;gap:10px;margin-top:4px}@media (prefers-reduced-motion:reduce){.notification-toast,.notification-toast.is-hiding{transition:none}}dialog{border:1px solid #ebd9cb;border-radius:24px;padding:0;width:540px;max-width:calc(100vw - 32px);max-height:85vh;color:#3b2820;background:#fffdf9;box-shadow:0 28px 70px rgba(55,31,22,.28);font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden}dialog::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}.dialog-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px 18px;border-bottom:1px solid #ebd9cb;background:#fffdf9}.dialog-header h2{margin:0;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:22px;color:#3b2820}.close{border:none;border-radius:0;width:auto;height:auto;padding:0;display:inline-flex;align-items:center;justify-content:center;color:#7a4b3a;background:none;cursor:pointer;box-shadow:none;font-size:26px;line-height:1;transition:color .15s ease,transform .15s ease}.close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.close:active{background:none;color:#1a0f0a;transform:scale(.92)}.close svg{width:18px;height:18px;fill:currentColor;stroke:currentColor}#feedback{padding:0 20px;font-size:12px}#feedback:empty{display:none}@media (min-width:901px){.dashboard-page .topbar{height:62px}.dashboard-page h1{font-size:28px;letter-spacing:-.7px}.dashboard-page .profile{gap:19px}.dashboard-page .bell,.dashboard-page .btn-theme-toggle,.dashboard-page .btn-topbar-guide{width:24px;height:30px}.dashboard-page .bell svg,.dashboard-page .btn-theme-toggle svg,.dashboard-page .btn-topbar-guide svg{width:22px;height:22px}.dashboard-page .avatar{width:32px;height:32px}.dashboard-page main{padding-top:30px}.dashboard-page .summary{width:min(100%,470px);grid-template-columns:repeat(4,minmax(0,1fr));gap:22px;align-items:stretch}.dashboard-page .card{min-height:167px;padding:19px 15px 14px;border-radius:19px}.dashboard-page .card>svg{top:19px;right:14px;width:15px;height:15px}.dashboard-page .label{padding-right:18px;font-size:10px;line-height:1.4}.dashboard-page .value{margin:3px 0 5px;font-size:16px;line-height:1.35}.dashboard-page .value small{font-size:11px}.dashboard-page .detail{font-size:11px;line-height:1.5}.dashboard-page .dashboard-grid{width:min(100%,470px);grid-template-columns:1.55fr 1fr;gap:34px;margin-top:21px}.dashboard-page h2{margin:0 0 4px 3px;font-size:20px;letter-spacing:-.4px;line-height:1.3}.dashboard-page .placeholder{height:260px;border-radius:12px}.dashboard-page .dormitories{margin-top:25px}}@media (max-width:1150px){.sidebar{width:220px}.shell{margin-left:238px;padding:0 28px 28px}.dashboard-grid{gap:24px}}@media (min-width:761px) and (max-width:900px){body{padding-right:6px;padding-left:4px}.sidebar{left:1.3vw;width:16.7vw;padding:12px 10px;border-radius:13px}.brand{gap:6px;margin:0 1px 10px;font-size:14px}.brand img{width:30px;height:30px}.sidebar nav{gap:4px}.sidebar nav a{grid-template-columns:14px minmax(0,1fr) auto;gap:6px;min-height:30px;padding:6px 8px;border-radius:7px;font-size:9px;letter-spacing:0}.sidebar nav a svg{width:14px;height:14px}.shell{margin-top:-10px;margin-left:18.8vw;padding:0 34px 4px 3.3vw;border-radius:12px 12px 0 0}.topbar{height:53px;gap:12px}h1{font-size:20px;letter-spacing:-.5px}.profile{gap:12px}.bell,.btn-theme-toggle,.btn-topbar-guide{width:20px;height:24px}.bell svg,.btn-theme-toggle svg,.btn-topbar-guide svg{width:18px;height:18px}.bell:after{width:7px;height:7px}.avatar{width:24px;height:24px}main{padding-top:19px}.summary{width:100%;gap:16px}.card{min-height:78px;padding:12px 11px 10px;border-radius:13px}.card>svg{top:12px;right:11px;width:13px;height:13px}.label{padding-right:16px;font-size:8px;line-height:1.35}.value{margin:2px 0 4px;font-size:14px;line-height:1.3}.value small{font-size:10px}.detail{font-size:8px;line-height:1.35}.dashboard-grid{width:100%;grid-template-columns:1.55fr 1fr;gap:24px;margin-top:15px}h2{margin:0 0 0 2px;font-size:16px;letter-spacing:-.3px;line-height:1.05}.placeholder{height:min(44.5vh,178px);border-radius:10px}.dormitories{margin-top:12px}}input[type=search]::-webkit-search-cancel-button{cursor:pointer}input[type=search]::-webkit-search-cancel-button:active,input[type=search]::-webkit-search-cancel-button:hover{cursor:pointer}@media (max-width:760px){body{padding:12px}.sidebar{position:static;width:auto;padding:14px;margin-bottom:12px;border-radius:17px}.brand{margin-bottom:14px}.brand img{width:40px;height:40px}nav{display:flex;gap:4px;flex-wrap:wrap}nav a{padding:8px 10px;min-height:38px;font-size:12px;gap:7px}.shell{margin:0;padding:0 20px 24px;border-radius:17px}h1{font-size:29px}.topbar{height:76px}.profile{gap:16px}.avatar{width:32px;height:32px}main{padding-top:24px}.dashboard-grid{grid-template-columns:1fr}.placeholder{height:260px}h2{font-size:24px}}@media (max-width:420px){.shell{padding:0 16px 24px}.summary{gap:12px}.card{padding:20px 14px;min-height:152px}.card>svg{position:static;margin-bottom:8px}.label{padding:0;font-size:10px}.value{font-size:17px}.detail{font-size:12px}.profile{gap:12px}.avatar{width:28px;height:28px}}.brand{overflow:hidden;padding-right:38px}.brand img{flex-shrink:0}.brand-name,.nav-label,.toggle-label{white-space:nowrap;overflow:hidden}.sidebar-toggle{display:none}@media (min-width:761px){.sidebar-toggle{display:grid;place-items:center;position:absolute;top:50%;right:-13px;transform:translateY(-50%);z-index:50;align-items:center;width:26px;height:26px;margin:0;padding:0;border:2px solid #fdf9f3;border-radius:50%;background:#633c2e;color:var(--cream);box-shadow:0 2px 8px rgba(0,0,0,.22);font-size:13px;font-weight:500;cursor:pointer;transition:background .2s ease,transform .3s ease}.sidebar-toggle:hover{background:#7a4b3a;color:var(--cream)}.toggle-circle{display:grid;place-items:center;flex:0 0 20px;width:20px;height:20px;border-radius:50%;background:transparent}.toggle-label{display:none}.toggle-circle svg{width:12px;height:12px;stroke-width:2.2;transition:transform .3s ease}.brand-name,.nav-badge,.nav-label,.toggle-label{transition:opacity .2s ease}html.sidebar-ready .sidebar{transition:width .3s ease}html.sidebar-ready .shell{transition:margin-left .3s ease}html.sidebar-collapsed .sidebar{width:88px}html.sidebar-collapsed .shell{margin-left:106px}html.sidebar-collapsed .brand-name,html.sidebar-collapsed .nav-badge,html.sidebar-collapsed .nav-label,html.sidebar-collapsed .toggle-label{opacity:0;pointer-events:none}html.sidebar-collapsed .brand{overflow:visible;padding-right:0}html.sidebar-collapsed .brand-name{display:none}html.sidebar-collapsed .brand img{width:42px;height:42px}html.sidebar-collapsed .sidebar-toggle{top:50%;right:-13px;transform:translateY(-50%)}html.sidebar-collapsed .toggle-circle svg{transform:rotate(180deg)}}@media (min-width:761px) and (max-width:900px){.sidebar-toggle{gap:0;padding:0;width:24px;height:24px}.sidebar-toggle,html.sidebar-collapsed .sidebar-toggle{top:50%;right:-12px;transform:translateY(-50%)}.toggle-circle{flex-basis:16px;width:16px;height:16px}.toggle-circle svg{width:10px;height:10px}html.sidebar-collapsed .sidebar{width:52px}html.sidebar-collapsed .admin-shell,html.sidebar-collapsed .shell{margin-left:calc(2.1vw + 52px)}}@media (min-width:901px){.admin-shell{margin-left:277px!important;transition:margin-left .24s cubic-bezier(.2,.8,.2,1)}html.sidebar-collapsed .admin-shell{margin-left:96px!important}}.logout-modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(30,15,10,.58);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}@keyframes logoutModalFadeOut{0%{opacity:1;backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}to{opacity:0;backdrop-filter:blur(0);-webkit-backdrop-filter:blur(0)}}@keyframes logoutCardPopOut{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(16px) scale(.92)}}.logout-modal-backdrop.is-active:not(.is-closing){opacity:1;pointer-events:auto}.logout-modal-backdrop.is-closing{pointer-events:none;animation:logoutModalFadeOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-card{background:#fff;border-radius:20px;max-width:420px;width:100%;padding:28px 28px 24px;box-shadow:0 20px 50px rgba(45,25,18,.28);border:1px solid #ebd9cb;transform:translateY(18px) scale(.94);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease;text-align:center}.logout-modal-backdrop.is-active:not(.is-closing) .logout-modal-card{transform:translateY(0) scale(1);opacity:1}.logout-modal-backdrop.is-closing .logout-modal-card{animation:logoutCardPopOut .22s cubic-bezier(.16,1,.3,1) forwards}.logout-modal-icon{margin:0 auto 16px;border:none;display:flex;align-items:center;justify-content:center;box-shadow:none}.logout-modal-icon,.logout-modal-logo-img{width:68px;height:68px;background:transparent;border-radius:50%}.logout-modal-logo-img{-o-object-fit:cover;object-fit:cover;display:block}.logout-modal-title{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:700;font-size:1.3rem;color:#292524;margin:0 0 8px}.logout-modal-desc{font-size:.92rem;color:#78665c;line-height:1.5;margin:0 0 24px}.logout-modal-actions{display:flex;gap:12px;justify-content:center}.btn-logout-cancel{flex:1;padding:12px 18px;border-radius:10px;background:#fbf8f5;border:1px solid #ebd9cb;color:#57534e;font-weight:600;font-size:.92rem;cursor:pointer;transition:background .18s ease,border-color .18s ease,color .18s ease;box-shadow:0 1px 3px rgba(0,0,0,.05)}.btn-logout-cancel:hover{background:#f5eeea;color:#292524}.btn-logout-cancel:active{background:#ede2db}.btn-logout-confirm{flex:1;padding:12px 18px;border-radius:10px;background:#dc2626;border:none;color:#fff!important;font-weight:600;font-size:.92rem;cursor:pointer;text-decoration:none!important;display:inline-flex;align-items:center;justify-content:center;transition:transform .18s cubic-bezier(.34,1.56,.64,1),background .18s ease,box-shadow .18s ease;box-shadow:0 2px 6px rgba(220,38,38,.25)}.btn-logout-confirm:hover{background:#b91c1c;transform:scale(1.05);box-shadow:0 6px 18px rgba(220,38,38,.35)}.btn-logout-confirm:active{background:#991b1b;transform:scale(1.09);box-shadow:0 8px 22px rgba(220,38,38,.45)}.admin-logout-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 16px;border-radius:999px;font-size:.84rem;font-weight:600;color:#7a4b3a!important;background:#fff;border:1px solid #dfc3b2;text-decoration:none!important;transition:all .18s;cursor:pointer;box-shadow:0 1px 3px rgba(60,30,20,.05)}.admin-logout-btn:hover{background:#7a4b3a;color:#fff!important;border-color:#7a4b3a;transform:translateY(-1px)}.sidebar nav a.nav-logout{margin-top:14px;border-top:1px solid hsla(0,0%,100%,.14);padding-top:12px;color:#f7dcd0}.sidebar nav a.nav-logout:hover{background:rgba(220,38,38,.22);color:#fff}.sidebar nav a.nav-logout svg{stroke:currentColor}html:not([data-theme=dark]) #notification-popover{background:#f3e8db!important;border:1px solid #e8ded6!important;color:#3b2820!important;box-shadow:0 18px 42px rgba(45,25,18,.22),0 6px 14px rgba(45,25,18,.08)!important;z-index:10000}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover .empty strong,html:not([data-theme=dark]) #notification-popover .notification-detail h3,html:not([data-theme=dark]) #notification-popover .notification-entry strong,html:not([data-theme=dark]) #notification-popover h2,html:not([data-theme=dark]) #notification-popover strong{color:#3b2820!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty p,html:not([data-theme=dark]) #notification-popover .notification-detail p,html:not([data-theme=dark]) #notification-popover .notification-entry span,html:not([data-theme=dark]) #notification-popover p,html:not([data-theme=dark]) #notification-popover span{color:#78665c!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover #view-all,html:not([data-theme=dark]) #notification-popover .notification-entry small,html:not([data-theme=dark]) #notification-popover .view-all,html:not([data-theme=dark]) #notification-popover small{color:#7a4b3a!important;background:transparent!important}html:not([data-theme=dark]) #notification-popover .empty,html:not([data-theme=dark]) #notification-popover .notification-actions,html:not([data-theme=dark]) #notification-popover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-list,html:not([data-theme=dark]) #notification-popover .notification-row{background:#f3e8db!important;border-color:#e8ddd3!important}html:not([data-theme=dark]) #notification-popover #popover-title,html:not([data-theme=dark]) #notification-popover h2{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-entry.is-unread{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .empty-icon,html:not([data-theme=dark]) #notification-popover .notification-entry .entry-icon{background:#f0e3d4!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-actions button,html:not([data-theme=dark]) #notification-popover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-mark-read{background:transparent!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .notification-entry:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html:not([data-theme=dark]) #notification-popover .view-all{background:#f3e8db!important;border-top:1px solid #e8ddd3!important;color:#7a4b3a!important}html:not([data-theme=dark]) #notification-popover .view-all:hover{background:#ead7c3!important;color:#5c3527!important}#notification-popover a:focus-visible,#notification-popover button:focus-visible{outline:2px solid #7a4b3a;outline-offset:-3px}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3}#notification-popover .notification-row .notification-mark-read{transition:transform .18s ease,color .18s ease;border-color:transparent}html:not([data-theme=dark]) #notification-popover .notification-row:active,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:hover{background:#ead7c3}html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:active .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3;border-color:transparent}#notification-popover .notification-detail{padding:16px;background:#f3e8db}#notification-popover .notification-detail[hidden],#notification-popover [hidden]{display:none}.notification-detail button{background:transparent;color:#7a4b3a;border:1px solid #d8c3b4;border-radius:8px;padding:7px 10px;cursor:pointer}.notification-detail h3{font-size:.95rem;color:#3b2820;margin:16px 0 10px}.notification-detail p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5;font-size:.85rem}#notification-popover .notification-list{overscroll-behavior:contain}#notification-popover .notification-detail,#notification-popover .notification-list{max-height:min(340px,45vh);overflow-y:auto}dialog.approval-contact-dialog{width:min(calc(100vw - 32px),480px);padding:0;border:0;border-radius:20px;background:#fffaf4;color:#3b2820;box-shadow:0 24px 60px rgba(55,31,22,.28)}dialog.approval-contact-dialog::backdrop{background:rgba(42,28,22,.55);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}.approval-contact-card{padding:26px}.approval-contact-icon{display:grid;place-items:center;width:52px;height:52px;margin-bottom:16px;border-radius:50%;background:#e5f4e8;color:#28733a;font-size:24px;font-weight:700}.approval-contact-card h2{margin:0 0 10px;font-family:Fraunces,Georgia,serif;font-size:1.55rem;color:#5f3425}.approval-contact-card p{margin:0;color:#6f5a4e;font-size:.92rem;line-height:1.6}.approval-contact-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.approval-contact-actions a,.approval-contact-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 16px;border-radius:9px;font:600 .85rem DM Sans,sans-serif;cursor:pointer;text-decoration:none;transition:transform .18s ease,background .18s ease,border-color .18s ease}.approval-contact-later{border:1px solid #d8c3b4;background:#fff;color:#6a4333}.approval-contact-view{border:1px solid #7a4b3a;background:#7a4b3a;color:#fff}.approval-contact-actions a:hover,.approval-contact-actions button:hover{transform:scale(1.04)}.approval-contact-view:hover{background:#5f3425;border-color:#5f3425;color:#fff}.approval-contact-actions a:active,.approval-contact-actions button:active{transform:scale(1.08)}.approval-contact-actions a:focus-visible,.approval-contact-actions button:focus-visible{outline:2px solid #7a4b3a;outline-offset:3px}@media (max-width:520px){.approval-contact-actions{flex-direction:column-reverse}.approval-contact-actions a,.approval-contact-actions button{width:100%}}.avatar span,.profile-photo .profile-initials{display:flex!important;align-items:center!important;justify-content:center!important;text-align:center!important;line-height:1!important;width:100%!important;height:100%!important}.profile-popover{width:290px;max-height:85vh;overflow-y:auto}.profile-switch-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted,#8c7569);padding:12px 16px 4px;border-top:1px solid var(--line,#ebd9cb)}.profile-accounts-list{display:flex;flex-direction:column;max-height:240px;overflow-y:auto;scrollbar-width:thin}.profile-account-item{display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer;text-decoration:none;color:inherit;transition:background .15s ease}.profile-account-item:hover{background:#fbf5ee}.profile-account-item.is-switching{opacity:.55;pointer-events:none}.profile-account-item .account-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-align:center;line-height:1;background:#f0e3d4;color:var(--brown,#7a4b3a);font-weight:700;font-size:13px;flex-shrink:0;overflow:hidden}.profile-account-item .account-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-account-item .account-details{min-width:0;flex:1}.profile-account-item .account-name{font-size:13px;font-weight:700;color:var(--head,#5f3425);display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-account-item .account-email{font-size:11px;color:var(--text-muted,#8c7569);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.account-role-badge{font-size:9px;padding:1px 6px;border-radius:999px;font-weight:600;background:#f3ece4;color:#6b3624;text-transform:capitalize}.account-role-badge.role-admin{background:#fef3c7;color:#92400e}.account-role-badge.role-landlord{background:#f0e3d4;color:#7a4b3a}.account-role-badge.role-tenant{background:#dcfce7;color:#166534}.profile-add-account{color:var(--brown,#7a4b3a)!important;font-size:13px!important;font-weight:700!important;padding:11px 16px!important;border-top:1px solid var(--line,#ebd9cb);text-decoration:none!important}.profile-add-account:hover{color:#3b1e10!important;background:#f5ece2!important;text-decoration:none!important}.profile-add-account:active{color:#261109!important;background:#ebdec9!important;text-decoration:none!important}@keyframes unistayShimmer{0%{background-position:-200% 0}to{background-position:200% 0}}.skeleton-shimmer{background:linear-gradient(90deg,#ece5df 25%,#f7f2ed 37%,#ece5df 63%)!important;background-size:400% 100%!important;animation:unistayShimmer 1.5s ease-in-out infinite!important}.skeleton-card{pointer-events:none!important;cursor:default!important;transform:none!important}.skeleton-card:hover{transform:none!important;box-shadow:0 6px 20px rgba(60,30,20,.06)!important;border-color:#ebd9cb!important}.skeleton-card-thumb{width:100%;aspect-ratio:16/10;border-radius:0}.skeleton-card-body{padding:20px 20px 18px;display:flex;flex-direction:column;gap:10px}.skeleton-line{height:14px;border-radius:6px}.skeleton-line-title{width:72%;height:20px}.skeleton-line-sub{width:48%;height:13px}.skeleton-line-price{width:38%;height:16px;margin-top:4px}.skeleton-card-btn{height:40px;width:100%;border-radius:10px;margin-top:10px}.profile-account-item{border:none;background:transparent;width:100%;text-align:left;font-family:inherit;font-size:inherit;box-sizing:border-box}.profile-switch-empty{padding:8px 16px;font-size:11px;color:var(--text-muted,#8c7569);font-style:italic}.admin-user-badge{position:relative!important;cursor:pointer!important;-webkit-user-select:none!important;-moz-user-select:none!important;user-select:none!important}#notification-page,#notification-popover .notification-detail,#notification-popover .notification-list,.full-inbox,.notification-list{scrollbar-width:thin;scrollbar-color:var(--brown,#7a4b3a) transparent}#notification-page::-webkit-scrollbar,#notification-popover .notification-detail::-webkit-scrollbar,#notification-popover .notification-list::-webkit-scrollbar,.full-inbox::-webkit-scrollbar,.notification-list::-webkit-scrollbar{width:8px;height:8px}#notification-page::-webkit-scrollbar-track,#notification-popover .notification-detail::-webkit-scrollbar-track,#notification-popover .notification-list::-webkit-scrollbar-track,.full-inbox::-webkit-scrollbar-track,.notification-list::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}#notification-page::-webkit-scrollbar-thumb,#notification-popover .notification-detail::-webkit-scrollbar-thumb,#notification-popover .notification-list::-webkit-scrollbar-thumb,.full-inbox::-webkit-scrollbar-thumb,.notification-list::-webkit-scrollbar-thumb{background:var(--brown,#7a4b3a);border:2px solid #f3e9df;border-radius:999px}#notification-page::-webkit-scrollbar-thumb:hover,#notification-popover .notification-detail::-webkit-scrollbar-thumb:hover,#notification-popover .notification-list::-webkit-scrollbar-thumb:hover,.full-inbox::-webkit-scrollbar-thumb:hover,.notification-list::-webkit-scrollbar-thumb:hover{background:#5f3425}.btn-action-reject,.btn-danger-outline,.btn-decline,.btn-delete,.btn-reject,.btn-settings-danger,.dorm-card-actions .dorm-delete-btn,.dorm-delete-btn,.rv-btn-danger{background:#fff!important;border:1px solid #fca5a5!important;color:#dc2626!important;box-shadow:0 2px 6px rgba(220,38,38,.08)!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important;font-size:13px!important;padding:9px 18px!important;border-radius:10px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;transform:scale(1)!important;transform-origin:center!important;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease!important;text-decoration:none!important}.btn-action-reject:hover:not(:disabled):not(.is-loading),.btn-danger-outline:hover:not(:disabled):not(.is-loading),.btn-decline:hover:not(:disabled):not(.is-loading),.btn-delete:hover:not(:disabled):not(.is-loading),.btn-reject:hover:not(:disabled):not(.is-loading),.btn-settings-danger:hover:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:hover:not(:disabled):not(.is-loading),.dorm-delete-btn:hover:not(:disabled):not(.is-loading),.rv-btn-danger:hover:not(:disabled):not(.is-loading){background:#fee2e2!important;border-color:#f87171!important;color:#b91c1c!important;transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(220,38,38,.2)!important}.btn-action-reject:active:not(:disabled):not(.is-loading),.btn-danger-outline:active:not(:disabled):not(.is-loading),.btn-decline:active:not(:disabled):not(.is-loading),.btn-delete:active:not(:disabled):not(.is-loading),.btn-reject:active:not(:disabled):not(.is-loading),.btn-settings-danger:active:not(:disabled):not(.is-loading),.dorm-card-actions .dorm-delete-btn:active:not(:disabled):not(.is-loading),.dorm-delete-btn:active:not(:disabled):not(.is-loading),.rv-btn-danger:active:not(:disabled):not(.is-loading){background:#fecaca!important;transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(220,38,38,.26)!important}.nav-badge-pill,.sidebar .nav-badge-pill{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font-family:DM Sans,sans-serif;font-size:.72rem;font-weight:700;line-height:1;margin-left:auto;box-shadow:0 1px 3px rgba(220,38,38,.35);pointer-events:none;transition:transform .2s cubic-bezier(.2,0,0,1),opacity .2s ease}.sidebar.collapsed .nav-badge-pill{display:none}@keyframes bellShake{0%{transform:rotate(0)}15%{transform:rotate(14deg)}30%{transform:rotate(-14deg)}45%{transform:rotate(10deg)}60%{transform:rotate(-10deg)}75%{transform:rotate(6deg)}to{transform:rotate(0)}}#notification-bell.bell-ringing,.bell.bell-ringing{animation:bellShake .8s ease-in-out!important}.unistay-lightbox-overlay,dialog.unistay-lightbox-modal{position:fixed;inset:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;margin:0;padding:24px;border:none;background:transparent;display:flex;align-items:center;justify-content:center;z-index:1000001;overflow:hidden;box-sizing:border-box}dialog.unistay-lightbox-modal:not([open]){display:none!important}dialog.unistay-lightbox-modal::backdrop{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);animation:backdrop-fade-in .26s ease both}dialog.unistay-lightbox-modal.is-closing::backdrop{animation:backdrop-fade-out .22s ease both}.unistay-lightbox-overlay{background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}.unistay-lightbox-overlay.is-open:not(.is-closing){opacity:1;pointer-events:auto}.unistay-lightbox-overlay.is-closing{opacity:0;pointer-events:none;transition:opacity .22s ease,backdrop-filter .22s ease,-webkit-backdrop-filter .22s ease}.unistay-lightbox-content{position:relative;max-width:90vw;max-height:88vh;display:flex;flex-direction:column;align-items:center;transform:scale(.92) translateY(12px);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease}.unistay-lightbox-overlay.is-open:not(.is-closing) .unistay-lightbox-content,dialog.unistay-lightbox-modal[open]:not(.is-closing) .unistay-lightbox-content{transform:scale(1) translateY(0);opacity:1}.unistay-lightbox-overlay.is-closing .unistay-lightbox-content,dialog.unistay-lightbox-modal.is-closing .unistay-lightbox-content{transform:scale(.94) translateY(10px);opacity:0;transition:transform .22s ease,opacity .2s ease}.unistay-lightbox-img{max-width:100%;max-height:80vh;-o-object-fit:contain;object-fit:contain;border-radius:14px;box-shadow:0 24px 50px rgba(0,0,0,.45)}.unistay-lightbox-caption{color:#fff;margin-top:14px;font-weight:600;font-size:1rem;text-align:center;text-shadow:0 1px 4px rgba(0,0,0,.5);max-width:80vw}.unistay-lightbox-close{position:absolute;top:-44px;right:0;background:none;border:none;border-radius:0;width:auto;height:auto;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;cursor:pointer;line-height:1;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.unistay-lightbox-close:hover{background:none;color:#e5d8cf;transform:scale(1.1)}.unistay-lightbox-close:active{background:none;color:#c9b7ad;transform:scale(.92)}.tenant-page .topbar .btn-theme-toggle{width:28px;height:34px;padding:0;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;color:var(--ink,#3b2820);transition:transform .18s ease,color .18s ease}.tenant-page .topbar .btn-theme-toggle svg{width:27px;height:27px;stroke-width:2.1}.tenant-page .topbar .btn-theme-toggle svg circle,.tenant-page .topbar .btn-theme-toggle svg path{transition:fill .18s ease,stroke .18s ease}.tenant-page .topbar .btn-theme-toggle:focus-visible,.tenant-page .topbar .btn-theme-toggle:hover{background:transparent!important;border:0!important;box-shadow:none!important;color:#7a4b3a;transform:scale(1.08)}.tenant-page .topbar .btn-theme-toggle:focus-visible{outline:2px solid currentColor;outline-offset:3px}.tenant-page .topbar .btn-theme-toggle:active svg path,.tenant-page .topbar .btn-theme-toggle:hover svg path{fill:currentColor}.tenant-page .topbar .btn-theme-toggle:active{background:transparent!important;color:#5c3527;transform:scale(.92)}html[data-theme=dark],html[data-theme=dark] .tenant-page,html[data-theme=dark] body{background:#1e1612!important;color:#d9c8bc!important}html[data-theme=dark] .dorms-page .shell,html[data-theme=dark] .shell,html[data-theme=dark] .tenant-page .shell{background:#382a22!important;border:1px solid #3d2d24!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .dorms-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;box-shadow:none!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .sidebar,html[data-theme=dark] aside.sidebar{background:linear-gradient(135deg,#4a372c,#3a2820)!important;border-right:1px solid #553e32!important}html[data-theme=dark] .brand,html[data-theme=dark] .brand .brand-name,html[data-theme=dark] .brand span,html[data-theme=dark] .sidebar .brand span,html[data-theme=dark] .sidebar .brand-name{color:#fff7f2!important}html[data-theme=dark] .sidebar nav a,html[data-theme=dark] .sidebar nav a .nav-label,html[data-theme=dark] .sidebar nav a span{color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a svg{stroke:#d9c8bc!important}html[data-theme=dark] .sidebar nav a:hover{background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .sidebar nav a[aria-current=page]{background:transparent!important;color:#d9c8bc!important}html[data-theme=dark] .sidebar nav a[aria-current=page]:before{background:hsla(25,28%,79%,.14)!important;opacity:1!important}html[data-theme=dark] #guide-launcher-btn,html[data-theme=dark] .btn-topbar-guide,html[data-theme=dark] .btn-topbar-guide svg,html[data-theme=dark] .btn-topbar-guide svg circle,html[data-theme=dark] .btn-topbar-guide svg line,html[data-theme=dark] .btn-topbar-guide svg path,html[data-theme=dark] .sidebar-toggle,html[data-theme=dark] .sidebar-toggle .toggle-circle,html[data-theme=dark] .sidebar-toggle .toggle-label,html[data-theme=dark] .sidebar-toggle svg{color:#d9c8bc!important;stroke:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle{background:transparent!important;border:0!important;box-shadow:none!important;color:#d9c8bc!important}html[data-theme=dark] .btn-theme-toggle svg,html[data-theme=dark] .btn-theme-toggle svg circle,html[data-theme=dark] .btn-theme-toggle svg path,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] .btn-theme-toggle:active svg circle,html[data-theme=dark] .btn-theme-toggle:hover svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active svg circle,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover svg circle{fill:#d9c8bc!important}html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:active,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:focus-visible,html[data-theme=dark] .tenant-page .topbar .btn-theme-toggle:hover{color:#d9c8bc!important}html[data-theme=dark] #notification-bell,html[data-theme=dark] #notification-bell svg,html[data-theme=dark] #notification-bell svg path,html[data-theme=dark] .topbar .bell,html[data-theme=dark] .topbar .bell svg,html[data-theme=dark] .topbar .bell svg path{color:#d9c8bc!important;stroke:#d9c8bc!important;fill:none!important}html[data-theme=dark] #notification-bell:active svg,html[data-theme=dark] #notification-bell:active svg path,html[data-theme=dark] #notification-bell:hover svg,html[data-theme=dark] #notification-bell:hover svg path,html[data-theme=dark] .topbar .bell:active svg,html[data-theme=dark] .topbar .bell:active svg path,html[data-theme=dark] .topbar .bell:hover svg,html[data-theme=dark] .topbar .bell:hover svg path{fill:#d9c8bc!important}@media (min-width:901px){.tenant-page.dashboard-page .topbar .btn-theme-toggle{width:24px;height:30px}.tenant-page.dashboard-page .topbar .btn-theme-toggle svg{width:22px;height:22px}}@media (min-width:761px) and (max-width:900px){.tenant-page .topbar .btn-theme-toggle{width:20px;height:24px}.tenant-page .topbar .btn-theme-toggle svg{width:18px;height:18px}}html[data-theme=dark] #notification-popover,html[data-theme=dark] .popover,html[data-theme=dark] .profile-popover{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 18px 42px rgba(0,0,0,.6),0 6px 14px rgba(0,0,0,.35)!important}html[data-theme=dark] #notification-popover h2,html[data-theme=dark] #popover-title,html[data-theme=dark] .popover h2{background:#241813!important;border-bottom:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty,html[data-theme=dark] #popover-empty{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty p,html[data-theme=dark] #notification-popover .empty strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-list{background:#241813!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-track,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] #notification-popover .notification-detail::-webkit-scrollbar-thumb,html[data-theme=dark] #notification-popover .notification-list::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] #notification-popover .notification-row{background:#241813!important;border-top:1px solid #453127!important}html[data-theme=dark] #notification-popover .notification-entry{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry.is-unread{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-entry:hover,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry{background:#38271e!important}html[data-theme=dark] #notification-popover .notification-entry span,html[data-theme=dark] #notification-popover .notification-entry strong{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-entry small{color:#e2ab94!important}html[data-theme=dark] #notification-popover .notification-entry .entry-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete,html[data-theme=dark] #notification-popover .notification-mark-read{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-delete:hover,html[data-theme=dark] #notification-popover .notification-mark-read:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail{background:#241813!important;color:#fff7f2!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] #notification-popover .notification-detail h3,html[data-theme=dark] #notification-popover .notification-detail p{color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .notification-detail button:hover{background:hsla(25,28%,79%,.25)!important}html[data-theme=dark] #notification-popover .notification-actions,html[data-theme=dark] #notification-popover .view-all,html[data-theme=dark] #view-all,html[data-theme=dark] .profile-popover .view-all{background:#241813!important;border-top:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all:hover,html[data-theme=dark] #view-all:hover,html[data-theme=dark] .profile-popover .view-all:hover{background:#2d1f19!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover .view-all svg,html[data-theme=dark] #view-all svg{stroke:#fff7f2!important;color:#fff7f2!important}html[data-theme=dark] .map-page .shell,html[data-theme=dark] .settings-page .shell,html[data-theme=dark] .tenant-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] .map-page .topbar,html[data-theme=dark] .settings-page .topbar,html[data-theme=dark] .tenant-page .topbar{background:transparent!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .map-page h1,html[data-theme=dark] .settings-page h1,html[data-theme=dark] .tenant-page .topbar h1,html[data-theme=dark] .tenant-page h1{color:#fff7f2!important}html[data-theme=dark] .tenant-page .stat-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .card-label,html[data-theme=dark] .tenant-page .card-suffix,html[data-theme=dark] .tenant-page .card-value,html[data-theme=dark] .tenant-page .stat-card-label,html[data-theme=dark] .tenant-page .stat-card-value{color:#fff7f2!important}html[data-theme=dark] .tenant-page .card-subtext,html[data-theme=dark] .tenant-page .card-subtext-link,html[data-theme=dark] .tenant-page .stat-card-desc{color:#fff7f2!important;opacity:.9}html[data-theme=dark] .tenant-page .stat-card-icon,html[data-theme=dark] .tenant-page .stat-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .current-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .tenant-page .current-booking-header{background:#241813!important;border-bottom:1px solid #453127!important}html[data-theme=dark] .tenant-page .current-booking-body{background:#241813!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-detail-label,html[data-theme=dark] .tenant-page .booking-detail-value,html[data-theme=dark] .tenant-page .booking-dorm-title,html[data-theme=dark] .tenant-page .booking-room-title,html[data-theme=dark] .tenant-page .current-booking-header h2,html[data-theme=dark] .tenant-page .section-title{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-meta-pill svg{color:#fff7f2!important;stroke:#fff7f2!important;fill:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn,html[data-theme=dark] .tenant-page .btn-action-outline,html[data-theme=dark] .tenant-page .btn-action-primary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-action-btn:hover,html[data-theme=dark] .tenant-page .btn-action-outline:hover,html[data-theme=dark] .tenant-page .btn-action-primary:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-message h3,html[data-theme=dark] .tenant-page .booking-empty-message p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .booking-empty-home{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-booking-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .tenant-page .booking-dorm-sub,html[data-theme=dark] .tenant-page .booking-room-heading,html[data-theme=dark] .tenant-page .booking-room-heading span,html[data-theme=dark] .tenant-page .booking-tenant-meta,html[data-theme=dark] .tenant-page .booking-tenant-meta span,html[data-theme=dark] .tenant-page .recent-bookings-empty h3,html[data-theme=dark] .tenant-page .recent-bookings-empty p{color:#fff7f2!important}html[data-theme=dark] .tenant-page .recent-bookings-empty-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details,html[data-theme=dark] .tenant-page .btn-manage-properties,html[data-theme=dark] .tenant-page .recent-bookings-view-all{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .btn-booking-details:hover,html[data-theme=dark] .tenant-page .btn-manage-properties:hover,html[data-theme=dark] .tenant-page .recent-bookings-view-all:hover{background:hsla(25,28%,79%,.25)!important;color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 16px 36px rgba(0,0,0,.5)!important}html[data-theme=dark] .tenant-page .profile-toast h3,html[data-theme=dark] .tenant-page .profile-toast p,html[data-theme=dark] .tenant-page .profile-toast-btn{color:#fff7f2!important}html[data-theme=dark] .tenant-page .profile-toast-icon{background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail{background:#5c4538!important;scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .map-page #um-detail::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:2px solid #241813!important}html[data-theme=dark] .map-page .map-panel-nav{background:#5c4538!important;border-bottom:1px solid #6e5344!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav span,html[data-theme=dark] .map-page .map-panel-nav strong{color:#fff7f2!important}html[data-theme=dark] .map-page .map-panel-nav .room-back-icon{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card,html[data-theme=dark] .map-page .map-dorm-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page #um-detail .dorm-card:hover{background:#2d1f19!important;border-color:#5d4235!important}html[data-theme=dark] .map-page .map-search-bar{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .map-page .map-search-bar input{background:transparent!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-tabs{border-top:1px solid #6e5344!important;border-bottom:1px solid #6e5344!important}html[data-theme=dark] .settings-page .settings-tab-btn{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-tab-btn:hover{color:#fff7f2!important;background:hsla(25,28%,79%,.08)!important}html[data-theme=dark] .settings-page .settings-tab-btn.is-active{color:#fff7f2!important;background:hsla(25,28%,79%,.14)!important;font-weight:700!important}html[data-theme=dark] .settings-page .settings-card{background:#241813!important;border:1px solid #453127!important;color:#fff7f2!important;box-shadow:0 4px 16px rgba(0,0,0,.4)!important}html[data-theme=dark] .settings-page .profile-view-header{border-bottom:1px solid #453127!important}html[data-theme=dark] .settings-page .field-label,html[data-theme=dark] .settings-page .form-label,html[data-theme=dark] .settings-page .profile-view-name,html[data-theme=dark] .settings-page .settings-card h2,html[data-theme=dark] .settings-page .settings-card h3,html[data-theme=dark] .settings-page .settings-card h4,html[data-theme=dark] .settings-page .settings-card label,html[data-theme=dark] .settings-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] .settings-page .field-hint,html[data-theme=dark] .settings-page .profile-view-email,html[data-theme=dark] .settings-page .settings-card p,html[data-theme=dark] .settings-page .settings-section-desc{color:#d9c8bc!important}html[data-theme=dark] .settings-page .settings-card input,html[data-theme=dark] .settings-page .settings-card select,html[data-theme=dark] .settings-page .settings-card textarea{background:#19110d!important;border:1px solid #453127!important;color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-card input:focus,html[data-theme=dark] .settings-page .settings-card select:focus,html[data-theme=dark] .settings-page .settings-card textarea:focus{border-color:#e2ab94!important;outline:none!important}html[data-theme=dark] .settings-page .btn-primary,html[data-theme=dark] .settings-page .btn-save-settings,html[data-theme=dark] .settings-page .settings-btn-primary{background:#e2ab94!important;color:#1e1612!important;font-weight:700!important}html[data-theme=dark] .settings-page .btn-secondary,html[data-theme=dark] .settings-page .settings-btn-secondary{background:hsla(25,28%,79%,.14)!important;border:1px solid hsla(25,28%,79%,.22)!important;color:#fff7f2!important}html[data-theme=dark] .settings-page #settings-main{scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:3px solid #241813!important}html[data-theme=dark] .settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#f0ded2!important}.auth-gate-close,.cl-dialog-close,.dorm-modal-close,.drawer-close,.id-lightbox-close,.landlord-profile-close-btn,.modal-close-btn,.policy-modal-close,.profile-toast-close,.tenant-drawer-close,.toast-close,.toast-close-btn,.unistay-lightbox-close,.us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#7a4b3a;transition:color .15s ease,transform .15s ease}.auth-gate-close:hover,.cl-dialog-close:hover,.dorm-modal-close:hover,.drawer-close:hover,.id-lightbox-close:hover,.landlord-profile-close-btn:hover,.modal-close-btn:hover,.policy-modal-close:hover,.profile-toast-close:hover,.tenant-drawer-close:hover,.toast-close-btn:hover,.toast-close:hover,.unistay-lightbox-close:hover,.us-confirm-close:hover{background:transparent!important;color:#3b2820!important;transform:scale(1.1)}.auth-gate-close:active,.cl-dialog-close:active,.dorm-modal-close:active,.drawer-close:active,.id-lightbox-close:active,.landlord-profile-close-btn:active,.modal-close-btn:active,.policy-modal-close:active,.profile-toast-close:active,.tenant-drawer-close:active,.toast-close-btn:active,.toast-close:active,.unistay-lightbox-close:active,.us-confirm-close:active{background:transparent!important;color:#1a0f0a!important;transform:scale(.92)}.auth-gate-close:focus-visible,.cl-dialog-close:focus-visible,.dorm-modal-close:focus-visible,.drawer-close:focus-visible,.id-lightbox-close:focus-visible,.landlord-profile-close-btn:focus-visible,.modal-close-btn:focus-visible,.policy-modal-close:focus-visible,.profile-toast-close:focus-visible,.tenant-drawer-close:focus-visible,.toast-close-btn:focus-visible,.toast-close:focus-visible,.unistay-lightbox-close:focus-visible,.us-confirm-close:focus-visible{outline:2px solid currentColor;outline-offset:3px}html[data-theme=dark] .auth-gate-close,html[data-theme=dark] .cl-dialog-close,html[data-theme=dark] .dorm-modal-close,html[data-theme=dark] .drawer-close,html[data-theme=dark] .id-lightbox-close,html[data-theme=dark] .landlord-profile-close-btn,html[data-theme=dark] .modal-close-btn,html[data-theme=dark] .policy-modal-close,html[data-theme=dark] .profile-toast-close,html[data-theme=dark] .tenant-drawer-close,html[data-theme=dark] .toast-close,html[data-theme=dark] .toast-close-btn,html[data-theme=dark] .unistay-lightbox-close,html[data-theme=dark] .us-confirm-close{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;color:#fff7f2!important}html[data-theme=dark] .auth-gate-close:hover,html[data-theme=dark] .cl-dialog-close:hover,html[data-theme=dark] .dorm-modal-close:hover,html[data-theme=dark] .drawer-close:hover,html[data-theme=dark] .id-lightbox-close:hover,html[data-theme=dark] .landlord-profile-close-btn:hover,html[data-theme=dark] .modal-close-btn:hover,html[data-theme=dark] .policy-modal-close:hover,html[data-theme=dark] .profile-toast-close:hover,html[data-theme=dark] .tenant-drawer-close:hover,html[data-theme=dark] .toast-close-btn:hover,html[data-theme=dark] .toast-close:hover,html[data-theme=dark] .unistay-lightbox-close:hover,html[data-theme=dark] .us-confirm-close:hover{background:transparent!important;color:#d8c3b4!important}html[data-theme=dark] .auth-gate-close:active,html[data-theme=dark] .cl-dialog-close:active,html[data-theme=dark] .dorm-modal-close:active,html[data-theme=dark] .drawer-close:active,html[data-theme=dark] .id-lightbox-close:active,html[data-theme=dark] .landlord-profile-close-btn:active,html[data-theme=dark] .modal-close-btn:active,html[data-theme=dark] .policy-modal-close:active,html[data-theme=dark] .profile-toast-close:active,html[data-theme=dark] .tenant-drawer-close:active,html[data-theme=dark] .toast-close-btn:active,html[data-theme=dark] .toast-close:active,html[data-theme=dark] .unistay-lightbox-close:active,html[data-theme=dark] .us-confirm-close:active{background:transparent!important;color:#b99f90!important}html[data-theme=dark] .profile-summary{background:#231a14!important;border-bottom:1px solid #3d2d24!important}html[data-theme=dark] .profile-photo{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-identity strong{color:#fff7f2!important}html[data-theme=dark] .profile-role{color:#a8948a!important}html[data-theme=dark] .profile-role-sep{color:#6c584c!important}html[data-theme=dark] .profile-view-link{color:#e2ab94!important}html[data-theme=dark] .profile-view-link:hover{background:hsla(18,57%,73%,.16)!important;color:#fff7f2!important}html[data-theme=dark] .profile-switch-header{color:#a8948a!important;border-top-color:#3d2d24!important}html[data-theme=dark] .profile-switch-empty{color:#a8948a!important}html[data-theme=dark] .profile-account-item{color:#d9c8bc!important}html[data-theme=dark] .profile-account-item:hover{background:#34261f!important}html[data-theme=dark] .profile-account-item .account-avatar{background:#34261f!important;color:#e2ab94!important}html[data-theme=dark] .profile-account-item .account-name{color:#fff7f2!important}html[data-theme=dark] .profile-account-item .account-email{color:#a8948a!important}html[data-theme=dark] .profile-add-account,html[data-theme=dark] .profile-logout{color:#e2ab94!important}html[data-theme=dark] .profile-logout:hover{background:#34261f!important;color:#fff7f2!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread){background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-entry.is-unread,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:transparent!important;border-color:transparent!important;box-shadow:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{color:#e2ab94!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):focus-within,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread):hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read:hover,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{color:#fff7f2!important;background:transparent!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button{background:transparent!important;border:0!important;box-shadow:none!important;color:#fff7f2!important;text-decoration:none!important}html:not([data-theme=dark]) #notification-popover .notification-row,html:not([data-theme=dark]) #notification-popover .notification-row .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row .notification-mark-read{background:#f3e8db!important}html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread),html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#fcf7ef!important}html:not([data-theme=dark]) #notification-popover .notification-row:focus-within,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:focus-within .notification-mark-read,html:not([data-theme=dark]) #notification-popover .notification-row:hover,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-delete,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-entry,html:not([data-theme=dark]) #notification-popover .notification-row:hover .notification-mark-read{background:#ead7c3!important}html[data-theme=dark] #notification-popover .notification-row,html[data-theme=dark] #notification-popover .notification-row .notification-delete,html[data-theme=dark] #notification-popover .notification-row .notification-entry,html[data-theme=dark] #notification-popover .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread),html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-entry,html[data-theme=dark] #notification-popover .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover .notification-row:focus-within,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-entry,html[data-theme=dark] #notification-popover .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover .notification-row:hover,html[data-theme=dark] #notification-popover .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover .notification-row:hover .notification-entry,html[data-theme=dark] #notification-popover .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row .notification-mark-read{background:#241813!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:has(.notification-entry.is-unread) .notification-mark-read{background:#2d1f19!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:focus-within .notification-mark-read,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-delete,html[data-theme=dark] #notification-popover:not(#_a) .notification-row:hover .notification-mark-read{background:#38271e!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:hover{background:transparent!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) .notification-actions button:active{background:transparent!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link,html[data-theme=dark] .profile-popover:not(#_a) .view-all{text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:hover{background:transparent!important;color:#cf967f!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .profile-view-link:active{background:transparent!important;color:#b57f6a!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:hover{color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] .profile-popover:not(#_a) .view-all:active{color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all,html[data-theme=dark] #notification-popover:not(#_a) .view-all{background:#241813!important;color:#fff7f2!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover{background:#241813!important;color:#d8c3b4!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active{background:#241813!important;color:#b99f90!important;text-decoration:none!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:hover svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:hover svg{stroke:#d8c3b4!important;color:#d8c3b4!important}html[data-theme=dark] #notification-popover:not(#_a) #view-all:active svg,html[data-theme=dark] #notification-popover:not(#_a) .view-all:active svg{stroke:#b99f90!important;color:#b99f90!important}body.tenant-page #clear-all,body.tenant-page #notification-popover .notification-actions,body.tenant-page #read-all,body.tenant-page .notification-page-heading{display:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar h1{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tabs,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-categories-bar{background:#5c4538!important;border-color:#6e5344!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab{color:#e2d3c8!important}html[data-theme=dark] body.admin-verification-page .settings-tab-btn.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab.is-active,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-tab:hover{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .settings-tabs-indicator,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .topbar-cat-indicator{background:#e2ab94!important}html[data-theme=dark] body.admin-users-page .tenants-table-card,html[data-theme=dark] body.admin-verification-page .tenant-card{background:#2b1f19!important;border-color:#4b382d!important;color:#e2d3c8!important}html[data-theme=dark] body.admin-users-page .tenants-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table td{color:#e2d3c8!important;border-bottom-color:#4b382d!important}html[data-theme=dark] body.admin-users-page .tenants-table tbody tr:hover,html[data-theme=dark] body.admin-verification-page .tenant-card:hover{background:#34261f!important}html[data-theme=dark] body.admin-users-page .tenants-table td strong,html[data-theme=dark] body.admin-verification-page .meta-value,html[data-theme=dark] body.admin-verification-page .tenant-head h3{color:#fff7f2!important}html[data-theme=dark] body.admin-verification-page .meta-label,html[data-theme=dark] body.admin-verification-page .tenant-sub{color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .tenant-meta{border-top-color:#4b382d!important}html[data-theme=dark] body.admin-verification-page .um-select-btn,html[data-theme=dark] body.admin-verification-page .um-select-menu,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search{background:#231a14!important;border-color:#563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.admin-verification-page .um-select-menu li,html[data-theme=dark] body.admin-verification-page .um-select-value,html[data-theme=dark] body:is(.admin-verification-page,.admin-users-page) .um-search input{color:#d9c8bc!important}html[data-theme=dark] body.admin-users-page .badge-status-suspended{color:#f87171!important}#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{position:fixed!important;right:24px!important;bottom:24px!important;width:min(400px,calc(100vw - 32px))!important;max-width:min(400px,calc(100vw - 32px))!important;min-width:min(400px,calc(100vw - 32px))!important;height:116px!important;min-height:116px!important;max-height:116px!important;box-sizing:border-box!important;padding:16px 20px!important;border-radius:16px!important;z-index:100000!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}@media (max-width:520px){#landing-toast,#notification-toast,#profileToast,.admin-verif-toast,.booking-toast,.notification-toast,.profile-toast,.room-booking-success-toast,.toast,.toast-popup{right:16px!important;bottom:16px!important;left:16px!important;width:auto!important;min-width:0!important;max-width:none!important;height:116px!important;min-height:116px!important;max-height:116px!important}}#confirmModal,#confirmModal *,#deleteModal,#deleteModal *,#dorm-modal,#dorm-modal *,#inspectModal,#inspectModal *,#logout-modal,#logout-modal *,#rejectModal,#rejectModal *,#resultModal,#resultModal *,#room-modal,#room-modal *,#suspend-modal,#suspend-modal *,.cl-dialog,.cl-dialog *,.confirm-modal-box,.confirm-modal-box *,.cropper-modal-card,.cropper-modal-card *,.delete-account-modal,.delete-account-modal *,.dialog,.dialog *,.inspect-box,.inspect-box *,.inspect-modal,.inspect-modal *,.logout-modal-card,.logout-modal-card *,.modal,.modal *,.modal-box,.modal-box *,.modal-card,.modal-card *,.modal-panel,.modal-panel *,.policy-modal,.policy-modal *,.policy-modal-panel,.policy-modal-panel *,.ps-card,.ps-card *,.settings-modal-card,.settings-modal-card *,.suspend-modal-dialog,.suspend-modal-dialog *,dialog,dialog *,dialog.us-confirm,dialog.us-confirm *{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}.cl-dialog,.confirm-modal-box,.cropper-modal-card,.delete-account-modal,.inspect-box,.logout-modal-card,.modal-box,.modal-card,.modal-panel,.policy-modal-panel,.ps-card,.settings-modal-card,.suspend-modal-dialog,dialog,dialog.us-confirm{border-radius:24px!important}#dorm-modal h2,#dorm-modal h3,#room-details-title,.cl-dialog h2,.cl-dialog h3,.cropper-modal-title,.delete-modal-title,.inspect-box h2,.inspect-box h3,.logout-modal-title,.modal h1,.modal h2,.modal h3,.modal h4,.modal-box h1,.modal-box h2,.modal-box h3,.modal-box h4,.modal-panel h1,.modal-panel h2,.modal-panel h3,.modal-panel h4,.policy-card h2,.policy-modal-header h2,.policy-modal-panel h2,.policy-modal-panel h3,.ps-card h2,.room-modal-header-title,.room-title-heading,.settings-modal-card h2,.settings-modal-card h3,.suspend-modal-dialog h2,.suspend-modal-dialog h3,dialog h1,dialog h2,dialog h3,dialog h4,dialog.us-confirm .us-confirm-panel h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px!important;font-weight:700!important;letter-spacing:-.2px!important;line-height:1.3!important}.cl-actions button,.cropper-modal-footer button,.delete-modal-actions button,.inspect-footer button,.policy-modal-footer button,.us-confirm-actions button,dialog button:not(.us-confirm-close):not(.cl-dialog-close):not(.policy-modal-close):not(.delete-modal-close):not(.cropper-btn-round):not(.modal-close):not(.close-btn):not([aria-label*=lose]):not([aria-label*=Dismiss]){border-radius:12px!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important}.verified-check{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:14px;height:14px;margin-left:5px;vertical-align:-2px;border-radius:50%;background:#10b981;color:#fff;overflow:visible}.verified-check svg{width:8px;height:8px;stroke-width:3.4;overflow:visible}.view-all,.view-all:active,.view-all:focus,.view-all:focus-visible,.view-all:hover{text-decoration:none!important}html[data-theme=dark] .shell-orbs{display:none!important}.settings-page .shell{min-height:calc(100vh - 30px);height:auto;border-radius:22px;padding:0 54px 48px}@media (min-width:761px){.settings-page .shell{min-height:calc(100vh - 30px);height:calc(100vh - 30px);padding:0 54px 24px;display:flex;flex-direction:column;overflow:hidden}.settings-page .shell:has(.popover:not([hidden])),.settings-page .shell:has(.topbar.is-popover-open){overflow:visible}.settings-page .topbar{flex-shrink:0}.settings-page #settings-main{flex:1;min-height:0;display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;padding-top:14px;margin:0 -54px 0 0;padding-right:42px;scrollbar-color:var(--brown) transparent;scrollbar-width:thin}.settings-page #settings-main::-webkit-scrollbar{width:12px}.settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important;border-radius:999px}.settings-page #settings-main::-webkit-scrollbar-thumb{background:#7a4b3a;border:3px solid #f3e9df;border-radius:999px}.settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#5c3527}.settings-page .settings-tabs{flex-shrink:0;width:100%;margin:0}.settings-page .settings-tab-content.is-active{display:block;width:100%;overflow:visible;padding-bottom:24px}}.settings-page .topbar{flex-shrink:0;position:relative;z-index:1000;height:auto;display:flex;flex-direction:column;align-items:stretch;border-bottom:1px solid #ebd9cb;padding:0;gap:0}.settings-page .topbar-main-row{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 4px;position:relative;z-index:2}.settings-page .topbar.is-popover-open .topbar-main-row,.settings-page .topbar:has(.popover:not([hidden])) .topbar-main-row{z-index:1000000}.settings-page h1{font-family:Fraunces,Georgia,Times New Roman,serif;font-size:34px;font-weight:700;letter-spacing:-.6px;line-height:1.2;margin:0;color:#3b2820}.settings-page .profile{display:flex;align-items:center;gap:26px;padding-right:3px}.settings-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:24px;font-size:.92rem;font-weight:500}.settings-alert.is-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.settings-alert.is-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.btn-settings-primary:disabled{cursor:not-allowed;opacity:.48;filter:grayscale(.15);box-shadow:none;pointer-events:none}.profile-save-hint{margin:10px 0 0;color:#8b5e4f;font-size:.82rem;text-align:right}.settings-tab-btn:disabled{cursor:not-allowed;opacity:.45;pointer-events:none}.settings-tabs{position:relative;display:flex;width:100%;border-top:1px solid #ebd9cb;margin:0;padding:0;background:hsla(38,67%,98%,.7);overflow-x:auto;scrollbar-width:none;border-radius:0;z-index:2}.settings-tabs::-webkit-scrollbar{display:none}.settings-tabs-indicator{position:absolute;bottom:0;top:auto;left:0;height:3px;background:#7a4b3a;border-radius:3px 3px 0 0;box-shadow:none;z-index:3;pointer-events:none;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}.settings-tab-btn{flex:1 1 0;min-width:0;position:relative;z-index:2;display:inline-flex;align-items:center;justify-content:center;gap:8px;height:auto;min-height:48px;padding:14px 16px;background:transparent;border:none;border-bottom:3px solid transparent;border-radius:0;color:#7a4b3a;font-family:DM Sans,sans-serif;font-size:.94rem;font-weight:600;cursor:pointer;transition:color .2s ease,background-color .2s ease;white-space:nowrap}.settings-tab-btn:hover:not(.is-active){color:#5f3425;background:rgba(122,75,58,.04)}.settings-tab-btn.is-active{color:#5f3425!important;font-weight:700;border-bottom-color:transparent;border-top-color:transparent;background:rgba(122,75,58,.02)}.settings-card{width:100%;box-sizing:border-box;background:#fff;border-radius:18px;border:1px solid #ebd9cb;box-shadow:0 4px 16px rgba(84,60,48,.06);padding:32px 36px;margin-bottom:28px;transition:box-shadow .2s ease}.settings-tab-content{display:none}.settings-tab-content.is-active{display:block}.profile-view-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding-bottom:22px;margin-bottom:24px;border-bottom:1px solid #f0e6e0}.profile-view-identity{display:flex;align-items:center;gap:18px}.profile-view-avatar{width:72px;height:72px;border-radius:50%;background:#7a4b3a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;overflow:hidden;flex-shrink:0;box-shadow:0 3px 10px rgba(122,75,58,.2);border:2px solid #fff}.profile-view-avatar img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.profile-view-name{font-family:Fraunces,Georgia,serif;font-size:1.45rem;font-weight:700;color:#3b2820;margin:0 0 4px}.profile-role-badge{display:inline-block;padding:0;border:0;border-radius:0;background:none;color:#7a4b3a;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.settings-section-title{font-size:1.05rem;font-weight:700;color:#3b2820;margin:24px 0 14px;padding-bottom:8px;border-bottom:1px solid #f0e6e0}.settings-section-title:first-child{margin-top:0}.settings-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px 24px;margin-bottom:22px}.settings-grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:24px}.info-display-group{display:flex;flex-direction:column;gap:4px}.info-display-label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#8b7467}.info-display-val{font-size:.95rem;font-weight:600;color:#3b2820;background:#fdfaf7;padding:10px 14px;border-radius:10px;border:1px solid #f0e6e0;min-height:42px;display:flex;align-items:center;word-break:break-word}.settings-field{display:flex;flex-direction:column;gap:0}.settings-label{display:block;margin-bottom:7px;color:#1f1a17;font-size:14px;font-weight:500}.settings-input,.settings-select{width:100%;height:45px;padding:0 14px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;color:#1f1a17;font-size:15px;font-family:inherit;transition:all .18s ease}.settings-input::-moz-placeholder{color:#9a8c84}.settings-input::placeholder,.settings-select:invalid{color:#9a8c84}.settings-select,.settings-select:active,.settings-select:focus,.settings-select:hover{cursor:pointer}.settings-input:focus,.settings-select:focus{outline:0;background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.avatar-edit-box{display:flex;align-items:center;gap:20px;padding:18px 20px;border-radius:14px;background:#fcf8f5;border:1px solid #ebd9cb;margin-bottom:24px}.avatar-edit-preview-wrap{position:relative;width:76px;height:76px;flex-shrink:0}.avatar-preview-circle{width:100%;height:100%;border-radius:50%;background:#7a4b3a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;overflow:hidden;box-shadow:0 2px 8px rgba(122,75,58,.15)}.avatar-preview-circle img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover}.avatar-edit-btn{position:absolute;bottom:-2px;right:-2px;width:28px;height:28px;border-radius:50%;background:#7a4b3a;color:#fff;border:2px solid #fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 5px rgba(0,0,0,.2);transition:transform .18s,background .18s}.avatar-edit-btn:hover{background:#5d3627;transform:scale(1.08)}.avatar-edit-btn svg{width:14px;height:14px;stroke:currentColor;stroke-width:2}.settings-dropzone{border:2px dashed #d9bea6;border-radius:14px;background:#fdfaf7;padding:22px 16px;text-align:center;cursor:pointer;transition:all .2s ease}.settings-dropzone.dragover,.settings-dropzone:hover{background:#f7ede5;border-color:#7a4b3a;transform:translateY(-1px)}.dropzone-icon{width:36px;height:36px;margin:0 auto 8px;stroke:#7a4b3a;display:block}.dropzone-filename{font-weight:600;color:#3b2820;font-size:.9rem;margin-bottom:2px;word-break:break-all}.dropzone-hint{font-size:.78rem;color:#8b7467;margin-bottom:12px}.dropzone-btn-row{display:flex;gap:8px;justify-content:center}.dropzone-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#fff;border:1px solid #ebd9cb;border-radius:8px;font-size:.8rem;font-weight:600;color:#7a4b3a;cursor:pointer;transition:background .15s,border-color .15s}.dropzone-btn:hover{background:#fbf5ef;border-color:#7a4b3a}.dropzone-btn svg{width:14px;height:14px}.btn-settings-primary{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 24px;border:none;border-radius:12px;background:linear-gradient(135deg,#7a4b3a,#5d3627);color:#fff;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(122,75,58,.22);transition:all .2s ease}.btn-settings-primary:hover{filter:brightness(1.08);transform:translateY(-1px)}.btn-settings-secondary{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border:1px solid #d9bea6;border-radius:12px;background:#fff;color:#7a4b3a;font-size:.94rem;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(84,60,48,.05);transition:all .2s ease}.btn-settings-secondary:hover{background:#fbf5ef;border-color:#7a4b3a;transform:translateY(-1px)}.settings-change-password-btn{justify-content:center;text-decoration:none;font-family:inherit}html[data-theme=dark] .settings-page .settings-logout-btn{background:hsla(25,28%,79%,.14);border-color:hsla(25,28%,79%,.22);color:#fff}html[data-theme=dark] .settings-page .settings-logout-btn:hover{background:hsla(25,28%,79%,.25);border-color:hsla(25,28%,79%,.4);color:#fff}.btn-settings-danger{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;padding:9px 18px!important;border-radius:10px!important;background:#fff!important;color:#dc2626!important;border:1px solid #fca5a5!important;box-shadow:0 2px 6px rgba(220,38,38,.08)!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:600!important;font-size:13px!important;cursor:pointer!important;transform:scale(1)!important;transform-origin:center!important;transition:transform .18s cubic-bezier(.2,0,0,1),background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease!important}.btn-settings-danger:hover{background:#fee2e2!important;border-color:#f87171!important;color:#b91c1c!important;transform:scale(1.06)!important;box-shadow:0 6px 18px rgba(220,38,38,.2)!important}.btn-settings-danger:active{background:#fecaca!important;transform:scale(1.1)!important;box-shadow:0 8px 24px rgba(220,38,38,.26)!important}.password-box{background:#fdfaf7;border:1px solid #ebd9cb;border-radius:14px;padding:20px;margin-top:16px;display:none}.password-box.is-open{display:block}.danger-zone-box{margin-top:16px;padding-top:16px;border-top:1px dashed #fecaca}.verification-pending-feedback{display:flex;align-items:center;gap:12px;margin-top:16px;padding:14px 18px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb}.verification-pending-feedback .verification-feedback-icon{font-size:22px}.verification-pending-feedback span:not(.verification-feedback-icon),.verification-pending-feedback strong{display:block;color:#92400e}.verification-pending-feedback strong{font-size:.95rem}.verification-pending-feedback span:not(.verification-feedback-icon){color:#78350f;font-size:.86rem}html[data-theme=dark] .settings-page .verification-pending-feedback{background:rgba(245,158,11,.12);border-color:hsla(48,97%,77%,.42)}html[data-theme=dark] .settings-page .verification-pending-feedback span,html[data-theme=dark] .settings-page .verification-pending-feedback strong{color:#fde68a!important}.switch-label{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid #f0e6e0}.switch-label:last-child{border-bottom:none}.switch-toggle{position:relative;display:inline-block;width:48px;height:26px}.switch-toggle input{opacity:0;width:0;height:0}.switch-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#d1d5db;transition:.24s;border-radius:26px}.switch-slider:before{position:absolute;content:"";height:20px;width:20px;left:3px;bottom:3px;background-color:#fff;transition:.24s;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2)}.switch-toggle input:checked+.switch-slider{background-color:#7a4b3a}.switch-toggle input:checked+.switch-slider:before{transform:translateX(22px)}.settings-modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:1000;align-items:center;justify-content:center;padding:20px}.settings-modal-overlay.is-open{display:flex}.settings-modal-card{background:#fffdf9;border-radius:24px;max-width:480px;width:100%;padding:34px 30px;box-shadow:0 28px 70px rgba(55,31,22,.28);border:1px solid #ebd9cb;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}.delete-account-modal{position:relative;margin:auto;text-align:center}.delete-modal-close{position:absolute;top:18px;right:18px;padding:0;border:0;border-radius:0;background:transparent;color:#7a4b3a;font-size:26px;line-height:1;cursor:pointer}.delete-modal-icon{width:64px;height:64px;margin:0 auto 20px;border:1px solid #fecaca;border-radius:50%;display:grid;place-items:center;background:#fee2e2;color:#dc2626}.delete-modal-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.delete-modal-title{margin:0 0 10px;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-size:22px;font-weight:700}.delete-modal-copy{max-width:380px;margin:0 auto 20px;color:#745f54;font-size:14px;line-height:1.6}.delete-modal-field{margin-bottom:22px}.delete-modal-field .settings-input{width:100%;text-align:center}.delete-modal-actions{display:flex;align-items:center;justify-content:center;gap:12px;width:100%}.delete-modal-actions .btn-settings-danger{flex:1}.delete-modal-cancel{flex:1;height:44px;padding:0 26px;border:1px solid var(--line,#e8ded6);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--bg,#fffdf9);color:var(--brown,#7a4b3a);box-shadow:0 1px 3px rgba(0,0,0,.12);font:700 14px DM Sans,sans-serif;cursor:pointer;transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease}.delete-modal-cancel:focus-visible,.delete-modal-cancel:hover{background:#fbf5ef;border-color:#7a4b3a;transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.2)}.delete-modal-cancel:active{transform:scale(1.12)}html[data-theme=dark] .delete-account-modal,html[data-theme=dark] .delete-modal-title{color:#fff7f2!important}html[data-theme=dark] .delete-modal-close,html[data-theme=dark] .delete-modal-copy{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-field .settings-input::-moz-placeholder{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-field .settings-input,html[data-theme=dark] .delete-modal-field .settings-input::placeholder{color:#d9c8bc!important}html[data-theme=dark] .delete-modal-cancel{background:transparent;border-color:var(--ds-accent-light,#efc2ae);color:var(--ds-accent-light,#efc2ae);box-shadow:none;font-weight:500}html[data-theme=dark] .delete-modal-cancel:active,html[data-theme=dark] .delete-modal-cancel:focus-visible,html[data-theme=dark] .delete-modal-cancel:hover{background:hsla(18,57%,73%,.1);border-color:#efc2ae;color:#efc2ae;font-weight:700}@media (max-width:480px){.delete-modal-actions{flex-direction:column}.delete-modal-actions .btn-settings-danger,.delete-modal-actions .delete-modal-cancel{flex:none;width:100%}}.input-prefix-wrapper,.input-with-icon-wrapper{display:flex;align-items:stretch;width:100%;height:45px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;overflow:hidden;transition:border-color .2s,box-shadow .2s}.input-prefix-wrapper:focus-within,.input-with-icon-wrapper:focus-within{background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.field-icon{display:flex;align-items:center;justify-content:center;padding:0 12px 0 14px;color:#7a4b3a;background:#fbf6f2;border-right:1px solid #ebd9cb;-webkit-user-select:none;-moz-user-select:none;user-select:none;flex-shrink:0}.field-icon svg{display:block}.input-prefix-addon{display:flex;align-items:center;padding:0 12px;background:transparent;color:#5b4a42;font-size:14px;font-weight:500;border-right:1px solid #d9c9bd;-webkit-user-select:none;-moz-user-select:none;user-select:none;white-space:nowrap}.input-prefix-wrapper .settings-input,.input-with-icon-wrapper .settings-input{background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;flex:1;min-width:0;height:43px}.select-with-icon-wrapper{position:relative;display:flex;align-items:center;width:100%}.select-with-icon-wrapper .field-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex;align-items:center;justify-content:center;color:#7a4b3a;background:transparent;border-right:none;padding:0;pointer-events:none;z-index:2}.select-with-icon-wrapper .settings-select{width:100%;height:44px;padding:0 44px 0 14px;border:1px solid #d9bea6;border-radius:12px;background:#fff;color:#3b2820;font-size:.94rem;font-family:inherit;appearance:none;-webkit-appearance:none;-moz-appearance:none;cursor:pointer!important;transition:border-color .18s,box-shadow .18s,background .18s}.select-with-icon-wrapper .settings-select.has-icon{padding-left:42px}.select-with-icon-wrapper .settings-select:hover{border-color:#bfa38d;background:#fdfaf7;cursor:pointer!important}.select-with-icon-wrapper .settings-select:focus{outline:none;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15);cursor:pointer!important}.select-with-icon-wrapper .select-dropdown-arrow{position:absolute;right:22px;top:50%;transform:translateY(-50%) rotate(180deg);display:flex;align-items:center;justify-content:center;color:#7a4b3a;pointer-events:none;z-index:2;transition:transform .24s cubic-bezier(.16,1,.3,1),color .18s ease}.select-with-icon-wrapper.is-dropped .select-dropdown-arrow{transform:translateY(-50%) rotate(0deg);color:#5d3627}.dropzone-preview-wrap{position:relative;width:100%;max-height:220px;border-radius:12px;overflow:hidden;margin-bottom:12px;background:#f5ede6;border:1px solid #e2cec0;display:flex;align-items:center;justify-content:center}.dropzone-preview-img{width:100%;max-height:220px;-o-object-fit:contain;object-fit:contain;display:block}.dropzone-active-state{display:flex;flex-direction:column;align-items:center;width:100%}.dropzone-badge{display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:20px;padding:4px 12px;font-size:.78rem;font-weight:600;margin-bottom:8px}.id-document-card{background:#fdfaf7;border:1px solid #ebd9cb;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;transition:transform .18s,box-shadow .18s}.id-document-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(84,60,48,.08)}.id-document-thumb{width:100%;height:180px;border-radius:10px;overflow:hidden;background:#f2e7de;display:flex;align-items:center;justify-content:center;cursor:pointer;border:1px solid #e5d3c5;position:relative}.id-document-thumb img{width:100%;height:100%;-o-object-fit:cover;object-fit:cover;transition:transform .25s ease}.id-document-thumb:hover img{transform:scale(1.03)}.id-document-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}.id-document-title{font-size:.92rem;font-weight:700;color:#3b2820}.id-document-view-btn{font-size:.8rem;font-weight:600;color:#7a4b3a;text-decoration:none!important;background:none;border:none;cursor:pointer;padding:0;transition:color .18s ease}.id-document-view-btn:hover{color:#4a271c;text-decoration:none!important}.id-document-view-btn:active{color:#2b140d}.profile-toast{position:fixed;bottom:24px;right:24px;z-index:100000;width:min(calc(100vw - 32px),400px);min-height:110px;box-sizing:border-box;background:#ecdccb;border-radius:14px;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);border:none;overflow:hidden;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;animation:toast-smooth-in .35s cubic-bezier(.16,1,.3,1) .05s both;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.profile-toast.is-dismissing{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none}.profile-toast-inner{display:flex;align-items:center;gap:14px;padding:16px 20px;height:100%;box-sizing:border-box}.profile-toast-icon{width:44px;height:44px;border-radius:12px;background:rgba(122,75,58,.15);color:#7a4b3a;border:none;display:grid;place-items:center;flex-shrink:0;font-size:20px;font-weight:700}.profile-toast-body{flex:1;min-width:0}.profile-toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:2px}.profile-toast-header strong{font-size:15px;font-weight:700;color:#3b2820}.profile-toast-close{background:none;border:none;font-size:22px;line-height:1;color:#7a4b3a;cursor:pointer;padding:0 4px;transition:color .15s,transform .15s}.profile-toast-close:hover{color:#3b2820;transform:scale(1.15)}.profile-toast-body p{margin:0;font-size:13.5px;line-height:1.4;color:#5a4236}@keyframes toast-smooth-in{0%{opacity:0;transform:translateY(28px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}.cropper-modal-overlay{display:none;position:fixed;inset:0;background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);z-index:100000;align-items:center;justify-content:center;padding:20px}.cropper-modal-overlay.is-open{display:flex}.cropper-modal-card{background:#fffdf9;border-radius:24px;max-width:480px;width:100%;padding:30px;box-shadow:0 28px 70px rgba(55,31,22,.28);border:1px solid #ebd9cb;display:flex;flex-direction:column;gap:18px;color:#3b2820;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}.cropper-modal-header .profile-toast-close{width:auto;height:auto;border-radius:0;border:none;background:none;color:#7a4b3a;font-size:26px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.cropper-modal-header .profile-toast-close:hover{background:none;color:#3b2820;border:none;transform:scale(1.1)}.cropper-modal-header .profile-toast-close:active{background:none;color:#1a0f0a;transform:scale(.92)}.cropper-modal-header{display:flex;align-items:center;justify-content:space-between}.cropper-modal-title{margin:0;font-size:1.35rem;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-weight:700;color:#3b2820}.cropper-canvas-wrap{position:relative;width:100%;height:300px;background:#1f1b18;border-radius:14px;overflow:hidden;-webkit-user-select:none;-moz-user-select:none;user-select:none;touch-action:none;display:flex;align-items:center;justify-content:center;cursor:grab}.cropper-canvas-wrap:active{cursor:grabbing}.cropper-mask{position:absolute;inset:0;pointer-events:none;box-shadow:0 0 0 9999px rgba(0,0,0,.55);border-radius:50%;width:200px;height:200px;margin:auto;border:2px solid hsla(0,0%,100%,.85)}.cropper-controls{display:flex;align-items:center;gap:14px}.cropper-zoom-slider{flex:1;accent-color:#7a4b3a}.cropper-btn-round{width:34px;height:34px;border-radius:50%;border:1px solid #ebd9cb;background:#fbf6f2;color:#7a4b3a;font-size:18px;font-weight:700;display:grid;place-items:center;cursor:pointer;transition:all .15s}.cropper-btn-round:hover{background:#f3e5d9}.id-lightbox-overlay{position:fixed;inset:0;background:rgba(15,10,8,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:100001;display:flex;align-items:center;justify-content:center;padding:24px;opacity:0;pointer-events:none;transition:opacity .26s cubic-bezier(.16,1,.3,1),backdrop-filter .26s ease,-webkit-backdrop-filter .26s ease}.id-lightbox-overlay.is-open:not(.is-closing){opacity:1;pointer-events:auto}.id-lightbox-overlay.is-closing{opacity:0;pointer-events:none;transition:opacity .22s ease,backdrop-filter .22s ease,-webkit-backdrop-filter .22s ease}.id-lightbox-content{position:relative;max-width:90vw;max-height:88vh;display:flex;flex-direction:column;align-items:center;transform:scale(.92) translateY(12px);opacity:0;transition:transform .28s cubic-bezier(.16,1,.3,1),opacity .24s ease}.id-lightbox-overlay.is-open:not(.is-closing) .id-lightbox-content{transform:scale(1) translateY(0);opacity:1}.id-lightbox-overlay.is-closing .id-lightbox-content{transform:scale(.94) translateY(10px);opacity:0;transition:transform .22s ease,opacity .2s ease}.id-lightbox-img{max-width:100%;max-height:80vh;-o-object-fit:contain;object-fit:contain;border-radius:14px;box-shadow:0 24px 50px rgba(0,0,0,.45)}.id-lightbox-close{position:absolute;top:-44px;right:0;background:none;border:none;border-radius:0;width:auto;height:auto;display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;cursor:pointer;line-height:1;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.id-lightbox-close:hover{background:none;color:#e5d8cf;transform:scale(1.1)}.id-lightbox-close:active{background:none;color:#c9b7ad;transform:scale(.92)}#tab-notifications .switch-label>div:first-child{flex:1;min-width:0;padding-right:24px}#tab-notifications .switch-toggle{flex:0 0 48px;margin-left:auto}#tab-notifications .settings-card{min-height:460px;display:flex;flex-direction:column}#notification-preference-status{margin:auto 0 0;padding-top:16px;align-self:flex-end;text-align:right;font-size:.85rem;color:#78665c}#notification-preference-status:empty{display:none}#tab-notifications .switch-label:last-of-type{border-bottom:none}.notification-preference-footer{display:block;width:100%;margin-top:auto;padding-top:16px;text-align:right}.notification-preference-footer #notification-preference-status{display:block;width:100%;margin:0;padding:0;text-align:right;overflow-wrap:anywhere}.notification-preference-footer #notification-preference-status:empty{display:none}dialog.ps-modal{width:min(92vw,480px);padding:0;border:1px solid #ebd9cb;border-radius:24px;background:#fffdf9;color:#3b2820;box-shadow:0 28px 70px rgba(55,31,22,.28);text-align:center;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}dialog.ps-modal::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}dialog.ps-modal[open]{animation:ps-in .28s cubic-bezier(.16,1,.3,1) both}dialog.ps-modal.is-closing{animation:ps-out .2s ease both}@keyframes ps-in{0%{opacity:0;transform:translateY(14px) scale(.95)}to{opacity:1;transform:none}}@keyframes ps-out{to{opacity:0;transform:translateY(10px) scale(.95)}}.ps-card{padding:34px 30px 28px}.ps-icon{width:64px;height:64px;margin:0 auto 18px;display:grid;place-items:center;border-radius:50%;background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}.ps-icon svg{width:30px;height:30px;stroke-width:2.2}.ps-card h2{margin:0 0 8px;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-.2px;color:#3b2820}.ps-card p{margin:0 auto 24px;max-width:340px;font-size:.94rem;line-height:1.55;color:#6b574e}.ps-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:46px;padding:0 26px;border:0;border-radius:12px;background:linear-gradient(135deg,#7a4b3a,#5d3627);color:#fff;font:700 .94rem DM Sans,sans-serif;text-decoration:none;cursor:pointer;box-shadow:0 4px 14px rgba(122,75,58,.22);transition:all .2s ease}.ps-btn:hover{filter:brightness(1.08);transform:translateY(-1px)}@media (prefers-reduced-motion:reduce){dialog.ps-modal.is-closing,dialog.ps-modal[open]{animation:none}}.settings-field .um-select{position:relative;width:100%}.settings-field .um-select-btn{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;height:45px;min-width:0;padding:0 14px;border:1px solid #d9c9bd;border-radius:10px;background:#f5f1ec;color:#1f1a17;font-family:DM Sans,sans-serif;font-size:15px;font-weight:400;cursor:pointer;transition:border-color .18s ease,box-shadow .18s ease;box-shadow:none}.settings-field .um-select-btn:hover{border-color:#d9c9bd}.settings-field .um-select-btn[aria-expanded=true]{background:#fff;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.settings-field .um-select-btn.is-placeholder .um-select-value{color:#9a8c84}.settings-field .um-select-caret{width:17px;height:17px;flex:none;stroke:currentColor;stroke-width:2.2;color:#5b4a42;transition:transform .2s ease}.settings-field .um-select-btn[aria-expanded=true] .um-select-caret{transform:rotate(180deg)}.settings-field .um-select-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:1200;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #eadfd9;border-radius:14px;box-shadow:0 18px 44px rgba(60,30,20,.18);transform-origin:top center;animation:um-menu-in .22s cubic-bezier(.16,1,.3,1) both}.settings-field .um-select-menu[hidden]{display:none}.settings-field .um-select-menu.is-closing{animation:um-menu-out .18s cubic-bezier(.2,.8,.2,1) both;pointer-events:none}.settings-field .um-select-menu li{padding:10px 14px;border-radius:10px;color:#5f3425;font-family:DM Sans,sans-serif;font-size:.92rem;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s}.settings-field .um-select-menu li:focus,.settings-field .um-select-menu li:hover{background:#f4ece7;outline:none}.settings-field .um-select-menu li[aria-selected=true]{background:#7a4b3a;color:#fff;font-weight:600}@keyframes um-menu-in{0%{opacity:0;transform:translateY(-8px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes um-menu-out{0%{opacity:1;transform:translateY(0) scale(1)}to{opacity:0;transform:translateY(-6px) scale(.96)}}html[data-theme=dark] .settings-page .settings-tabs{background:#5c4538!important;border-top-color:#6e5344!important;border-bottom-color:#6e5344!important}html[data-theme=dark] .settings-page .settings-tab-btn,html[data-theme=dark] .settings-page .settings-tab-btn.is-active{color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-tab-btn:hover:not(.is-active){color:#fff7f2!important;background:rgba(255,247,242,.06)!important}html[data-theme=dark] .settings-page .settings-tab-btn.is-active{background:rgba(122,75,58,.02)!important}html[data-theme=dark] .settings-page .settings-tabs-indicator{background:#fff7f2!important}html[data-theme=dark] .settings-page #profileViewCard{background:var(--dark-form-surface,#382a22)!important;border:1px solid var(--ds-line,#3d2d24)!important;border-radius:24px;color:var(--ds-title,#fff7f2)!important;box-shadow:0 16px 48px rgba(0,0,0,.5)!important}html[data-theme=dark] .settings-page #tab-security .settings-card>div:first-of-type strong{color:#fff7f2!important}html[data-theme=dark] .settings-page .settings-change-password-btn{height:45px;padding:0 20px;border:1px solid var(--ds-accent,#e2ab94)!important;border-radius:10px;background:var(--ds-accent,#e2ab94)!important;color:var(--ds-on-accent,#1e1612)!important;font-size:14px;font-weight:700;box-shadow:none;transition:background-color .2s,border-color .2s,color .2s,transform .2s}html[data-theme=dark] .settings-page .settings-change-password-btn:hover{background:var(--ds-accent-hover,#efc2ae)!important;border-color:var(--ds-accent-hover,#efc2ae)!important;color:var(--ds-on-accent,#1e1612)!important;transform:scale(1.02)}html[data-theme=dark] .settings-page .settings-change-password-btn:active{background:var(--ds-accent-press,#cf967f)!important;border-color:var(--ds-accent-press,#cf967f)!important;color:var(--ds-on-accent,#1e1612)!important;transform:scale(1.02)}html[data-theme=dark] .settings-page .settings-change-password-btn:focus-visible{outline:2px solid #fff7f2;outline-offset:3px}.btn-settings-secondary.btn-edit-profile{height:44px;padding:0 26px;border:1px solid #7b4a3a;border-radius:8px;background:#7b4a3a;color:#fff;font:700 14px DM Sans,sans-serif;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s}.btn-settings-secondary.btn-edit-profile:hover{background:#5c3527;border-color:#5c3527;color:#fff;filter:brightness(.95);transform:none;scale:1.1}.btn-settings-secondary.btn-edit-profile:active{background:#452618;border-color:#452618;color:#fff}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile{background:#e2ab94;border-color:#e2ab94;color:#1e1612}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile:hover{background:#efc2ae;border-color:#efc2ae;color:#1e1612}html[data-theme=dark] .settings-page .btn-settings-secondary.btn-edit-profile:active{background:#cf967f;border-color:#cf967f;color:#1e1612}html[data-theme=dark] body.tenant-page:not(#_a) #profileEditForm,html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-header{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-name{color:#fff7f2!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-role-badge,html[data-theme=dark] body.tenant-page:not(#_a) .tenant-role-label{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) .profile-view-avatar{background:#231914!important;color:#d9c8bc!important;border:2px solid #563e33!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) .settings-section-title{color:#fff7f2!important;border-bottom:1px solid #563e33!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-label{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val a,html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val span{color:#e2ab94!important}html[data-theme=dark] body.tenant-page:not(#_a) #profileViewCard .info-display-val:not(:has(a)) span{color:#8a6c5d!important}html[data-theme=dark] body.tenant-page:not(#_a) .btn-edit-profile{border-color:#c48974!important}body.tenant-page:not(#_a) .danger-zone-box{display:grid;grid-template-columns:1fr auto;-moz-column-gap:24px;column-gap:24px;align-items:center}body.tenant-page:not(#_a) .danger-zone-box h4{grid-column:1;grid-row:1}body.tenant-page:not(#_a) .danger-zone-box p{grid-column:1;grid-row:2;margin:3px 0 0!important}body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger{grid-column:2;grid-row:1/span 2;align-self:center;justify-self:end;white-space:nowrap}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box{border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box p{color:#c5a898!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger:hover,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger:active,html[data-theme=dark] body.tenant-page:not(#_a) .delete-account-modal .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}@media (max-width:600px){body.tenant-page:not(#_a) .danger-zone-box{grid-template-columns:1fr}body.tenant-page:not(#_a) .danger-zone-box .btn-settings-danger{grid-column:1;grid-row:3;justify-self:start;margin-top:14px}}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{display:grid;grid-template-columns:1fr auto;-moz-column-gap:24px;column-gap:24px;align-items:center}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box h4{grid-column:1;grid-row:1}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box p{grid-column:1;grid-row:2;margin:3px 0 0!important}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{grid-column:2;grid-row:1/span 2;align-self:center;justify-self:end;white-space:nowrap}@media (max-width:600px){body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{grid-template-columns:1fr}body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{grid-column:1;grid-row:3;justify-self:start;margin-top:14px}}html[data-theme=dark] body.landlord-settings-page #settings-main{scrollbar-color:#d8c3b4 transparent!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-thumb{background:#d8c3b4!important;border:3px solid transparent!important;background-clip:padding-box!important}html[data-theme=dark] body.landlord-settings-page #settings-main::-webkit-scrollbar-thumb:hover{background:#f0ded2!important;background-clip:padding-box!important}html[data-theme=dark] body.landlord-settings-page #profileEditForm,html[data-theme=dark] body.landlord-settings-page #profileViewCard,html[data-theme=dark] body.landlord-settings-page #tab-security .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .profile-view-header{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page #tab-security strong,html[data-theme=dark] body.landlord-settings-page .avatar-edit-box h3,html[data-theme=dark] body.landlord-settings-page .profile-view-name,html[data-theme=dark] body.landlord-settings-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page #tab-security p,html[data-theme=dark] body.landlord-settings-page .profile-role-badge,html[data-theme=dark] body.landlord-settings-page .profile-save-hint{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page .profile-view-avatar{background:#231914!important;color:#d9c8bc!important;border:2px solid #563e33!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .settings-section-title{border-bottom:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page #profileViewCard .info-display-label{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page #profileViewCard .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile{height:44px!important;padding:0 26px!important;border:1px solid #e2ab94!important;border-radius:8px!important;background:#e2ab94!important;color:#1e1612!important;font:700 14px DM Sans,sans-serif!important;box-shadow:0 1px 3px rgba(0,0,0,.12)!important}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile:hover{background:#efc2ae!important;border-color:#efc2ae!important;color:#1e1612!important;transform:none!important;scale:1.1}html[data-theme=dark] body.landlord-settings-page .btn-landlord-edit-profile:active{background:#cf967f!important;border-color:#cf967f!important;color:#1e1612!important}html[data-theme=dark] body.landlord-settings-page .settings-field label,html[data-theme=dark] body.landlord-settings-page .settings-label{color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper,html[data-theme=dark] body.landlord-settings-page .settings-input:not([type=checkbox]):not([type=radio]),html[data-theme=dark] body.landlord-settings-page .settings-select,html[data-theme=dark] body.landlord-settings-page .um-select-btn{background:#231a14!important;border:1px solid #5c4639!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper .settings-input,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper .settings-input{background:transparent!important;border:0!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .field-icon,html[data-theme=dark] body.landlord-settings-page .input-prefix-addon{background:transparent!important;color:#d9c8bc!important;border-right-color:#5c4639!important}html[data-theme=dark] body.landlord-settings-page .settings-input::-moz-placeholder{color:#d9c8bc!important;opacity:.9!important}html[data-theme=dark] body.landlord-settings-page .settings-input::placeholder{color:#d9c8bc!important;opacity:.9!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper:focus-within,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper:focus-within,html[data-theme=dark] body.landlord-settings-page .settings-input:focus,html[data-theme=dark] body.landlord-settings-page .settings-select:focus,html[data-theme=dark] body.landlord-settings-page .um-select-btn[aria-expanded=true]{background:#281d17!important;border-color:#e2ab94!important;color:#d9c8bc!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] body.landlord-settings-page .input-prefix-wrapper .settings-input:focus,html[data-theme=dark] body.landlord-settings-page .input-with-icon-wrapper .settings-input:focus{box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page .settings-input:-webkit-autofill{-webkit-text-fill-color:#d9c8bc!important;-webkit-box-shadow:inset 0 0 0 1000px #231a14!important;caret-color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-box{background:#231914!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-btn{background:#e2ab94!important;color:#1e1612!important;border:2px solid #231914!important}html[data-theme=dark] body.landlord-settings-page .avatar-edit-btn:hover{background:#efc2ae!important}html[data-theme=dark] body.landlord-settings-page .settings-dropzone{background:#231914!important;border:2px dashed #563e33!important}html[data-theme=dark] body.landlord-settings-page .settings-dropzone.dragover,html[data-theme=dark] body.landlord-settings-page .settings-dropzone:hover{background:#2f211a!important;border-color:#c48974!important}html[data-theme=dark] body.landlord-settings-page .dropzone-hint,html[data-theme=dark] body.landlord-settings-page .dropzone-icon{color:#c5a898!important;stroke:#c5a898!important}html[data-theme=dark] body.landlord-settings-page .dropzone-filename{color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .dropzone-preview-wrap,html[data-theme=dark] body.landlord-settings-page .id-document-thumb{background:#1a110d!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn:hover{background:#2f211a!important;border-color:#c48974!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page .dropzone-btn.dropzone-btn-danger{background:transparent!important;border-color:hsla(0,91%,71%,.45)!important;color:#fca5a5!important}html[data-theme=dark] body.landlord-settings-page .id-document-card{background:#231914!important;border:1px solid #563e33!important}html[data-theme=dark] body.landlord-settings-page .id-document-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-settings-page .id-document-view-btn{color:#e2ab94!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-pending,html[data-theme=dark] body.landlord-settings-page .verification-callout.is-pending{background:rgba(245,158,11,.12)!important;border-color:rgba(251,191,36,.35)!important;color:#fde68a!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-rejected,html[data-theme=dark] body.landlord-settings-page .verification-callout.is-rejected{background:rgba(220,38,38,.12)!important;border-color:hsla(0,91%,71%,.4)!important;color:#fecaca!important}html[data-theme=dark] body.landlord-settings-page .verification-badge.is-verified{background:rgba(34,197,94,.12)!important;border-color:rgba(134,239,172,.35)!important;color:#bbf7d0!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box{border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.landlord-settings-page .danger-zone-box p{color:#c5a898!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger:hover,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.landlord-settings-page #deleteModal .btn-settings-danger:active,html[data-theme=dark] body.landlord-settings-page .danger-zone-box .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}html[data-theme=dark] body.settings-page.admin-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page.admin-page .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body.settings-page.admin-page #headerTitle,html[data-theme=dark] body.settings-page.admin-page #tab-notifications strong,html[data-theme=dark] body.settings-page.admin-page #tab-security strong,html[data-theme=dark] body.settings-page.admin-page .profile-view-name,html[data-theme=dark] body.settings-page.admin-page .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.settings-page.admin-page #profileEditForm,html[data-theme=dark] body.settings-page.admin-page #profileViewCard,html[data-theme=dark] body.settings-page.admin-page .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important;color:#d9c8bc!important}html[data-theme=dark] body.settings-page.admin-page .profile-view-header,html[data-theme=dark] body.settings-page.admin-page .settings-section-title{border-bottom-color:#563e33!important}html[data-theme=dark] body.settings-page.admin-page #tab-notifications p,html[data-theme=dark] body.settings-page.admin-page #tab-notifications span,html[data-theme=dark] body.settings-page.admin-page #tab-security p,html[data-theme=dark] body.settings-page.admin-page .info-display-label,html[data-theme=dark] body.settings-page.admin-page .settings-field label,html[data-theme=dark] body.settings-page.admin-page .settings-label{color:#c5a898!important}html[data-theme=dark] body.settings-page.admin-page .info-display-val,html[data-theme=dark] body.settings-page.admin-page .password-box,html[data-theme=dark] body.settings-page.admin-page .settings-input{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.settings-page.admin-page .info-display-val a{color:#e2ab94!important}html[data-theme=dark] body.settings-page.admin-page .settings-input::-moz-placeholder{color:#8a6c5d!important}html[data-theme=dark] body.settings-page.admin-page .settings-input::placeholder{color:#8a6c5d!important}html[data-theme=dark] body.settings-page.admin-page .settings-input:focus{border-color:#c48974!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box{background:#2b1f19!important;border-color:hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.settings-page.admin-page .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card :is(h3,strong){color:#fff7f2!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card p{color:#c5a898!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .settings-card>div[style*=border-top]{border-top-color:#563e33!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box{background:#2b1f19!important;border-top:1px dashed hsla(0,91%,71%,.35)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box h4{color:#f87171!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger{background:#231914!important;border:1px solid hsla(0,91%,71%,.45)!important;color:#fca5a5!important;box-shadow:none!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger:hover{background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important}html[data-theme=dark] body.settings-page:is(.landlord-settings-page,.admin-page) #tab-security .danger-zone-box .btn-settings-danger:active{background:rgba(220,38,38,.28)!important;color:#fecaca!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card{background:#2b1f19!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:0 16px 40px rgba(0,0,0,.45)!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card :is(h3,strong){color:#fff7f2!important}html[data-theme=dark] body.settings-page #tab-notifications .settings-card :is(p,span:not(.switch-slider)){color:#c5a898!important}html[data-theme=dark] body.settings-page #tab-notifications .switch-slider{background:#4b382d!important}html[data-theme=dark] body.settings-page #tab-notifications .switch-toggle input:checked+.switch-slider{background:#c48974!important}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body{background:var(--bg);color:var(--text)}html[data-theme=dark] body:not(#_a) .shell:not(#_b){background:#5c4538!important;border:1px solid #6e5344!important}html[data-theme=dark] body:not(#_a) .shell:not(#_b)>.topbar,html[data-theme=dark] body:not(#_a) .shell:not(#_b)>main{background:transparent!important;box-shadow:none!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .bar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar{background:rgba(30,22,18,.96);border-bottom-color:var(--line);box-shadow:0 4px 18px rgba(0,0,0,.35)}[data-theme=dark] .admin-topbar h1,[data-theme=dark] .bar .logo,[data-theme=dark] .topbar h1,[data-theme=dark] .topbar h2,[data-theme=dark] h1,[data-theme=dark] h2,[data-theme=dark] h3,[data-theme=dark] h4{color:var(--head)}[data-theme=dark] .btn-theme-toggle{background:#2b1f19;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-theme-toggle:hover{background:#382921;border-color:var(--brown);color:var(--brown)}[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#4a372c,#3a2820);border-right-color:#553e32}[data-theme=dark] .admin-sidebar{background:linear-gradient(135deg,#32221b,#241813);border-right-color:#3d2d24}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08);color:#fff7f2}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14);color:#d9c8bc;font-weight:700}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .current-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .room-card,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .settings-modal-card,[data-theme=dark] .stat-card,[data-theme=dark] dialog{background:#2b1f19;border-color:var(--line);color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,.4)}html[data-theme=dark] .logout-modal-title{color:#fff7f2!important}html[data-theme=dark] .logout-modal-desc{color:#d9c8bc!important}[data-theme=dark] .dorm-card,[data-theme=dark] .dorm-card-body{background:#382a22;border-color:#4b382d;color:#d9c8bc}[data-theme=dark] .current-booking-body,[data-theme=dark] .dorm-modal-body,[data-theme=dark] .dorm-modal-header,[data-theme=dark] .landlord-profile-card-rect{background:#2b1f19;color:var(--text)}[data-theme=dark] .dorm-card-title{color:#fff7f2}[data-theme=dark] .profile-name,[data-theme=dark] .profile-switch-header,[data-theme=dark] .settings-section-title,[data-theme=dark] .stat-card-value{color:var(--head)}[data-theme=dark] .dorm-card-amenity{background:#231a14;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .admin-user-badge,[data-theme=dark] .topbar-categories-bar{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea{background:#231a14!important;color:var(--ink)!important;border-color:var(--line)!important}[data-theme=dark] .um-select-menu li{color:var(--text)}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f;color:var(--ink)}[data-theme=dark] .settings-tabs{background:#241a15;border-color:var(--line)}[data-theme=dark] .settings-tab-btn{color:#bba89d}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .settings-tab-btn[aria-selected=true]{color:var(--ink)}[data-theme=dark] .settings-tabs-indicator{background:var(--brown)}[data-theme=dark] .switch-slider{background-color:#4b382d}[data-theme=dark] .switch-toggle input:checked+.switch-slider{background-color:var(--brown)}[data-theme=dark] .profile-account-item{border-color:var(--line)}[data-theme=dark] .profile-account-item:hover{background:#34261f}[data-theme=dark] .profile-account-item strong{color:var(--head)}[data-theme=dark] .skeleton-card,[data-theme=dark] .skeleton-shimmer{background:linear-gradient(90deg,#2b1f19 25%,#382921 50%,#2b1f19 75%);background-size:200% 100%}[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .settings-modal-overlay,[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}html[data-theme=dark] dialog:not(.onboarding-toast-modal):not(.guide-modal):not(.landlord-profile-modal):not(.us-confirm):not(.rm-modal){position:fixed;inset:0;margin:auto}html[data-theme=dark] .landlord-properties-page dialog.dorm-modal[open],html[data-theme=dark] dialog.dorm-modal,html[data-theme=dark] dialog.room-details-modal{position:fixed!important;inset:0!important;margin:auto!important;top:0!important;left:0!important;right:0!important;bottom:0!important;transform:none!important}html[data-theme=dark] #modalOverlay,html[data-theme=dark] .cropper-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal,html[data-theme=dark] .preview-modal,html[data-theme=dark] .settings-modal-overlay{align-items:center!important;justify-content:center!important}html[data-theme=dark] #modalOverlay>.modal-card,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .preview-modal-box,html[data-theme=dark] .settings-modal-card{margin:auto}[data-theme=dark] .notification-toast{background:#32241d;border:1px solid var(--line);color:var(--ink);box-shadow:0 16px 36px rgba(0,0,0,.55)}[data-theme=dark] .notification-toast strong{color:var(--head)}[data-theme=dark] .notification-toast span{color:var(--text)}[data-theme=dark] .wrap section{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .crumb{color:#ab978c}[data-theme=dark] .foot{background:#18110d;color:#e2d3c8;border-top:1px solid var(--line)}[data-theme=dark] .feat.feature-hero{background:radial-gradient(circle at 50% 20%,#2b1f19 0,#1e1612 100%)}[data-theme=dark] .sec{background:#1e1612}[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--line)}[data-theme=dark] .tab{color:#bba89d}[data-theme=dark] .tab.active{color:#fff7f2}[data-theme=dark] .tab-indicator{background:var(--brown)}[data-theme=dark] .panel{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .hero-wrap.photo-section{background:#1e1612}[data-theme=dark] .hero-copy{color:var(--text)}[data-theme=dark] .hero-copy h2{color:var(--head)}[data-theme=dark] .sq{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .sq-badge{background:rgba(30,22,18,.85);color:var(--ink);border-color:var(--line)}[data-theme=dark] .sq-num{color:var(--head)}[data-theme=dark] .apply-dialog{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right-color:var(--line)}[data-theme=dark] .apply-ws-right{background:#2b1f19}[data-theme=dark] .la-label{color:#d1b8ab}[data-theme=dark] .la-input{background:#231a14!important;border-color:var(--line)!important;color:var(--ink)!important}[data-theme=dark] body{background-color:#1e1612}[data-theme=dark] .stage{background:#1e1612}[data-theme=dark] .card{background:#2b1f19;border-color:#563e33;box-shadow:0 16px 40px rgba(0,0,0,.45)}[data-theme=dark] .form-side{background:#2b1f19;color:#fbf5f0}[data-theme=dark] .form-side h2,[data-theme=dark] .form-side h3{color:#fbf5f0}[data-theme=dark] .field label,[data-theme=dark] .form-side .hint,[data-theme=dark] .tagline{color:#c5a898}[data-theme=dark] .control input,[data-theme=dark] .control select{background:#231914;border-color:#563e33;color:#d9c8bc}[data-theme=dark] .control input:focus,[data-theme=dark] .control select:focus{background:#281d17;border-color:var(--brown,#b85d34);color:#d9c8bc}[data-theme=dark] .control input::-moz-placeholder{color:#8a6c5d}[data-theme=dark] .control input::placeholder{color:#8a6c5d}[data-theme=dark] .control .lead-icon{stroke:#c5a898}[data-theme=dark] .btn-google{background:#231914;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-google:hover{background:#2f211a;border-color:#7a4b3a}[data-theme=dark] .actions--full .or:after,[data-theme=dark] .actions--full .or:before{background:#563e33}[data-theme=dark] .actions--full .or{color:#c5a898}[data-theme=dark] .back-link,[data-theme=dark] .btn-resend-link,[data-theme=dark] .forgot,[data-theme=dark] .form-footer a,[data-theme=dark] .resend-alt-link{color:#e2a07f}[data-theme=dark] .readonly-email-box{background-color:#231914!important;color:#c5a898!important;border-color:#563e33!important}[data-theme=dark] .landlord-dashboard-page .topbar h1,[data-theme=dark] .landlord-notifications-page .topbar h1,[data-theme=dark] .landlord-properties-page .topbar h1,[data-theme=dark] .landlord-settings-page .topbar h1,[data-theme=dark] .landlord-tenants-page .topbar h1{color:var(--head,#fff7f2)!important}html[data-theme=dark] .stat-card{background:#241813!important;border:1px solid #453127!important;color:var(--text,#e2d3c8)!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}html[data-theme=dark] .stat-card :is(.card-label,.card-value,.card-suffix,.card-subtext,.card-subtext-link,.stat-card-title,.stat-card-label,.stat-card-value,.stat-card-desc){color:#fff7f2!important}html[data-theme=dark] .stat-card :is(.card-subtext,.card-subtext-link,.stat-card-desc){opacity:.9}html[data-theme=dark] .stat-card :is(.stat-card-icon,.stat-icon){background:hsla(25,28%,79%,.14)!important;color:#fff7f2!important}html[data-theme=dark] .stat-card svg{color:#fff7f2!important;stroke:#fff7f2!important}[data-theme=dark] .recent-bookings-card{background:#2b1f19!important;border:1px solid var(--line,#4b382d)!important;box-shadow:0 4px 20px rgba(0,0,0,.4)!important}[data-theme=dark] .recent-bookings-header{border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .recent-bookings-view-all{background:var(--brown)!important;color:#1a0f0a!important}[data-theme=dark] .recent-bookings-view-all:hover{background:var(--brown-dark)!important;color:#1a0f0a!important}[data-theme=dark] .recent-bookings-footer{border-top:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .recent-bookings-table td{border-bottom:1px solid var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .recent-bookings-table tbody tr:hover{background:#34261f!important}[data-theme=dark] .landlord-onboarding-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .onboarding-step-box{background:#231a14!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .onboarding-step-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .landlord-properties-page .um-search{background:#231a14!important;border-color:#5c4639!important}[data-theme=dark] .landlord-properties-page .um-search input{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-search input::-moz-placeholder{color:#d9c8bc!important;opacity:.9}[data-theme=dark] .landlord-properties-page .um-search input::placeholder{color:#d9c8bc!important;opacity:.9}[data-theme=dark] .landlord-properties-page .um-select-btn{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-select-btn,[data-theme=dark] .landlord-properties-page .um-select-menu{background:#231a14!important;border-color:#5c4639!important}[data-theme=dark] .landlord-properties-page .um-select-menu li{color:#d9c8bc!important}[data-theme=dark] .landlord-properties-page .um-select-menu li:hover,[data-theme=dark] .landlord-properties-page .um-select-menu li[aria-selected=true]{background:#34261f!important;color:#fff7f2!important}[data-theme=dark] .landlord-properties-page .dorm-card{background:#241813!important;border-color:#453127!important;color:#fff7f2!important;box-shadow:0 6px 20px rgba(0,0,0,.4)!important}[data-theme=dark] .landlord-properties-page .dorm-card:hover{background:#2d1f19!important;border-color:#5d4235!important;box-shadow:0 16px 36px rgba(0,0,0,.5)!important}[data-theme=dark] .landlord-properties-page .dorm-card-address,[data-theme=dark] .landlord-properties-page .dorm-card-rent-label,[data-theme=dark] .landlord-properties-page .dorm-card-rent-value,[data-theme=dark] .landlord-properties-page .dorm-card-title{color:#fff7f2!important}[data-theme=dark] .landlord-properties-page .dorm-card-amenity,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap{background:rgba(255,247,242,.1)!important;color:#fff!important;border:1px solid rgba(255,247,242,.18)!important}[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill strong,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-occ-pill svg,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenant-name,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenants-vacant,[data-theme=dark] .landlord-properties-page .dorm-card-tenants-wrap .dorm-card-tenants-vacant span{color:#fff!important}[data-theme=dark] .landlord-properties-page .dorm-card-tenant-badge{background:rgba(255,247,242,.12)!important;border:1px solid rgba(255,247,242,.22)!important}[data-theme=dark] dialog.dorm-inspect-modal{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-inspect-col-right{background:#241a15!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-modal-rooms-header{border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .dorm-modal-rooms-title{color:var(--head,#fff7f2)!important}[data-theme=dark] .dorm-modal-room-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .dorm-modal-room-photo-item{background:#1e1612!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .add-prop-page1-grid .form-col-right,[data-theme=dark] .add-prop-page2-grid{background:#241a15!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .rooms-section-header{background:#241a15!important;border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .rooms-section-header h3{color:var(--head,#fff7f2)!important}[data-theme=dark] .room-item-box:nth-child(2n){background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .room-item-box:nth-child(2n) .amenity-checkbox-item,[data-theme=dark] .room-item-box:nth-child(2n) .field-input,[data-theme=dark] .room-item-box:nth-child(2n) .photo-drop-box,[data-theme=dark] .room-item-box:nth-child(2n) select.field-input{background:#231a14!important;color:var(--ink,#fff7f2)!important}[data-theme=dark] .add-room-dashed-btn{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .add-room-dashed-btn:hover{background:#34261f!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .add-prop-modal .modal-footer{background:#1e1612!important;border-top-color:var(--line,#4b382d)!important}[data-theme=dark] .map-wrapper{border-color:var(--line,#4b382d)!important}[data-theme=dark] .landlord-tenants-page .card,[data-theme=dark] .tenants-table-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .tenants-table th{background:#231a14!important;color:#d9c8bc!important;border-bottom:1px solid var(--line,#4b382d)!important}[data-theme=dark] .tenants-table td{border-bottom:1px solid var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .tenants-table tbody tr:hover{background:#34261f!important}[data-theme=dark] .btn-filter-tab,[data-theme=dark] .filter-pill{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .btn-filter-tab.is-active,[data-theme=dark] .filter-pill.is-active{background:var(--brown,#c48974)!important;color:#fff!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .tenant-drawer{background:#2b1f19!important;border-left-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important;box-shadow:-10px 0 40px rgba(0,0,0,.6)!important}[data-theme=dark] .tenant-drawer-header{background:#231a14!important;border-bottom-color:var(--line,#4b382d)!important}[data-theme=dark] .tenant-drawer-heading{color:var(--head,#fff7f2)!important}[data-theme=dark] .tenant-drawer-close{color:#dca08b!important}[data-theme=dark] .tenant-drawer-close:hover{color:#fff7f2!important}[data-theme=dark] .tenant-detail-section{background:#241a15!important;border:1px solid var(--line,#4b382d)!important;border-radius:14px}[data-theme=dark] .notification-hero h2{color:var(--head,#fff7f2)!important}[data-theme=dark] .notification-tab-btn{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .notification-tab-btn.is-active{background:var(--brown,#c48974)!important;color:#fff!important;border-color:var(--brown,#c48974)!important}[data-theme=dark] .notification-entry{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .notification-entry.is-unread{background:#34261f!important;border-color:#5f4435!important}[data-theme=dark] .notification-entry strong{color:var(--head,#fff7f2)!important}[data-theme=dark] .notification-timestamp{color:#a89486!important}[data-theme=dark] .settings-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .settings-tabs{background:#231a14!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .settings-tab-btn{color:#c5a898!important}[data-theme=dark] .settings-tab-btn.is-active{background:#34261f!important;color:var(--head,#fff7f2)!important}[data-theme=dark] .settings-label{color:var(--head,#fff7f2)!important}[data-theme=dark] .settings-input,[data-theme=dark] .settings-select{background:#1e1612!important;border-color:var(--line,#4b382d)!important;color:var(--ink,#fff7f2)!important}[data-theme=dark] .password-box{background:#231a14!important;border-color:var(--line,#4b382d)!important}[data-theme=dark] .danger-zone-box{border-top-color:hsla(0,91%,71%,.35)!important}[data-theme=dark] .danger-zone-box p{color:#c5a898!important}[data-theme=dark] .settings-modal-card{box-shadow:0 24px 60px rgba(0,0,0,.6)!important}[data-theme=dark] .cropper-modal-card,[data-theme=dark] .settings-modal-card{background:#2b1f19!important;border-color:var(--line,#4b382d)!important;color:var(--text,#e2d3c8)!important}[data-theme=dark] .cropper-modal-header{background:#231a14!important;border-bottom-color:var(--line,#4b382d)!important}html[data-theme=dark] .rm-room-btn{background:#2a1a17!important;border-color:#7f1d1d!important;color:#f87171!important}html[data-theme=dark] .rm-room-btn:hover{background:#3b1c18!important;border-color:#dc2626!important;color:#fca5a5!important}html[data-theme=dark] #landing-toast,html[data-theme=dark] #notification-toast,html[data-theme=dark] #profileToast,html[data-theme=dark] .admin-verif-toast,html[data-theme=dark] .booking-toast,html[data-theme=dark] .notification-toast,html[data-theme=dark] .profile-toast,html[data-theme=dark] .room-booking-success-toast,html[data-theme=dark] .toast,html[data-theme=dark] .toast-popup{background:#32241d!important;border:1px solid var(--line,#4b382d)!important;color:var(--ink,#fff7f2)!important;box-shadow:0 16px 36px rgba(0,0,0,.55)!important}html[data-theme=dark] #confirmModal,html[data-theme=dark] #confirmModal *,html[data-theme=dark] #dorm-modal,html[data-theme=dark] #dorm-modal *,html[data-theme=dark] #inspectModal,html[data-theme=dark] #inspectModal *,html[data-theme=dark] #rejectModal,html[data-theme=dark] #rejectModal *,html[data-theme=dark] #resultModal,html[data-theme=dark] #resultModal *,html[data-theme=dark] #room-modal,html[data-theme=dark] #room-modal *,html[data-theme=dark] #suspend-modal,html[data-theme=dark] #suspend-modal *,html[data-theme=dark] .cl-dialog,html[data-theme=dark] .cl-dialog *,html[data-theme=dark] .confirm-modal-box,html[data-theme=dark] .confirm-modal-box *,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .cropper-modal-card *,html[data-theme=dark] .delete-account-modal,html[data-theme=dark] .delete-account-modal *,html[data-theme=dark] .inspect-box,html[data-theme=dark] .inspect-box *,html[data-theme=dark] .inspect-modal,html[data-theme=dark] .inspect-modal *,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .logout-modal-card *,html[data-theme=dark] .modal,html[data-theme=dark] .modal *,html[data-theme=dark] .modal-box,html[data-theme=dark] .modal-box *,html[data-theme=dark] .modal-card,html[data-theme=dark] .modal-card *,html[data-theme=dark] .modal-panel,html[data-theme=dark] .modal-panel *,html[data-theme=dark] .policy-modal,html[data-theme=dark] .policy-modal *,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .policy-modal-panel *,html[data-theme=dark] .ps-card,html[data-theme=dark] .ps-card *,html[data-theme=dark] .settings-modal-card,html[data-theme=dark] .settings-modal-card *,html[data-theme=dark] .suspend-modal-dialog,html[data-theme=dark] .suspend-modal-dialog *,html[data-theme=dark] dialog,html[data-theme=dark] dialog *,html[data-theme=dark] dialog.us-confirm,html[data-theme=dark] dialog.us-confirm *{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important}html[data-theme=dark] .cl-dialog h2,html[data-theme=dark] .cropper-modal-title,html[data-theme=dark] .delete-modal-title,html[data-theme=dark] .inspect-box h3,html[data-theme=dark] .logout-modal-title,html[data-theme=dark] .modal-panel h2,html[data-theme=dark] .modal-panel h3,html[data-theme=dark] .policy-card h2,html[data-theme=dark] .policy-modal-header h2,html[data-theme=dark] .ps-card h2,html[data-theme=dark] .suspend-modal-dialog h2,html[data-theme=dark] dialog h1,html[data-theme=dark] dialog h2,html[data-theme=dark] dialog h3,html[data-theme=dark] dialog h4,html[data-theme=dark] dialog.us-confirm .us-confirm-panel h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;color:var(--head,#fff7f2)!important}html[data-theme=dark] body.landlord-settings-page .shell,html[data-theme=dark] body.landlord-tenants-page .shell{background:#5c4538!important;border:1px solid #6e5344!important;box-shadow:0 30px 60px rgba(0,0,0,.45)!important}html[data-theme=dark] body.landlord-settings-page .topbar,html[data-theme=dark] body.landlord-tenants-page .topbar{background:transparent!important;border-bottom-color:#3d2d24!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page .requests-page-title,html[data-theme=dark] body.landlord-tenants-page .topbar h1{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .tenants-table tr:hover td{background:#34261f!important}html[data-theme=dark] body.landlord-tenants-page .dorms-stay-filters{background:#5c4538!important;border-color:#6e5344!important}html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn{color:#e2d3c8!important}html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn.is-active,html[data-theme=dark] body.landlord-tenants-page .stay-filter-btn:hover{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .stay-pill{background:#5c4538!important;box-shadow:inset 0 -1px 0 #e2ab94!important}html[data-theme=dark] body.landlord-tenants-page .request-room-meta,html[data-theme=dark] body.landlord-tenants-page .tenant-meta-email{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer{background:#2b1f19!important;border-left-color:#563e33!important;color:#d9c8bc!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-drawer-header{background:#231914!important;border-bottom-color:#563e33!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-header,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .settings-section-title{border-bottom-color:#563e33!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-title,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-name,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .settings-section-title{color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-card>span,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-thumb span,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-label,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-role-badge,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-role-separator,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-verification-status{color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-card,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-avatar{background:#231914!important;border:1px solid #563e33!important;color:#d9c8bc!important;box-shadow:none!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .profile-view-avatar{border-width:2px!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer #drawer-status-pill{background:#3b2b23!important;border:1px solid #6e5344!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-thumb{background:#1a110d!important;border:1px solid #563e33!important;color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .id-document-view-btn,html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val a{color:#e2ab94!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .info-display-val span{color:#c5a898!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .badge-verified-student{background:#183b2a!important;border:1px solid #357653!important;color:#bbf7d0!important}html[data-theme=dark] body.landlord-tenants-page #tenant-drawer .tenant-verification-pending{background:#47351e!important;border-color:#8b692d!important;color:#fde68a!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:4px!important;height:auto!important;padding:3px 9px!important;border:1px solid #c48974!important;border-radius:6px!important;background:#3b2b23!important;color:#fff7f2!important;font:700 .74rem DM Sans,sans-serif!important;line-height:1.3!important;box-shadow:none!important;transform:none!important;transition:all .16s ease!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details:hover{background:#5f4435!important;border-color:#e2ab94!important;color:#fff7f2!important;filter:none!important;scale:none!important;transform:translateY(-1px)!important}html[data-theme=dark] body.landlord-tenants-page .btn-tenant-view-details:active{background:#4b382d!important;border-color:#e2ab94!important;color:#fff7f2!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;padding:9px 18px!important;border:1px solid hsla(0,91%,71%,.45)!important;border-radius:10px!important;background:#231914!important;color:#fca5a5!important;font:600 13px DM Sans,sans-serif!important;box-shadow:none!important;transform:scale(1)!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end:hover:not(.is-loading){background:rgba(220,38,38,.18)!important;border-color:#f87171!important;color:#fecaca!important;box-shadow:0 6px 18px rgba(0,0,0,.35)!important;transform:scale(1.06)!important}html[data-theme=dark] body.landlord-tenants-page .btn-action-end:active:not(.is-loading){background:rgba(220,38,38,.28)!important;border-color:#f87171!important;color:#fecaca!important;transform:scale(1.1)!important}html[data-theme=dark] .dorms-stay-filters,html[data-theme=dark] .tenants-tabs{background:transparent;border-top-color:#6e5344;border-bottom-color:#6e5344}.hover\:bg-slate-50:hover{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity,1))}.hover\:bg-slate-900:hover{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}
/* landlord-tailwind:settings:end */
    </style>
    <script src="../assets/js/theme.js"></script>
    <style>
        /* Specific enhancements for Landlord Verification Badges */
        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 999px;
            letter-spacing: 0.2px;
        }
        .verification-badge.is-verified {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .verification-badge.is-pending {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .verification-badge.is-rejected {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .verification-badge.is-unverified {
            background: #f5f5f4;
            color: #57534e;
            border: 1px solid #e7e5e4;
        }
        .verification-callout {
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.92rem;
            line-height: 1.45;
        }
        .verification-callout.is-pending {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #78350f;
        }
        .verification-callout.is-rejected {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .doc-preview-card {
            background: #fcf8f5;
            border: 1px solid #ebd9cb;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        dialog.ps-modal{position:fixed!important;top:50%!important;left:50%!important;transform:translate(-50%,-50%)!important;margin:0!important;width:min(92vw,420px)!important;max-width:420px!important;padding:0!important;border:1px solid #ebd9cb!important;border-radius:20px!important;background:#fff!important;color:#3b2820!important;box-shadow:0 20px 50px rgba(45,25,18,.28)!important;text-align:center!important;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;overflow:hidden!important;z-index:999999!important}
        dialog.ps-modal::backdrop{position:fixed!important;inset:0!important;background:rgba(30,15,10,.58)!important;backdrop-filter:blur(5px)!important;-webkit-backdrop-filter:blur(5px)!important}
        dialog.ps-modal .ps-card{padding:28px 28px 24px!important;text-align:center!important;box-sizing:border-box!important}
        dialog.ps-modal .ps-icon{width:64px!important;height:64px!important;margin:0 auto 16px!important;display:flex!important;align-items:center!important;justify-content:center!important;border-radius:50%!important;background:#ecfdf5!important;color:#059669!important;border:1px solid #a7f3d0!important}
        dialog.ps-modal .ps-icon svg{width:32px!important;height:32px!important;stroke-width:2.2!important;fill:none!important;stroke:currentColor!important}
        dialog.ps-modal .ps-title,dialog.ps-modal h2{font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif!important;font-weight:700!important;font-size:1.3rem!important;color:#292524!important;margin:0 0 8px!important;line-height:1.3!important}
        dialog.ps-modal .ps-msg,dialog.ps-modal p{font-size:.92rem!important;color:#78665c!important;line-height:1.5!important;margin:0 0 24px!important}
        dialog.ps-modal .ps-actions{display:flex!important;gap:12px!important;justify-content:center!important}
        dialog.ps-modal .ps-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:100%!important;height:44px!important;padding:12px 18px!important;border:none!important;border-radius:10px!important;background:#7a4b3a!important;color:#fff!important;font-family:DM Sans,sans-serif!important;font-weight:600!important;font-size:.92rem!important;cursor:pointer!important;text-decoration:none!important;box-shadow:0 2px 6px rgba(122,75,58,.25)!important;transition:background .18s ease,transform .18s ease,box-shadow .18s ease!important}
        dialog.ps-modal .ps-btn:hover{background:#5d3627!important;transform:translateY(-1px)!important;box-shadow:0 4px 12px rgba(122,75,58,.35)!important}
        dialog.ps-modal .ps-btn:active{transform:scale(0.98)!important}
        [data-theme="dark"] dialog.ps-modal,html[data-theme="dark"] dialog.ps-modal{background:#2b1f19!important;border-color:#4b382d!important;color:#fff7f2!important;box-shadow:0 20px 50px rgba(0,0,0,.5)!important}
        [data-theme="dark"] dialog.ps-modal .ps-title,[data-theme="dark"] dialog.ps-modal h2,html[data-theme="dark"] dialog.ps-modal .ps-title,html[data-theme="dark"] dialog.ps-modal h2{color:#fff7f2!important}
        [data-theme="dark"] dialog.ps-modal .ps-msg,[data-theme="dark"] dialog.ps-modal p,html[data-theme="dark"] dialog.ps-modal .ps-msg,html[data-theme="dark"] dialog.ps-modal p{color:#d9c8bc!important}
        [data-theme="dark"] dialog.ps-modal .ps-icon,html[data-theme="dark"] dialog.ps-modal .ps-icon{background:#1e3a2f!important;color:#34d399!important;border-color:#065f46!important}
        [data-theme="dark"] dialog.ps-modal .ps-btn,html[data-theme="dark"] dialog.ps-modal .ps-btn{background:#c48974!important;color:#1e140f!important}
        [data-theme="dark"] dialog.ps-modal .ps-btn:hover,html[data-theme="dark"] dialog.ps-modal .ps-btn:hover{background:#d89b86!important}
        .doc-preview-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #ebd9cb;
            display: grid;
            place-items: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 20px;
        }
        .doc-preview-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        /* Modal for preview */
        .preview-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .preview-modal.is-active {
            display: flex;
        }
        .preview-modal-box {
            background: #fff;
            border-radius: 18px;
            max-width: 650px;
            width: 100%;
            padding: 22px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            animation: zoomIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .preview-modal-img {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 10px;
            display: block;
            margin: 14px auto 0;
            object-fit: contain;
            background: #f5f5f4;
        }
        @keyframes zoomIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js?v=11"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=33"></script>
</head>
<body class="settings-page landlord-settings-page">
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
        <symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
        <symbol id="pencil-icon" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Unified Landlord Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Landlord Portal">
        <a href="dashboard.php" class="brand" title="UniStay Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Main navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="dorms.php" title="Dorms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
            <a href="requests.php" title="Requests"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Requests</span><span class="nav-badge-pill" id="sidebar-requests-badge" <?= $sidebarPendingCount > 0 ? '' : 'style="display:none;"' ?>><?= $sidebarPendingCount > 99 ? '99+' : ($sidebarPendingCount > 0 ? $sidebarPendingCount : '') ?></span></a>
            <a href="settings.php" title="Settings" aria-current="page"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <!-- Shell Container -->
    <div class="shell">
        <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
        <header class="topbar">
            <div class="topbar-main-row">
                <h1 id="headerTitle">Settings</h1>
            <div class="profile">
                <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme"></button>
                <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
                    <svg aria-hidden="true"><use href="#bell-icon"/></svg>
                </button>
                <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($effectiveAvatar) ? 'style="background-image:url(\''.htmlspecialchars($effectiveAvatar, ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
                    <?php if (!empty($effectiveAvatar)): ?>
                        <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                        <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($userInitials) ?>
                    <?php endif; ?>
                </button>
                <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
                    <h2 id="popover-title">Notifications</h2>
                    <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when student bookings arrive.</p></div>
                    <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
                    <a class="view-all" id="view-all" href="notifications.php?from=settings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
                </section>
            </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="settings-tabs" role="tablist">
                <div class="settings-tabs-indicator" id="landlordTabsIndicator"></div>
                <button type="button" class="settings-tab-btn is-active" onclick="switchTab('profile')" id="btn-profile" role="tab" aria-selected="true">Profile</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('security')" id="btn-security" role="tab" aria-selected="false" <?= $mandatoryProfileSetup ? 'disabled aria-disabled="true"' : '' ?>>Account and Security</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('notifications')" id="btn-notifications" role="tab" aria-selected="false" <?= $mandatoryProfileSetup ? 'disabled aria-disabled="true"' : '' ?>>Notifications</button>
            </div>
        </header>

        <main id="settings-main" tabindex="-1">
            <!-- Alerts -->
            <?php if (!empty($successMessage)): ?>
                <dialog id="profileSuccessModal" class="ps-modal" aria-labelledby="psTitle" aria-describedby="psMsg">
                    <div class="ps-card">
                        <div class="ps-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
                        </div>
                        <h2 id="psTitle" class="ps-title" style="margin: 0 0 20px; font-size: 1.25rem; font-weight: 700; text-align: center;">Profile updated successfully!</h2>
                        <div class="ps-actions">
                            <button type="button" id="psClose" class="ps-btn">Continue</button>
                        </div>
                    </div>
                </dialog>
                <script>
                    (function () {
                        var d = document.getElementById('profileSuccessModal');
                        if (!d || typeof d.showModal !== 'function') return;
                        function close() {
                            d.close();
                            d.remove();
                        }
                        d.addEventListener('click', function (e) { if (e.target === d) close(); });
                        var btn = document.getElementById('psClose');
                        if (btn) btn.addEventListener('click', close);
                        d.showModal();
                    })();
                </script>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="settings-alert is-error" role="alert">⚠ <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- TAB 1: PROFILE -->
            <div id="tab-profile" class="settings-tab-content is-active">
                <!-- VIEW MODE CARD -->
                <div id="profileViewCard" class="settings-card">
                    <div class="profile-view-header">
                        <div class="profile-view-identity">
                            <div class="profile-view-avatar">
                                <?php if (!empty($effectiveAvatar)): ?>
                                    <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                    <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($userInitials) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h2 class="profile-view-name"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Landlord Profile') ?><?php if ($vStatus === 'approved'): ?><span class="verified-check" role="img" aria-label="Verified host" title="Verified host"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span><?php endif; ?></h2>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                                    <span class="profile-role-badge">Landlord</span>
                                    <?php if ($vStatus === 'pending'): ?>
                                        <span class="verification-badge is-pending">⏳ Verification Pending</span>
                                    <?php elseif ($vStatus === 'rejected'): ?>
                                        <span class="verification-badge is-rejected">⚠ Action Required</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-settings-secondary btn-landlord-edit-profile" onclick="toggleEditMode()">
                            <svg style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><use href="#pencil-icon"/></svg> Edit Profile
                        </button>
                    </div>

                    <h3 class="settings-section-title">Personal Information</h3>
                    <div class="settings-grid-3">
                        <div class="info-display-group">
                            <span class="info-display-label">First Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['first_name'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Middle Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['middle_name'] ?: 'None') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Last Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['last_name'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Phone Number</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['phone_number'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Facebook Link</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['facebook_link'] ?: 'None') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Email Address</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['email'] ?: ($user['personal_email'] ?? 'Not specified')) ?></div>
                        </div>


                    </div>

                    <h3 class="settings-section-title">Uploaded Identification</h3>

                    <!-- Callouts for Verification Status -->
                    <?php if ($vStatus === 'pending'): ?>
                        <div class="verification-callout is-pending" style="margin-bottom: 16px;">
                            <span>⏳</span>
                            <div>
                                <strong>Government ID Submitted &amp; Pending Admin Review</strong>
                                <p style="margin:2px 0 0; font-size:0.86rem;">Our administrative team is reviewing your documents. Once approved, your listings and verified host badge will be activated.</p>
                            </div>
                        </div>
                    <?php elseif ($vStatus === 'rejected'): ?>
                        <div class="verification-callout is-rejected" style="margin-bottom: 16px;">
                            <span>⚠</span>
                            <div>
                                <strong>Verification Needs Attention</strong>
                                <p style="margin:2px 0 0; font-size:0.86rem;"><?= !empty($user['feedback']) ? htmlspecialchars($user['feedback']) : 'Please upload a clearer copy of your government ID by clicking Edit Profile.' ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="id-documents-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;"><div class="id-document-card">
                            <div class="id-document-meta">
                                <span class="id-document-title">Government ID</span>
                                <?php if (!empty($user['gov_id_file'])): ?>
                                    <button type="button" class="id-document-view-btn" onclick="openLightbox('<?= htmlspecialchars($govIdDisplayUrl) ?>', 'Government ID')">View full photo ↗</button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($user['gov_id_file'])): ?>
                                <div class="id-document-thumb" onclick="openLightbox('<?= htmlspecialchars($govIdDisplayUrl) ?>', 'Government ID')">
                                    <img src="<?= htmlspecialchars($govIdDisplayUrl) ?>" alt="Government ID Preview">
                                </div>
                                <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></span>
                            <?php else: ?>
                                <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem;">
                                    <span style="font-size: 26px;">🪪</span>
                                    <span>No Government ID uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div></div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card"  style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo'] ?: ($user['photo'] ?? '')) ?>">
                    <input type="hidden" name="cropped_profile_photo" id="cropped_profile_photo" value="">
                    <input type="hidden" name="existing_gov_id" value="<?= htmlspecialchars((string)($user['gov_id'] ?: ($user['gov_id_file'] ?? ''))) ?>">
                    <input type="hidden" name="remove_gov_id" id="remove_gov_id" value="0">

                    <div class="avatar-edit-box">
                        <div class="avatar-edit-preview-wrap">
                            <div class="avatar-preview-circle" id="avatarPreviewContainer">
                                <?php if (!empty($effectiveAvatar)): ?>
                                    <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                    <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($userInitials) ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="avatar-edit-btn" onclick="document.getElementById('profile_photo_input').click();" title="Change photo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                            </button>
                            <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="initCropper(this)">
                        </div>
                        <div>
                            <h3 style="color: #3b2820; font-size: 1.15rem; margin-bottom: 4px;"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Update Profile') ?></h3>
                            <span class="profile-role-badge">Role: <?= htmlspecialchars($user['role'] ?? 'Landlord') ?></span>
                        </div>
                    </div>

                    <h3 class="settings-section-title" style="margin-top:0;">Edit Personal Information</h3>

                    <div class="settings-grid-3">
                        <div class="settings-field">
                            <label class="settings-label">First Name *</label>
                            <input type="text" name="first_name" class="settings-input" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Middle Name</label>
                            <input type="text" name="middle_name" class="settings-input" value="<?= htmlspecialchars($user['middle_name']) ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Last Name *</label>
                            <input type="text" name="last_name" class="settings-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Phone Number *</label>
                            <div class="input-prefix-wrapper">
                                <span class="field-icon" aria-hidden="true" title="Phone">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                </span>
                                <span class="input-prefix-addon">+63</span>
                                <input type="tel" name="phone_number" class="settings-input" value="<?= htmlspecialchars(preg_replace('/^(?:\+?63|0)/', '', $user['phone_number'] ?? '')) ?>" placeholder="9XXXXXXXXX" inputmode="numeric" maxlength="15" required>
                            </div>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Facebook Link *</label>
                            <div class="input-prefix-wrapper">
                                <span class="field-icon" aria-hidden="true" title="Facebook">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                                </span>
                                <span class="input-prefix-addon">facebook.com/</span>
                                <input type="text" name="facebook_link" class="settings-input" value="<?= htmlspecialchars(preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $user['facebook_link'])) ?>" placeholder="username or profile ID" required>
                            </div>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Email Address *</label>
                            <div class="input-with-icon-wrapper">
                                <span class="field-icon" aria-hidden="true" title="Email">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                </span>
                                <input type="email" name="email" class="settings-input" value="<?= htmlspecialchars($user['email'] ?: $user['personal_email']) ?>" required>
                            </div>
                        </div>



</div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                        <?php if (!$mandatoryProfileSetup): ?><button type="button" class="btn-settings-secondary" onclick="cancelEditProfile()">Cancel</button><?php endif; ?>
                        <button type="submit" class="btn-settings-primary" id="profileSaveButton" disabled aria-disabled="true">Save Changes</button>
                    </div>
                    <p class="profile-save-hint" id="profileSaveHint" role="status">Complete every required field and upload a government ID to enable Save Changes.</p>
                </form>
            </div>

            <!-- TAB 2: ACCOUNT AND SECURITY -->
            <div id="tab-security" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Manage Account</h3>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0; margin-top:6px;">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.96rem;">Password</strong>
                            <p style="margin: 3px 0 0; font-size: 0.85rem; color: #78665c;">Modify your account password securely.</p>
                        </div>
                        <button type="button" class="btn-settings-secondary settings-change-password-btn" onclick="togglePasswordFrame()">Change password</button>
                    </div>

                    <div id="passwordFrame" class="password-box" style="<?= (!empty($passwordSuccessMessage) || ($errorMessage !== '' && ($_POST['action'] ?? '') === 'update_password')) ? 'display: block;' : '' ?>">
                        <?php if (!empty($passwordSuccessMessage)): ?>
                            <div style="background:#e8f5e9; border:1px solid #c8e6c9; color:#2e7d32; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-weight:600; font-size:0.9rem;">
                                <?= htmlspecialchars($passwordSuccessMessage) ?>
                            </div>
                        <?php endif; ?>
                        <form action="settings.php" method="POST">
                            <input type="hidden" name="action" value="update_password">
                            <div class="settings-field" style="margin-bottom: 14px;">
                                <label class="settings-label">Current password *</label>
                                <input type="password" name="current_password" class="settings-input" placeholder="Enter current password" required>
                            </div>
                            <div class="settings-grid-2" style="margin-bottom: 18px;">
                                <div class="settings-field">
                                    <label class="settings-label">New password *</label>
                                    <input type="password" name="new_password" class="settings-input" placeholder="Enter new password (min. 8 characters)" required minlength="8">
                                </div>
                                <div class="settings-field">
                                    <label class="settings-label">Confirm new password *</label>
                                    <input type="password" name="confirm_password" class="settings-input" placeholder="Confirm new password" required minlength="8">
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="btn-settings-primary">Update Password</button>
                                <button type="button" class="btn-settings-secondary" onclick="togglePasswordFrame()">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0; margin-top:6px; border-top:1px solid #ebd9cb;">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.96rem;">Log Out</strong>
                            <p style="margin: 3px 0 0; font-size: 0.85rem; color: #78665c;">Sign out of your UniStay account on this device.</p>
                        </div>
                        <a class="btn-settings-secondary settings-change-password-btn" href="../auth/logout.php">Log out</a>
                    </div>

                    <div class="danger-zone-box">
                        <h4 style="color: #dc2626; font-size: 1.05rem; margin:0;">Danger Zone</h4>
                        <p style="font-size: 0.85rem; color: #78665c; margin:3px 0 14px;">Once you delete your account, your profile and bookings cannot be recovered. Please be certain.</p>
                        <button type="button" class="btn-settings-danger" onclick="openDeleteModal()">Delete Account</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NOTIFICATIONS -->
            <div id="tab-notifications" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Notification Preferences</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 24px;">Configure how you receive student booking inquiries, messages, and platform alerts.</p>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Student Booking Inquiries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive instant notifications when students apply for a room</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="togglePushNotificationsLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Verification Status Updates</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive notification when your government ID is reviewed</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="toggleVerificationNotificationsLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Email Summaries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive periodic occupancy and monthly earnings summaries</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="toggleEmailSummariesLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div id="deleteModal" class="settings-modal-overlay">
        <div class="settings-modal-card">
            <h3 style="color: #dc2626; font-family:'DM Sans', sans-serif; font-weight:700; margin-top: 0; font-size: 1.35rem;">Delete Account Confirmation</h3>
            <p style="font-size: 0.88rem; color: #5f3425; line-height: 1.5; margin: 12px 0 18px;">To permanently delete your account, please type your registered email address below to confirm:</p>
            <form action="settings.php" method="POST">
                <input type="hidden" name="action" value="delete_account">
                <div style="margin-bottom: 18px;">
                    <input type="email" name="confirm_email" class="settings-input" style="width:100%;" placeholder="<?= htmlspecialchars($user['email'] ?: ($user['personal_email'] ?? '')); ?>" required>
                </div>
                <div class="delete-modal-actions" style="display:flex;gap:12px;justify-content:center;align-items:center;">
                    <button type="button" class="btn-settings-secondary" onclick="closeDeleteModal()" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn-settings-danger" style="flex:1;">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

<div id="cropperModal" class="cropper-modal-overlay">
        <div class="cropper-modal-card">
            <div class="cropper-modal-header">
                <h3 class="cropper-modal-title">Crop Profile Photo</h3>
                <button type="button" class="profile-toast-close" onclick="closeCropperModal()" aria-label="Close cropper">&times;</button>
            </div>
            <div class="cropper-canvas-wrap" id="cropperCanvasWrap">
                <canvas id="cropperCanvas" width="300" height="300"></canvas>
                <div class="cropper-mask"></div>
            </div>
            <div class="cropper-controls">
                <button type="button" class="cropper-btn-round" onclick="adjustZoom(-0.15)" title="Zoom Out">−</button>
                <input type="range" id="cropperZoom" class="cropper-zoom-slider" min="0.6" max="3" step="0.05" value="1" oninput="setZoom(parseFloat(this.value))">
                <button type="button" class="cropper-btn-round" onclick="adjustZoom(0.15)" title="Zoom In">+</button>
            </div>
            <p style="margin: 0; font-size: 0.82rem; color: #78665c; text-align: center;">Drag the image to position inside the circle, and adjust zoom.</p>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;">
                <button type="button" class="btn-settings-secondary" onclick="closeCropperModal()">Cancel</button>
                <button type="button" class="btn-settings-primary" onclick="applyCroppedAvatar()">Apply Crop</button>
            </div>
        </div>
    </div>
<div id="idLightbox" class="id-lightbox-overlay" onclick="closeLightbox(event)">
        <div class="id-lightbox-content">
            <button type="button" class="id-lightbox-close" onclick="closeLightboxDirect()" aria-label="Close full view">&times;</button>
            <img id="lightboxImg" class="id-lightbox-img" src="" alt="Full ID Preview">
            <p id="lightboxCaption" style="color: #ffffff; margin-top: 12px; font-weight: 600; font-size: 1rem;"></p>
        </div>
    </div>
    <script>
        function updateLandlordTabIndicator(btn) {
            const indicator = document.getElementById('landlordTabsIndicator');
            if (!indicator || !btn) return;
            indicator.style.transform = `translateX(${btn.offsetLeft}px)`;
            indicator.style.width = `${btn.offsetWidth}px`;
        }

        function switchTab(tabId) {
            document.querySelectorAll('.settings-tab-btn').forEach(btn => {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.settings-tab-content').forEach(content => {
                content.classList.remove('is-active');
            });

            const activeBtn = document.getElementById('btn-' + tabId);
            const activeTab = document.getElementById('tab-' + tabId);
            if (activeBtn && activeTab) {
                activeBtn.classList.add('is-active');
                activeBtn.setAttribute('aria-selected', 'true');
                activeTab.classList.add('is-active');
                updateLandlordTabIndicator(activeBtn);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active') || document.getElementById('btn-profile');
            if (activeBtn) updateLandlordTabIndicator(activeBtn);
        });
        window.addEventListener('load', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active');
            if (activeBtn) updateLandlordTabIndicator(activeBtn);
        });
        window.addEventListener('resize', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active');
            if (activeBtn) updateLandlordTabIndicator(activeBtn);
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => {
                const activeBtn = document.querySelector('.settings-tab-btn.is-active');
                if (activeBtn) updateLandlordTabIndicator(activeBtn);
            });
        }

        function togglePasswordFrame() {
            const frame = document.getElementById('passwordFrame');
            if (frame) frame.classList.toggle('is-open');
        }

        const initialProfileData = {
            govIdFile: <?= json_encode($govIdDisplayUrl) ?>,
            govIdName: <?= json_encode(!empty($user['gov_id_file']) ? basename($user['gov_id_file']) : '') ?>,
            avatarHtml: <?= json_encode(
                !empty($effectiveAvatar)
                    ? '<img src="' . htmlspecialchars($effectiveAvatar) . '" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';if(this.nextElementSibling)this.nextElementSibling.style.display=\'inline\';"><span style="display:none;">' . htmlspecialchars($userInitials) . '</span>'
                    : htmlspecialchars($userInitials)
            ) ?>
        };

        function cancelEditProfile() {
            const form = document.getElementById('profileEditForm');
            if (form) {
                form.reset();
            }

            // Clear cropped avatar
            const croppedInput = document.getElementById('cropped_profile_photo');
            if (croppedInput) croppedInput.value = '';

            // Reset file inputs
            const photoInput = document.getElementById('profile_photo_input');
            if (photoInput) photoInput.value = '';
            const govInput = document.getElementById('gov_id_input');
            if (govInput) govInput.value = '';

            // Undo any staged ID photo removals
            const rmGov = document.getElementById('remove_gov_id');
            if (rmGov) rmGov.value = '0';

            // Restore avatar preview container
            const avatarPreview = document.getElementById('avatarPreviewContainer');
            if (avatarPreview) {
                avatarPreview.innerHTML = initialProfileData.avatarHtml;
            }

            // Restore Gov ID dropzone
            const govEmpty = document.getElementById('dropzone_gov_id_empty');
            const govFilled = document.getElementById('dropzone_gov_id_filled');
            const govPreview = document.getElementById('gov_id_preview');
            const govLabelFilled = document.getElementById('gov_id_label_filled');
            const govLabelEmpty = document.getElementById('gov_id_label');
            if (initialProfileData.govIdFile) {
                if (govEmpty) govEmpty.style.display = 'none';
                if (govFilled) govFilled.style.display = 'flex';
                if (govPreview) govPreview.src = initialProfileData.govIdFile;
                if (govLabelFilled) govLabelFilled.innerText = initialProfileData.govIdName;
            } else {
                if (govEmpty) govEmpty.style.display = 'block';
                if (govFilled) govFilled.style.display = 'none';
                if (govPreview) govPreview.src = '';
                if (govLabelEmpty) govLabelEmpty.innerText = 'No file chosen';
            }

            // Switch view back to profileViewCard
            const viewCard = document.getElementById('profileViewCard');
            const editForm = document.getElementById('profileEditForm');
            if (viewCard && editForm) {
                viewCard.style.display = 'block';
                editForm.style.display = 'none';
            }
        }

        function toggleEditMode() {
            const viewCard = document.getElementById('profileViewCard');
            const editForm = document.getElementById('profileEditForm');
            if (!viewCard || !editForm) return;
            if (viewCard.style.display === 'none') {
                viewCard.style.display = 'block';
                editForm.style.display = 'none';
            } else {
                viewCard.style.display = 'none';
                editForm.style.display = 'block';
            }
        }

        // ==================== CROPPER LOGIC ====================
        let cropperImg = null;
        let cropperScale = 1;
        let cropperBaseScale = 1;
        let cropperOffsetX = 0;
        let cropperOffsetY = 0;
        let isDragging = false;
        let dragStartX = 0;
        let dragStartY = 0;

        function initCropper(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    cropperImg = new Image();
                    cropperImg.onload = function() {
                        const modal = document.getElementById('cropperModal');
                        if (modal) modal.classList.add('is-open');

                        // Base scale ensures image covers at least the 200px circle
                        cropperBaseScale = Math.max(200 / cropperImg.width, 200 / cropperImg.height);
                        cropperScale = cropperBaseScale;
                        document.getElementById('cropperZoom').value = 1;

                        // Center image on (150, 150)
                        cropperOffsetX = 150 - (cropperImg.width * cropperScale) / 2;
                        cropperOffsetY = 150 - (cropperImg.height * cropperScale) / 2;

                        drawCropper();
                        bindCropperEvents();
                    };
                    cropperImg.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        }

        function drawCropper() {
            const canvas = document.getElementById('cropperCanvas');
            if (!canvas || !cropperImg) return;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const drawW = cropperImg.width * cropperScale;
            const drawH = cropperImg.height * cropperScale;
            ctx.drawImage(cropperImg, cropperOffsetX, cropperOffsetY, drawW, drawH);
        }

        function adjustZoom(delta) {
            const slider = document.getElementById('cropperZoom');
            if (!slider) return;
            let val = parseFloat(slider.value) + delta;
            val = Math.max(parseFloat(slider.min), Math.min(parseFloat(slider.max), val));
            slider.value = val;
            setZoom(val);
        }

        function setZoom(factor) {
            if (!cropperImg) return;
            const oldScale = cropperScale;
            cropperScale = cropperBaseScale * factor;

            // Keep center point (150, 150) stationary
            const centerRelX = (150 - cropperOffsetX) / oldScale;
            const centerRelY = (150 - cropperOffsetY) / oldScale;

            cropperOffsetX = 150 - centerRelX * cropperScale;
            cropperOffsetY = 150 - centerRelY * cropperScale;
            drawCropper();
        }

        let eventsBound = false;
        function bindCropperEvents() {
            if (eventsBound) return;
            eventsBound = true;
            const wrap = document.getElementById('cropperCanvasWrap');

            function startDrag(clientX, clientY) {
                isDragging = true;
                dragStartX = clientX;
                dragStartY = clientY;
            }
            function moveDrag(clientX, clientY) {
                if (!isDragging) return;
                const dx = clientX - dragStartX;
                const dy = clientY - dragStartY;
                dragStartX = clientX;
                dragStartY = clientY;
                cropperOffsetX += dx;
                cropperOffsetY += dy;
                drawCropper();
            }
            function endDrag() {
                isDragging = false;
            }

            wrap.addEventListener('mousedown', e => startDrag(e.clientX, e.clientY));
            window.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
            window.addEventListener('mouseup', endDrag);

            wrap.addEventListener('touchstart', e => {
                if (e.touches.length === 1) {
                    startDrag(e.touches[0].clientX, e.touches[0].clientY);
                }
            }, { passive: true });
            window.addEventListener('touchmove', e => {
                if (e.touches.length === 1) {
                    moveDrag(e.touches[0].clientX, e.touches[0].clientY);
                }
            }, { passive: true });
            window.addEventListener('touchend', endDrag);

            // Wheel zoom support
            wrap.addEventListener('wheel', e => {
                e.preventDefault();
                adjustZoom(e.deltaY < 0 ? 0.08 : -0.08);
            }, { passive: false });
        }

        function applyCroppedAvatar() {
            if (!cropperImg) return;
            // High-resolution offscreen export (400x400)
            const offscreen = document.createElement('canvas');
            offscreen.width = 400;
            offscreen.height = 400;
            const ctx2 = offscreen.getContext('2d');

            // Source rectangle mapped from the 200px preview circle centered at (150, 150)
            const sx = (150 - cropperOffsetX - 100) / cropperScale;
            const sy = (150 - cropperOffsetY - 100) / cropperScale;
            const sDim = 200 / cropperScale;

            ctx2.drawImage(cropperImg, sx, sy, sDim, sDim, 0, 0, 400, 400);

            const dataUrl = offscreen.toDataURL('image/png', 0.95);
            document.getElementById('cropped_profile_photo').value = dataUrl;

            const preview = document.getElementById('avatarPreviewContainer');
            if (preview) {
                preview.innerHTML = '<img src="' + dataUrl + '" style="width:100%;height:100%;object-fit:cover;" alt="Avatar">';
            }
            closeCropperModal();
        }

        function closeCropperModal() {
            const modal = document.getElementById('cropperModal');
            if (modal) modal.classList.remove('is-open');
        }

        // ==================== ID PHOTO PREVIEW ====================
        function openIdPicker(event, inputId) {
            if (event.target.closest('.dropzone-btn-danger') || event.target.tagName === 'INPUT') return;
            const input = document.getElementById(inputId);
            if (input) input.click();
        }

        function previewIdFile(input, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];

            // 1. Check file size (min 10KB, max 10MB)
            if (file.size < 10 * 1024) {
                alert("The selected file is too small (" + Math.round(file.size / 1024) + " KB) or blank. Please upload a clear photo of your ID (minimum 10 KB).");
                input.value = '';
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                alert("The selected file exceeds the 10 MB limit. Please upload a smaller image file.");
                input.value = '';
                return;
            }

            // 2. Check file type
            const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert("Invalid file format. Please upload a JPG, PNG, or WEBP photo of your ID.");
                input.value = '';
                return;
            }

            // 3. Check image dimensions via Image object
            const reader = new FileReader();
            reader.onload = function(e) {
                const testImg = new Image();
                testImg.onload = function() {
                    if (testImg.naturalWidth < 200 || testImg.naturalHeight < 120) {
                        alert("The image resolution (" + testImg.naturalWidth + "×" + testImg.naturalHeight + "px) is too small to be a legible ID. Minimum required is 200×120 pixels.");
                        input.value = '';
                        return;
                    }
                    const previewImg = document.getElementById(imgId);
                    if (previewImg) previewImg.src = e.target.result;
                    const emptyBox = document.getElementById(emptyBoxId);
                    if (emptyBox) emptyBox.style.display = 'none';
                    const filledBox = document.getElementById(filledBoxId);
                    if (filledBox) filledBox.style.display = 'flex';
                    const filledLabel = document.getElementById(filledLabelId);
                    if (filledLabel) filledLabel.innerText = file.name;
                    const label = document.getElementById(labelId);
                    if (label) label.innerText = file.name;

                    // A newly chosen file cancels any staged removal for this ID
                    const removeFlag = document.getElementById('remove_' + input.id.replace('_input', ''));
                    if (removeFlag) removeFlag.value = '0';
                };
                testImg.onerror = function() {
                    alert("Could not load the file as an image. Please ensure it is a valid photo.");
                    input.value = '';
                };
                testImg.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        // Stage removal of an ID photo: the dropzone goes back to the plain upload area.
        // The file is only deleted on the server when the form is saved.
        function removeIdPhoto(key) {
            // Stage the government ID change until Save Changes.
            const input = document.getElementById(key + '_input');
            if (input) input.value = '';
            const flag = document.getElementById('remove_' + key);
            if (flag) flag.value = '1';

            const preview = document.getElementById(key + '_preview');
            if (preview) preview.removeAttribute('src');
            const filledLabel = document.getElementById(key + '_label_filled');
            if (filledLabel) filledLabel.innerText = '';
            const emptyLabel = document.getElementById(key + '_label');
            if (emptyLabel) emptyLabel.innerText = 'No file chosen';

            const filled = document.getElementById('dropzone_' + key + '_filled');
            const empty = document.getElementById('dropzone_' + key + '_empty');
            if (filled) filled.style.display = 'none';
            if (empty) empty.style.display = 'block';
        }

        function handleDragOver(e, dropzone) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        }

        function handleDragLeave(dropzone) {
            dropzone.classList.remove('dragover');
        }

        function handleDrop(e, inputId, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId) {
            e.preventDefault();
            e.stopPropagation();
            const dropzone = e.currentTarget;
            dropzone.classList.remove('dragover');

            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                const fileInput = document.getElementById(inputId);
                fileInput.files = e.dataTransfer.files;
                previewIdFile(fileInput, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId);
            }
        }

        // ==================== LIGHTBOX MODAL (Smooth Transitions) ====================
        function openLightbox(src, caption) {
            const overlay = document.getElementById('idLightbox');
            const img = document.getElementById('lightboxImg');
            const cap = document.getElementById('lightboxCaption');
            if (overlay && img) {
                img.src = src;
                if (cap) cap.innerText = caption || 'ID Preview';
                overlay.classList.remove('is-closing');
                void overlay.offsetWidth; // Force reflow for smooth entrance
                overlay.classList.add('is-open');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeLightboxDirect() {
            const overlay = document.getElementById('idLightbox');
            if (!overlay || !overlay.classList.contains('is-open')) return;
            overlay.classList.add('is-closing');
            setTimeout(() => {
                overlay.classList.remove('is-open', 'is-closing');
                document.body.style.overflow = '';
            }, 240);
        }

        function closeLightbox(e) {
            if (e.target && e.target.id === 'idLightbox') {
                closeLightboxDirect();
            }
        }
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeLightboxDirect();
            }
        });

        // Dropdown Arrow Flip/Unflip listeners (faces up initially, faces down when open, faces up on close/outside click)
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.select-with-icon-wrapper').forEach(wrap => {
                const select = wrap.querySelector('select');
                if (!select) return;

                select.addEventListener('mousedown', () => {
                    wrap.dataset.wasDropped = wrap.classList.contains('is-dropped') ? '1' : '0';
                });

                select.addEventListener('click', () => {
                    if (wrap.dataset.wasDropped === '1') {
                        wrap.classList.remove('is-dropped');
                    } else {
                        wrap.classList.add('is-dropped');
                    }
                });

                select.addEventListener('change', () => wrap.classList.remove('is-dropped'));
                select.addEventListener('blur', () => wrap.classList.remove('is-dropped'));
            });

            document.addEventListener('click', (e) => {
                document.querySelectorAll('.select-with-icon-wrapper.is-dropped').forEach(wrap => {
                    if (!wrap.contains(e.target)) {
                        wrap.classList.remove('is-dropped');
                    }
                });
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.select-with-icon-wrapper.is-dropped').forEach(wrap => {
                        wrap.classList.remove('is-dropped');
                    });
                }
            });
        });

        function openDeleteModal() {
            const modal = document.getElementById('deleteModal');
            if (modal) modal.classList.add('is-open');
        }
        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            if (modal) modal.classList.remove('is-open');
        }
        const delModal = document.getElementById('deleteModal');
        if (delModal) {
            delModal.addEventListener('click', function(e) {
                if (e.target === delModal) closeDeleteModal();
            });
        }

        if (<?= json_encode($errorMessage !== '' && ($_POST['action'] ?? '') === 'update_profile') ?>) toggleEditMode();
        // Auto open edit mode if mode=edit or #edit in URL
        document.addEventListener('DOMContentLoaded', function() {
            var params = new URLSearchParams(window.location.search);
            if (params.get('mode') === 'edit' || window.location.hash === '#edit') {
                var viewCard = document.getElementById('profileViewCard');
                var editForm = document.getElementById('profileEditForm');
                if (viewCard && editForm) {
                    viewCard.style.display = 'none';
                    editForm.style.display = 'block';
                    editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    </script>
<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (string)($_SESSION['user_id'] ?? ''), 'role' => 'landlord']) ?>;
</script>
<script src="../assets/js/profile-completion-gate.js?v=1"></script>
<script src="../assets/js/notification-preferences.js?v=3"></script>
<script src="../assets/js/landlord-notifications.js?v=7"></script>
</body>
</html>
