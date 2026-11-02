<?php
require_once __DIR__ . '/settings.php';

$next = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM users')->fetchColumn();
$pdo->exec('ALTER TABLE users AUTO_INCREMENT = ' . $next);
