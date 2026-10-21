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