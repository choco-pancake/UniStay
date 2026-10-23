<?php
require_once __DIR__ . '/../config/database.php';
$pdo = database();
$pdo->exec('DELETE FROM rooms WHERE property_id = 105');
$pdo->exec('DELETE FROM properties WHERE id = 105');
echo "Deleted property 105 and its rooms successfully.\n";
$remaining = $pdo->query('SELECT id, name FROM properties')->fetchAll(PDO::FETCH_ASSOC);
print_r($remaining);

