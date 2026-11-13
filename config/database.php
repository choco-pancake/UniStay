<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/account_ids.php';

const DB_HOST = 'localhost';
const DB_NAME = 'unistay';
const DB_USER = 'root';
const DB_PASS = '';

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function current_tenant_id(): string
{
    require_once __DIR__ . '/session.php';
    $user = require_signed_in('tenant');
    return (string)$user['id'];
}

function format_user_id(int|string $id, string $role = ''): string
{
    $str = (string)$id;
    if (preg_match('/^[ALT][0-9]+$/', $str)) {
        return $str;
    }
    $num = (int)preg_replace('/\D/', '', $str);
    if ($num <= 0) return $str;
    $role = strtolower(trim($role));
    return match ($role) {
        'tenant' => 'T' . $num,
        'landlord' => 'L' . $num,
        'admin' => 'A' . $num,
        default => (string)$num,
    };
}

function tenant_profile_complete(PDO $pdo, int|string $userId): bool
{
    try {
        $statement = $pdo->prepare(
            'SELECT profile_completed, first_name, last_name, phone_number, facebook_link,
                    university, emergency_name, emergency_relationship, emergency_phone,
                    school_id, gov_id
             FROM tenants WHERE tenant_id = ?'
        );
        $statement->execute([(string)$userId]);
        $account = $statement->fetch();
        if ($account === false || (int) $account['profile_completed'] !== 1) {
            return false;
        }

        foreach (['first_name', 'last_name', 'phone_number', 'facebook_link', 'university', 'emergency_name', 'emergency_relationship', 'emergency_phone', 'school_id', 'gov_id'] as $field) {
            if (trim((string)($account[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    } catch (PDOException $error) {
        error_log('UniStay profile check failed: ' . $error->getMessage());
        return false;
    }
}

function landlord_profile_complete(PDO $pdo, int|string $userId): bool
{
    try {
        $statement = $pdo->prepare('SELECT landlord_id AS id, landlord_id, first_name, last_name, phone_number, facebook_link, email, email AS personal_email, gov_id, status FROM landlords WHERE landlord_id = ?');
        $statement->execute([(string)$userId]);
        $account = $statement->fetch();
        return $account !== false && ($account['status'] ?? '') === 'approved';
    } catch (PDOException $error) {
        error_log('UniStay landlord profile check failed: ' . $error->getMessage());
        return false;
    }
}

/**
 * Returns the id for a new account.
 *
 * The number is always one higher than the highest id in the table, so an
 * empty table starts at 1 again instead of continuing where it left off.
 */
function next_user_id(PDO $pdo, string $table = 'tenants'): int
{
    return next_table_id($pdo, $table, 1);
}

if (!function_exists('next_table_id')) {
    function next_table_id(PDO $pdo, string $table, int $minStart = 1): int
    {
        try {
            $statement = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`");
            $maxId = (int) $statement->fetchColumn();
            return max($minStart, $maxId + 1);
        } catch (PDOException $error) {
            error_log("UniStay next $table id lookup failed: " . $error->getMessage());
            return $minStart;
        }
    }
}

if (!function_exists('reset_table_auto_increment')) {
    function reset_table_auto_increment(PDO $pdo, string $table, int $minStart = 1): void
    {
        try {
            $statement = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$table`");
            $maxId = (int) $statement->fetchColumn();
            $nextId = max($minStart, $maxId + 1);
            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = $nextId");
        } catch (PDOException $error) {
            error_log("UniStay reset AUTO_INCREMENT for $table failed: " . $error->getMessage());
        }
    }
}

/**
 * Returns the account of an email address across admins, landlords, or tenants, or null when there is none.
 */
function find_user_by_email(PDO $pdo, string $email): ?array
{
    if ($email === '') {
        return null;
    }

    try {
        // Check admins
        $stmt = $pdo->prepare('SELECT admin_id AS id, admin_id, first_name, NULL AS middle_name, last_name, email, email AS personal_email, password_hash, \'admin\' AS role, profile_photo AS photo, profile_photo, 1 AS profile_completed FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $acc = $stmt->fetch();
        if ($acc) return $acc;

        // Check landlords
        $stmt = $pdo->prepare('SELECT landlord_id AS id, landlord_id, first_name, middle_name, last_name, email, email AS personal_email, password_hash, \'landlord\' AS role, profile_photo AS photo, profile_photo, 1 AS profile_completed, status, suspension_reason FROM landlords WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $acc = $stmt->fetch();
        if ($acc) return $acc;

        // Check tenants
        $stmt = $pdo->prepare('SELECT tenant_id AS id, tenant_id, first_name, middle_name, last_name, email, email AS personal_email, password_hash, \'tenant\' AS role, profile_photo AS photo, profile_photo, profile_completed, account_status, suspension_reason FROM tenants WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $acc = $stmt->fetch();
        if ($acc) return $acc;

        return null;
    } catch (PDOException $error) {
        error_log('UniStay account lookup failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Returns the account of an email address that exists in the database but was
 * never registered through the sign-up form.
 */
function find_unregistered_account(PDO $pdo, string $email): ?array
{
    if ($email === '') {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            "SELECT tenant_id AS id, tenant_id, 'tenant' AS role, profile_photo FROM tenants
             WHERE email = ?
               AND phone_number IS NULL
               AND facebook_link IS NULL
               AND profile_completed = 0
             LIMIT 1"
        );
        $statement->execute([$email]);
        $account = $statement->fetch();

        return $account === false ? null : $account;
    } catch (PDOException $error) {
        error_log('UniStay unregistered account lookup failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Resolves the newest profile image to a URL usable by pages one directory
 * below the project root (tenant/, landlord/, admin/, and API consumers).
 */
function resolve_user_profile_photo_url(?string $profilePhoto, ?string $fallbackPhoto = null, string $role = ''): string
{
    $raw = trim((string)($profilePhoto ?: $fallbackPhoto ?: ''));
    if ($raw === '') return '';
    if (preg_match('#^(?:(?:https?:)?//|data:|blob:)#i', $raw)) return $raw;

    $raw = str_replace('\\', '/', $raw);
    $base = app_base_path();
    if ($base !== '' && str_starts_with($raw, $base . '/')) {
        $raw = substr($raw, strlen($base) + 1);
    }

    $path = preg_replace('#^(?:\.\./)+#', '', ltrim($raw, './'));
    $path = ltrim((string)$path, '/');
    if ($path === '') return '';

    $candidates = [];
    if (str_starts_with($path, 'tenant/uploads/')) {
        $candidates[] = $path;
        $candidates[] = substr($path, strlen('tenant/'));
    } elseif (str_starts_with($path, 'uploads/')) {
        // Older tenant uploads live in tenant/uploads while landlord uploads
        // live in the root uploads directory. Check the real file so photos
        // remain valid even if an account role or stored path is inconsistent.
        if (strtolower($role) === 'tenant') {
            $candidates[] = 'tenant/' . $path;
            $candidates[] = $path;
        } else {
            $candidates[] = $path;
            $candidates[] = 'tenant/' . $path;
        }
    } else {
        $candidates[] = $path;
    }

    $projectRoot = dirname(__DIR__);
    $resolvedPath = $candidates[0];
    $modifiedAt = false;
    foreach (array_unique($candidates) as $candidate) {
        $absolute = $projectRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        if (is_file($absolute)) {
            $resolvedPath = $candidate;
            $modifiedAt = filemtime($absolute);
            break;
        }
    }

    $url = app_asset_path($resolvedPath);
    return $modifiedAt === false ? $url : $url . '?v=' . $modifiedAt;
}

/**
 * Returns normalized profile metadata for the authenticated user (name, initials, photo, role, school).
 */
function get_authenticated_profile(PDO $pdo, int $userId, ?string $role = null): array
{
    $activeRole = strtolower(trim((string)($role ?: ($_SESSION['role'] ?? 'tenant'))));
    $row = [];
    try {
        if ($activeRole === 'admin') {
            $st = $pdo->prepare("SELECT admin_id AS id, admin_id, first_name, last_name, email, email AS personal_email, profile_photo, profile_photo AS photo, 'admin' AS role FROM admins WHERE admin_id = ? LIMIT 1");
            $st->execute([(string)$userId]);
            $row = $st->fetch() ?: [];
        } elseif ($activeRole === 'landlord') {
            $st = $pdo->prepare("SELECT landlord_id AS id, landlord_id, first_name, last_name, email, email AS personal_email, profile_photo, profile_photo AS photo, 'landlord' AS role FROM landlords WHERE landlord_id = ? LIMIT 1");
            $st->execute([(string)$userId]);
            $row = $st->fetch() ?: [];
        } else {
            $st = $pdo->prepare("SELECT tenant_id AS id, tenant_id, first_name, last_name, email, email AS personal_email, profile_photo, profile_photo AS photo, 'tenant' AS role, university AS school FROM tenants WHERE tenant_id = ? LIMIT 1");
            $st->execute([(string)$userId]);
            $row = $st->fetch() ?: [];
        }
    } catch (PDOException $e) {
        $row = [];
    }

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $name = trim("$first $last");
    $email = (string)($row['email'] ?? ($row['personal_email'] ?? ''));

    if ($name === '') {
        $name = $email !== '' ? $email : 'User';
        $initials = strtoupper(substr($name, 0, 1));
    } else {
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    $photo = resolve_user_profile_photo_url(
        $row['profile_photo'] ?? null,
        $row['photo'] ?? null,
        $activeRole
    );
    if ($photo === '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $photo = 'https://unavatar.io/' . rawurlencode(strtolower(trim($email))) . '?fallback=false';
    }
    $displayRole = ucfirst($activeRole);
    $school = (string)($row['school'] ?? '');

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        if ($name !== '') {
            $_SESSION['user']['name'] = $name;
        }
        if ($email !== '') {
            $_SESSION['user']['email'] = $email;
        }
        $_SESSION['user']['photo'] = $photo;
    }

    return [
        'id' => $userId,
        'name' => $name,
        'role' => $displayRole,
        'photo' => $photo,
        'initials' => $initials,
        'email' => $email,
        'school' => $school
    ];
}

/**
 * Returns verification details for a landlord.
 */
function get_landlord_verification(PDO $pdo, int|string $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT status, feedback, decided_at, gov_id, profile_photo FROM landlords WHERE landlord_id = ? LIMIT 1');
        $stmt->execute([(string)$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $rawStatus = (string)($row['status'] ?? 'pending');
            $status = ($rawStatus === 'approved') ? 'verified' : (($rawStatus === 'declined') ? 'rejected' : 'pending');
            $gid = $row['gov_id'] ?? '';
            return [
                'status' => $status,
                'raw_status' => $rawStatus,
                'feedback' => $row['feedback'] ?? '',
                'notes' => $row['feedback'] ?? '',
                'verified_at' => $row['decided_at'] ?? null,
                'gov_id' => $gid,
                'gov_id_file' => $gid,
                'profile_photo' => $row['profile_photo'] ?? ''
            ];
        }
    } catch (PDOException $e) {
        error_log('UniStay verification lookup failed: ' . $e->getMessage());
    }
    return [
        'status' => 'unverified',
        'raw_status' => 'pending',
        'feedback' => '',
        'notes' => '',
        'verified_at' => null,
        'gov_id' => '',
        'gov_id_file' => '',
        'profile_photo' => ''
    ];
}

/**
 * Returns verification details for a tenant.
 */
function get_tenant_verification(PDO $pdo, int|string $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT verification_status, feedback, verified_at, school_id, gov_id, profile_photo, profile_completed FROM tenants WHERE tenant_id = ? LIMIT 1');
        $stmt->execute([(string)$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $sid = $row['school_id'] ?? '';
            $gid = $row['gov_id'] ?? '';
            return [
                'status' => $row['verification_status'] ?? 'unverified',
                'feedback' => $row['feedback'] ?? '',
                'notes' => $row['feedback'] ?? '',
                'verified_at' => $row['verified_at'] ?? null,
                'school_id' => $sid,
                'school_id_file' => $sid,
                'gov_id' => $gid,
                'gov_id_file' => $gid,
                'profile_photo' => $row['profile_photo'] ?? '',
                'profile_completed' => (int)($row['profile_completed'] ?? 0)
            ];
        }
    } catch (PDOException $e) {
        error_log('UniStay tenant verification lookup failed: ' . $e->getMessage());
    }
    return [
        'status' => 'unverified',
        'feedback' => '',
        'notes' => '',
        'verified_at' => null,
        'school_id' => '',
        'school_id_file' => '',
        'gov_id' => '',
        'gov_id_file' => '',
        'profile_photo' => '',
        'profile_completed' => 0
    ];
}

/**
 * Returns verification submissions for the admin portal (supports landlord and tenant roles).
 */
function admin_get_verification_requests(PDO $pdo, string $role = 'landlord', string $filter = 'all'): array
{
    try {
        $targetRole = in_array($role, ['landlord', 'tenant'], true) ? $role : 'landlord';
        if ($targetRole === 'landlord') {
            $sql = "SELECT id, first_name, middle_name, last_name, email, email AS personal_email, phone_number, facebook_link,
                           residential_address, experience_summary, gov_id, gov_id AS gov_id_file, profile_photo, profile_photo AS photo,
                           status, 
                           CASE WHEN status = 'approved' THEN 'verified' WHEN status = 'declined' THEN 'rejected' ELSE 'pending' END AS verification_status,
                           feedback, feedback AS verification_notes, decided_at AS verified_at, created_at
                    FROM landlords
                    WHERE 1=1";
            if ($filter === 'pending') {
                $sql .= " AND status = 'pending'";
            } elseif ($filter === 'verified' || $filter === 'approved') {
                $sql .= " AND status = 'approved'";
            } elseif ($filter === 'rejected' || $filter === 'declined') {
                $sql .= " AND status = 'declined'";
            }
            $sql .= " ORDER BY CASE WHEN status = 'pending' THEN 0 WHEN status = 'declined' THEN 1 ELSE 2 END, id DESC";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $sql = "SELECT id, first_name, middle_name, last_name, email, email AS personal_email, phone_number, facebook_link,
                           university AS school, university, emergency_name, emergency_relationship, emergency_phone,
                           profile_photo, profile_photo AS photo, school_id, gov_id, school_id AS school_id_file, gov_id AS gov_id_file,
                           verification_status, feedback, feedback AS verification_notes, verified_at, created_at
                    FROM tenants
                    WHERE 1=1";
            if ($filter === 'pending') {
                $sql .= " AND (verification_status = 'pending' OR (verification_status = 'unverified' AND ((school_id IS NOT NULL AND school_id != '') OR (gov_id IS NOT NULL AND gov_id != ''))))";
            } elseif ($filter === 'verified') {
                $sql .= " AND verification_status = 'verified'";
            } elseif ($filter === 'rejected') {
                $sql .= " AND verification_status = 'rejected'";
            } elseif ($filter === 'unverified') {
                $sql .= " AND verification_status = 'unverified'";
            }
            $sql .= " ORDER BY CASE WHEN verification_status = 'pending' THEN 0 WHEN verification_status = 'rejected' THEN 1 ELSE 2 END, id DESC";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $e) {
        error_log('UniStay admin verification query failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Updates a user's verification status by admin.
 */
function admin_set_verification_status(PDO $pdo, int $userId, string $status, ?string $notes = null, ?string $role = null): bool
{
    try {
        $validStatuses = ['unverified', 'pending', 'verified', 'rejected', 'approved', 'declined'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $targetRole = ($role && in_array($role, ['landlord', 'tenant', 'admin'], true)) ? $role : 'tenant';

        if ($targetRole === 'landlord') {
            $landlordStatus = ($status === 'verified' || $status === 'approved') ? 'approved' : (($status === 'rejected' || $status === 'declined') ? 'declined' : 'pending');
            $decidedAt = in_array($landlordStatus, ['approved', 'declined'], true) ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare("UPDATE landlords SET status = ?, feedback = ?, decided_at = ? WHERE landlord_id = ?");
            $success = $stmt->execute([$landlordStatus, $notes, $decidedAt, (string)$userId]);
            if ($success) {
                require_once __DIR__ . '/notifications.php';
                send_verification_decision_notification($pdo, (string)$userId, $landlordStatus === 'approved' ? 'verified' : 'rejected', $notes, 'landlord');
                if ($landlordStatus === 'approved') {
                    add_in_app_notification($pdo, (string)$userId, 'approval', "Account Verified! ✓", "Great news! Your Landlord identity and credentials have been approved by Admin. Your dormitory listings are now active and ready to accept bookings.", null, 'landlord');
                } elseif ($landlordStatus === 'declined') {
                    add_in_app_notification($pdo, (string)$userId, 'system', "Application Update", "Your Landlord application was not approved. " . (!empty($notes) ? "Admin notes: {$notes}" : "Please contact support for more details."), null, 'landlord');
                }
            }
            return $success;
        } else {
            $tenantStatus = ($status === 'approved' || $status === 'verified') ? 'verified' : (($status === 'declined' || $status === 'rejected') ? 'declined' : $status);
            $verifiedAt = ($tenantStatus === 'verified') ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare("UPDATE tenants SET verification_status = ?, feedback = ?, verified_at = ? WHERE tenant_id = ?");
            $success = $stmt->execute([$tenantStatus, $notes, $verifiedAt, (string)$userId]);
            if ($success && in_array($tenantStatus, ['verified', 'declined'], true)) {
                require_once __DIR__ . '/notifications.php';
                send_verification_decision_notification($pdo, (string)$userId, $tenantStatus === 'verified' ? 'verified' : 'rejected', $notes, 'tenant');
                if ($tenantStatus === 'verified') {
                    add_in_app_notification($pdo, (string)$userId, 'approval', "Account Verified! ✓", "Great news! Your Student Tenant credentials have been verified by Admin. You now have full access to submit room booking requests!", null, 'tenant');
                } elseif ($tenantStatus === 'declined') {
                    add_in_app_notification($pdo, (string)$userId, 'system', "Verification Update", "Your Student Tenant verification request was not approved. " . (!empty($notes) ? "Admin notes: {$notes}" : "Please check your settings to re-upload clear credentials."), null, 'tenant');
                }
            }
            return $success;
        }
    } catch (PDOException $e) {
        error_log('UniStay admin status update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Suspends a tenant or landlord account and records the reason.
 */
function admin_suspend_user(PDO $pdo, int|string $userId, string $role, string $reason = ''): array
{
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Account suspended pending administrative or OSAS review.';
    }

    try {
        if ($role === 'landlord') {
            $stmt = $pdo->prepare("UPDATE landlords SET status = 'suspended', suspension_reason = ?, suspended_at = NOW() WHERE landlord_id = ?");
            $stmt->execute([$reason, (string)$userId]);
            if ($stmt->rowCount() > 0) {
                if (file_exists(__DIR__ . '/notifications.php')) {
                    require_once __DIR__ . '/notifications.php';
                    add_in_app_notification($pdo, (string)$userId, 'system', 'Account Suspended ⚠️', "Your Landlord account has been temporarily suspended by OSAS. Reason: {$reason}", null, 'landlord');
                }
                return ['ok' => true, 'message' => "Landlord account #{$userId} has been suspended. Listings are now hidden from student search."];
            }
        } elseif ($role === 'tenant') {
            $stmt = $pdo->prepare("UPDATE tenants SET account_status = 'suspended', suspension_reason = ?, suspended_at = NOW() WHERE tenant_id = ?");
            $stmt->execute([$reason, (string)$userId]);
            if ($stmt->rowCount() > 0) {
                if (file_exists(__DIR__ . '/notifications.php')) {
                    require_once __DIR__ . '/notifications.php';
                    add_in_app_notification($pdo, (string)$userId, 'system', 'Account Suspended ⚠️', "Your Student Tenant account has been temporarily suspended by OSAS. Reason: {$reason}", null, 'tenant');
                }
                return ['ok' => true, 'message' => "Student Tenant account #{$userId} has been suspended."];
            }
        }
        return ['ok' => false, 'message' => "Account #{$userId} not found or already suspended."];
    } catch (PDOException $e) {
        error_log('UniStay admin suspend error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error during suspension: ' . $e->getMessage()];
    }
}

/**
 * Unsuspends / reactivates a tenant or landlord account.
 */
function admin_unsuspend_user(PDO $pdo, int|string $userId, string $role): array
{
    try {
        if ($role === 'landlord') {
            $stmt = $pdo->prepare("UPDATE landlords SET status = 'approved', suspension_reason = NULL, suspended_at = NULL WHERE landlord_id = ?");
            $stmt->execute([(string)$userId]);
            if ($stmt->rowCount() > 0) {
                if (file_exists(__DIR__ . '/notifications.php')) {
                    require_once __DIR__ . '/notifications.php';
                    add_in_app_notification($pdo, (string)$userId, 'approval', 'Account Reinstated! ✓', "Your Landlord account and accreditation have been reinstated by OSAS. Your listings are now active.", null, 'landlord');
                }
                return ['ok' => true, 'message' => "Landlord account #{$userId} has been reactivated. Listings are active again."];
            }
        } elseif ($role === 'tenant') {
            $stmt = $pdo->prepare("UPDATE tenants SET account_status = 'active', suspension_reason = NULL, suspended_at = NULL WHERE tenant_id = ?");
            $stmt->execute([(string)$userId]);
            if ($stmt->rowCount() > 0) {
                if (file_exists(__DIR__ . '/notifications.php')) {
                    require_once __DIR__ . '/notifications.php';
                    add_in_app_notification($pdo, (string)$userId, 'approval', 'Account Reinstated! ✓', "Your Student Tenant account has been reinstated by OSAS.", null, 'tenant');
                }
                return ['ok' => true, 'message' => "Student Tenant account #{$userId} has been reactivated."];
            }
        }
        return ['ok' => false, 'message' => "Account #{$userId} not found or already active."];
    } catch (PDOException $e) {
        error_log('UniStay admin unsuspend error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error during reactivation: ' . $e->getMessage()];
    }
}

/**
 * Synchronizes the users table AUTO_INCREMENT sequence to MAX(id) + 1.
 * Ensures new user registrations cleanly follow the next sequential ID.
 */
function sync_users_auto_increment(PDO $pdo, string $table = 'tenants'): void
{
    try {
        $allowed = ['tenants', 'landlords', 'admins', 'dorms', 'rooms', 'bookings', 'notifications', 'subscribers', 'reviews', 'landlord_applications'];
        if (!in_array($table, $allowed, true)) {
            $table = 'tenants';
        }
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
        $nextId = ($maxId > 0) ? ($maxId + 1) : 1;
        $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$nextId}");
    } catch (\Throwable $e) {
        error_log('UniStay sync_users_auto_increment error: ' . $e->getMessage());
    }
}

/**
 * Completely purges a user account and all instances, references, files, and activity of that account.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string|null $role
 * @return array ['ok' => bool, 'message' => string, 'email' => string, 'role' => string]
 */
function delete_user_account_completely(PDO $pdo, int|string $userId, ?string $role = null): array
{
    $userId = (string)$userId;
    if ($userId === '') {
        return ['ok' => false, 'message' => 'Invalid user ID.'];
    }

    try {
        $targetRole = strtolower(trim((string)($role ?: ($_SESSION['role'] ?? 'tenant'))));
        $table = ($targetRole === 'landlord') ? 'landlords' : (($targetRole === 'admin') ? 'admins' : 'tenants');
        $pk = account_pk($targetRole);

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['ok' => false, 'message' => 'Account not found.'];
        }

        $email = strtolower(trim((string)($user['email'] ?? ($user['personal_email'] ?? ''))));
        $filesToDelete = [];

        // Account files
        foreach (['profile_photo', 'photo', 'gov_id_file', 'gov_id', 'school_id_file', 'school_id'] as $fileCol) {
            $f = trim((string)($user[$fileCol] ?? ''));
            if ($f !== '' && !preg_match('#^https?://#i', $f)) {
                $filesToDelete[] = $f;
            }
        }

        $propIds = [];
        if ($targetRole === 'landlord') {
            $propStmt = $pdo->prepare("SELECT id, photo, proof_of_ownership, business_permit FROM dorms WHERE landlord_id = ?");
            $propStmt->execute([$userId]);
            $dorms = $propStmt->fetchAll(PDO::FETCH_ASSOC);
            $propIds = array_column($dorms, 'id');

            foreach ($dorms as $dorm) {
                $proof = trim((string)($dorm['proof_of_ownership'] ?? ''));
                if ($proof !== '' && !preg_match('#^https?://#i', $proof)) {
                    $filesToDelete[] = $proof;
                }
                $permit = trim((string)($dorm['business_permit'] ?? ''));
                if ($permit !== '' && !preg_match('#^https?://#i', $permit)) {
                    $filesToDelete[] = $permit;
                }
                $photos = json_decode((string)($dorm['photo'] ?? '[]'), true);
                if (is_array($photos)) {
                    foreach ($photos as $ph) {
                        if (is_string($ph) && $ph !== '' && !preg_match('#^https?://#i', $ph)) {
                            $filesToDelete[] = $ph;
                        }
                    }
                }
            }

            if (!empty($propIds)) {
                $inProps = implode(',', array_map('intval', $propIds));
                $roomPhotos = $pdo->query("SELECT photo FROM rooms WHERE dorm_id IN ($inProps)")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($roomPhotos as $rawPhotos) {
                    $rPhotos = json_decode((string)$rawPhotos, true);
                    if (is_array($rPhotos)) {
                        foreach ($rPhotos as $rph) {
                            if (is_string($rph) && $rph !== '' && !preg_match('#^https?://#i', $rph)) {
                                $filesToDelete[] = $rph;
                            }
                        }
                    }
                }
            }
        }

        // BEGIN TRANSACTION TO PURGE FROM DATABASE
        $pdo->beginTransaction();

        if (!empty($propIds)) {
            $inProps = implode(',', array_map('intval', $propIds));
            $pdo->exec("DELETE FROM dorms WHERE id IN ($inProps)");
        }

        if ($targetRole === 'tenant') {
            $pdo->prepare("DELETE FROM bookings WHERE tenant_id = ?")->execute([$userId]);
        }

        $pdo->prepare("DELETE FROM notifications WHERE role = ? AND user_id = ?")->execute([$targetRole, $userId]);
        $pdo->prepare("DELETE FROM notification_preferences WHERE role = ? AND user_id = ?")->execute([$targetRole, $userId]);
        $pdo->prepare("DELETE FROM password_resets WHERE role = ? AND user_id = ?")->execute([$targetRole, $userId]);

        if ($email !== '') {
            $pdo->prepare("DELETE FROM subscribers WHERE LOWER(email) = ?")->execute([$email]);
        }

        $pdo->prepare("DELETE FROM `{$table}` WHERE `{$pk}` = ?")->execute([$userId]);
        $pdo->commit();

        sync_users_auto_increment($pdo, $table);
        if ($targetRole === 'landlord') {
            sync_users_auto_increment($pdo, 'dorms');
            sync_users_auto_increment($pdo, 'rooms');
            sync_users_auto_increment($pdo, 'bookings');
        } elseif ($targetRole === 'tenant') {
            sync_users_auto_increment($pdo, 'bookings');
        }
        sync_users_auto_increment($pdo, 'notifications');

        $baseDir = dirname(__DIR__);
        foreach (array_unique($filesToDelete) as $relPath) {
            $cleanRel = ltrim(str_replace(['../', '..\\'], '', $relPath), '/\\');
            $absPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanRel);
            if (is_file($absPath)) {
                @unlink($absPath);
            }
        }

        return [
            'ok' => true,
            'message' => 'Account and all related activity and records have been permanently deleted from everywhere.',
            'email' => $email,
            'role' => $targetRole
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('delete_user_account_completely error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not delete account: ' . $e->getMessage()];
    }
}
