<?php

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

function current_tenant_id(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (is_numeric($userId)) {
        return (int) $userId;
    }

    header('Location: ../auth/login.php', true, 303);
    exit;
}

function tenant_profile_complete(PDO $pdo, int $userId): bool
{
    try {
        $statement = $pdo->prepare('SELECT profile_completed FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $account = $statement->fetch();
        return $account !== false && (int) $account['profile_completed'] === 1;
    } catch (PDOException $error) {
        error_log('UniStay profile check failed: ' . $error->getMessage());
        return false;
    }
}

function landlord_profile_complete(PDO $pdo, int $userId): bool
{
    try {
        $statement = $pdo->prepare('SELECT first_name, last_name, phone_number, facebook_link, personal_email, gov_id_file FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $account = $statement->fetch();
        return $account !== false && !empty($account['first_name']) && !empty($account['last_name'])
            && !empty($account['phone_number']) && !empty($account['facebook_link'])
            && !empty($account['personal_email']) && !empty($account['gov_id_file']);
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
function next_user_id(PDO $pdo): int
{
    return next_table_id($pdo, 'users', 1);
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
 * Returns the account of an email address, or null when there is none.
 */
function find_user_by_email(PDO $pdo, string $email): ?array
{
    if ($email === '') {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, first_name, middle_name, last_name, personal_email, role, photo, profile_photo
             FROM users WHERE personal_email = ? LIMIT 1'
        );
        $statement->execute([$email]);
        $account = $statement->fetch();

        return $account === false ? null : $account;
    } catch (PDOException $error) {
        error_log('UniStay account lookup failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * Returns the account of an email address that exists in the database but was
 * never registered through the sign-up form.
 *
 * Those rows were created by an older Google sign-in: they hold a random
 * password the visitor never sees, no phone number and no Facebook link, so
 * the account still has to be completed on the sign-up form.
 *
 * Returns null when the email does not belong to such an account.
 */
function find_unregistered_account(PDO $pdo, string $email): ?array
{
    if ($email === '') {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, role, photo FROM users
             WHERE personal_email = ?
               AND phone_number IS NULL
               AND facebook_link IS NULL
               AND profile_completed = 0
             LIMIT 1'
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
    if (preg_match('#^(?:https?:|data:)#i', $raw) || $raw[0] === '/') return $raw;

    $raw = str_replace('\\', '/', $raw);
    if (str_starts_with($raw, '../tenant/') || str_starts_with($raw, '../uploads/')) return $raw;

    $path = ltrim($raw, './');
    if (str_starts_with($path, 'tenant/')) return '../' . $path;
    if (str_starts_with($path, 'uploads/')) {
        return strtolower($role) === 'tenant' ? '../tenant/' . $path : '../' . $path;
    }
    return '../' . $path;
}

/**
 * Returns normalized profile metadata for the authenticated user (name, initials, photo, role, school).
 */
function get_authenticated_profile(PDO $pdo, int $userId): array
{
    try {
        $st = $pdo->prepare("SELECT id, first_name, last_name, personal_email, photo, profile_photo, role, school FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch() ?: [];
    } catch (PDOException $e) {
        $row = [];
    }

    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $name = trim("$first $last");
    $email = (string)($row['personal_email'] ?? '');

    if ($name === '') {
        $name = $email !== '' ? $email : 'User';
        $initials = strtoupper(substr($name, 0, 1));
    } else {
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    $photo = resolve_user_profile_photo_url(
        $row['profile_photo'] ?? null,
        $row['photo'] ?? null,
        (string)($row['role'] ?? '')
    );
    if ($photo === '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $photo = 'https://unavatar.io/' . rawurlencode(strtolower(trim($email))) . '?fallback=false';
    }
    $role = ucfirst((string)($row['role'] ?? 'tenant'));
    $school = (string)($row['school'] ?? '');

    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && $photo !== '') {
        $_SESSION['user']['photo'] = $photo;
    }

    return [
        'id' => $userId,
        'name' => $name,
        'role' => $role,
        'photo' => $photo,
        'initials' => $initials,
        'email' => $email,
        'school' => $school
    ];
}

/**
 * Returns verification details for a landlord.
 */
function get_landlord_verification(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT verification_status, verification_notes, verified_at, gov_id_file, proof_of_ownership_file, profile_photo FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'status' => $row['verification_status'] ?? 'unverified',
                'notes' => $row['verification_notes'] ?? '',
                'verified_at' => $row['verified_at'] ?? null,
                'gov_id_file' => $row['gov_id_file'] ?? '',
                'proof_of_ownership_file' => $row['proof_of_ownership_file'] ?? '',
                'profile_photo' => $row['profile_photo'] ?? ''
            ];
        }
    } catch (PDOException $e) {
        error_log('UniStay verification lookup failed: ' . $e->getMessage());
    }
    return [
        'status' => 'unverified',
        'notes' => '',
        'verified_at' => null,
        'gov_id_file' => '',
        'proof_of_ownership_file' => '',
        'profile_photo' => ''
    ];
}

/**
 * Returns verification details for a tenant.
 */
function get_tenant_verification(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT verification_status, verification_notes, verified_at, school_id_file, gov_id_file, profile_photo, profile_completed FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'status' => $row['verification_status'] ?? 'unverified',
                'notes' => $row['verification_notes'] ?? '',
                'verified_at' => $row['verified_at'] ?? null,
                'school_id_file' => $row['school_id_file'] ?? '',
                'gov_id_file' => $row['gov_id_file'] ?? '',
                'profile_photo' => $row['profile_photo'] ?? '',
                'profile_completed' => (int)($row['profile_completed'] ?? 0)
            ];
        }
    } catch (PDOException $e) {
        error_log('UniStay tenant verification lookup failed: ' . $e->getMessage());
    }
    return [
        'status' => 'unverified',
        'notes' => '',
        'verified_at' => null,
        'school_id_file' => '',
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
        $sql = "SELECT id, first_name, middle_name, last_name, personal_email, phone_number, facebook_link,
                       school, emergency_name, emergency_relationship, emergency_phone,
                       profile_photo, photo, school_id_file, gov_id_file,
                       verification_status, verification_notes, verified_at, created_at
                FROM users
                WHERE role = ?";

        $params = [$targetRole];
        if ($filter === 'pending') {
            if ($targetRole === 'landlord') {
                $sql .= " AND (verification_status = 'pending' OR (verification_status = 'unverified' AND gov_id_file IS NOT NULL AND gov_id_file != ''))";
            } else {
                $sql .= " AND (verification_status = 'pending' OR (verification_status = 'unverified' AND ((school_id_file IS NOT NULL AND school_id_file != '') OR (gov_id_file IS NOT NULL AND gov_id_file != ''))))";
            }
        } elseif ($filter === 'verified') {
            $sql .= " AND verification_status = 'verified'";
        } elseif ($filter === 'rejected') {
            $sql .= " AND verification_status = 'rejected'";
        } elseif ($filter === 'unverified') {
            $sql .= " AND verification_status = 'unverified'";
        }
        $sql .= " ORDER BY CASE WHEN verification_status = 'pending' THEN 0 WHEN verification_status = 'rejected' THEN 1 ELSE 2 END, id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $validStatuses = ['unverified', 'pending', 'verified', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }
        $verifiedAt = ($status === 'verified') ? date('Y-m-d H:i:s') : null;
        $sql = 'UPDATE users SET verification_status = ?, verification_notes = ?, verified_at = ? WHERE id = ?';
        $params = [$status, $notes, $verifiedAt, $userId];
        if ($role && in_array($role, ['landlord', 'tenant'], true)) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute($params);
        if ($success && in_array($status, ['verified', 'rejected'], true)) {
            require_once __DIR__ . '/notifications.php';
            send_verification_decision_notification($pdo, $userId, $status, $notes);

            $userRole = $role;
            if (!$userRole) {
                try {
                    $uStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                    $uStmt->execute([$userId]);
                    $userRole = (string)$uStmt->fetchColumn();
                } catch (\Throwable $e) {
                    $userRole = 'tenant';
                }
            }

            if ($status === 'verified') {
                $roleTitle = ($userRole === 'landlord') ? 'Landlord' : 'Student Tenant';
                $title = "Account Verified! ✓";
                $msg = ($userRole === 'landlord')
                    ? "Great news! Your Landlord identity and property credentials have been verified by Admin. Your dormitory listings are now active and ready to accept bookings."
                    : "Great news! Your Student Tenant credentials have been verified by Admin. You now have full access to submit room booking requests!";
                add_in_app_notification($pdo, $userId, 'approval', $title, $msg);
            } elseif ($status === 'rejected') {
                $roleTitle = ($userRole === 'landlord') ? 'Landlord' : 'Student Tenant';
                $title = "Verification Update";
                $msg = "Your {$roleTitle} verification request was not approved. " . (!empty($notes) ? "Admin notes: {$notes}" : "Please check your settings to re-upload clear credentials.");
                add_in_app_notification($pdo, $userId, 'system', $title, $msg);
            }
        }
        return $success;
    } catch (PDOException $e) {
        error_log('UniStay admin status update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Synchronizes the users table AUTO_INCREMENT sequence to MAX(id) + 1.
 * Ensures new user registrations cleanly follow the next sequential ID.
 */
function sync_users_auto_increment(PDO $pdo): void
{
    try {
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM users")->fetchColumn();
        $nextId = max(1, $maxId + 1);
        $pdo->exec("ALTER TABLE users AUTO_INCREMENT = {$nextId}");
    } catch (\Throwable $e) {
        error_log('UniStay sync_users_auto_increment error: ' . $e->getMessage());
    }
}
