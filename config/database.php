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
        $statement = $pdo->prepare('SELECT first_name, phone_number FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $account = $statement->fetch();
        return $account !== false && !empty($account['phone_number']) && !empty($account['first_name']);
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
    try {
        $statement = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM users');
        return (int) $statement->fetchColumn() + 1;
    } catch (PDOException $error) {
        error_log('UniStay next user id lookup failed: ' . $error->getMessage());
        return 1;
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

    $photo = '';
    if (!empty($row['photo'])) {
        $photo = (string)$row['photo'];
    } elseif (!empty($row['profile_photo'])) {
        $p = (string)$row['profile_photo'];
        $photo = (strpos($p, 'http') === 0 || strpos($p, '/') === 0) ? $p : '../tenant/' . ltrim($p, '/');
    } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
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