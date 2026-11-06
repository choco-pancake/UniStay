<?php
require_once __DIR__ . '/settings.php';

$next = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM tenants')->fetchColumn();
$pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . $next);
