<?php
require_once __DIR__ . '/../config/database.php';
$pdo = database();

echo "Starting Migration v2 Adjustments...\n";

// 1. Drop the 4 unwanted email tables
echo "Dropping email queue & campaign tables...\n";
$pdo->exec("DROP TABLE IF EXISTS subscribe_attempts, email_logs, email_queue, campaigns;");
echo "Dropped subscribe_attempts, email_logs, email_queue, campaigns.\n";

// 2. Change dorms.approved_by to VARCHAR(150)
echo "Modifying dorms.approved_by to VARCHAR(150)...\n";
// Check if foreign key exists on dorms.approved_by
$fks = $pdo->query("
    SELECT CONSTRAINT_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'dorms' 
      AND COLUMN_NAME = 'approved_by'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($fks as $fk) {
    echo "Dropping foreign key $fk on dorms...\n";
    $pdo->exec("ALTER TABLE dorms DROP FOREIGN KEY `$fk`");
}

$pdo->exec("ALTER TABLE dorms MODIFY COLUMN approved_by VARCHAR(150) NULL DEFAULT NULL");
echo "dorms.approved_by modified to VARCHAR(150) successfully.\n";

// 3. Resequence tenants starting from 1
echo "Resequencing tenants table starting from 1...\n";
$pdo->beginTransaction();

// Disable foreign key checks during re-indexing
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

$tenants = $pdo->query("SELECT id FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$newId = 1;
foreach ($tenants as $oldId) {
    if ((int)$oldId !== $newId) {
        echo "Mapping tenant id $oldId -> $newId\n";
        // Update referencing tables
        $pdo->prepare("UPDATE bookings SET tenant_id = ? WHERE tenant_id = ?")->execute([$newId, $oldId]);
        $pdo->prepare("UPDATE notifications SET user_id = ? WHERE user_id = ? AND role = 'tenant'")->execute([$newId, $oldId]);
        $pdo->prepare("UPDATE notification_preferences SET user_id = ? WHERE user_id = ? AND role = 'tenant'")->execute([$newId, $oldId]);
        // Update tenant row
        $pdo->prepare("UPDATE tenants SET id = ? WHERE id = ?")->execute([$newId, $oldId]);
    }
    $newId++;
}

// Reset AUTO_INCREMENT on all tables to 1 (InnoDB resets to MAX(id)+1)
$tablesToReset = ['tenants', 'landlords', 'dorms', 'rooms', 'bookings', 'notifications', 'subscribers', 'admins'];
foreach ($tablesToReset as $tbl) {
    $pdo->exec("ALTER TABLE `$tbl` AUTO_INCREMENT = 1");
    echo "Reset AUTO_INCREMENT on $tbl.\n";
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
$pdo->commit();

echo "Migration v2 Adjustments Completed Successfully!\n";

