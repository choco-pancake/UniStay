<?php
/**
 * Migration v3: Remove numeric `id` and `code` columns from admins, landlords,
 * and tenants tables, promoting admin_id, landlord_id, and tenant_id
 * (A1, A2..., L1, L2..., T1, T2...) to be the sole primary keys.
 *
 * Foreign keys (bookings.tenant_id, dorms.landlord_id) and user_id columns
 * (notifications, notification_preferences, password_resets) are converted
 * to the prefixed string format.
 *
 * Usage:
 *   php database/migrate_v3_prefixed_ids.php          # dry run / inspection
 *   php database/migrate_v3_prefixed_ids.php --yes    # execute migration
 */
require_once __DIR__ . '/../config/database.php';

$pdo = database();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$apply = in_array('--yes', $argv ?? [], true);

$COLLATE = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

function get_columns(PDO $pdo, string $table): array
{
    return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
}

function fk_exists(PDO $pdo, string $table, string $fkName): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $s->execute([$table, $fkName]);
    return (int)$s->fetchColumn() > 0;
}

echo "=== UniStay Migration: Prefixed Account IDs ===\n";

$adminCols    = get_columns($pdo, 'admins');
$landlordCols = get_columns($pdo, 'landlords');
$tenantCols   = get_columns($pdo, 'tenants');

$needsMigration = in_array('id', $adminCols, true) || in_array('code', $adminCols, true)
               || in_array('id', $landlordCols, true) || in_array('code', $landlordCols, true)
               || in_array('id', $tenantCols, true) || in_array('code', $tenantCols, true);

if (!$needsMigration) {
    echo "All account tables (admins, landlords, tenants) are already migrated.\n";
    echo "Columns 'id' and 'code' do not exist. Primary keys are admin_id, landlord_id, tenant_id.\n";
    exit(0);
}

echo "Account tables currently contain 'id' / 'code' columns and require migration.\n";

if (!$apply) {
    echo "\nDry run complete. Run with --yes to execute migration.\n";
    exit(0);
}

echo "\nApplying migration...\n";

// Disable foreign key checks during schema alteration
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

try {
    // 1. Drop existing FKs if present
    if (fk_exists($pdo, 'bookings', 'fk_bookings_tenant')) {
        $pdo->exec("ALTER TABLE `bookings` DROP FOREIGN KEY `fk_bookings_tenant`");
        echo "- Dropped FK fk_bookings_tenant\n";
    }
    if (fk_exists($pdo, 'dorms', 'fk_dorms_landlord')) {
        $pdo->exec("ALTER TABLE `dorms` DROP FOREIGN KEY `fk_dorms_landlord`");
        echo "- Dropped FK fk_dorms_landlord\n";
    }

    // 2. Admins table
    echo "- Migrating admins...\n";
    $cols = get_columns($pdo, 'admins');
    if (!in_array('admin_id', $cols, true)) {
        $pdo->exec("ALTER TABLE `admins` ADD COLUMN `admin_id` VARCHAR(20) $COLLATE NULL FIRST");
    } else {
        $pdo->exec("ALTER TABLE `admins` MODIFY COLUMN `admin_id` VARCHAR(20) $COLLATE NOT NULL");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("UPDATE `admins` SET `admin_id` = `code` WHERE `code` IS NOT NULL AND `code` != ''");
    }
    if (in_array('id', $cols, true)) {
        $pdo->exec("UPDATE `admins` SET `admin_id` = CONCAT('A', `id`) WHERE `admin_id` IS NULL OR `admin_id` = '' OR `admin_id` REGEXP '^[0-9]+$'");
    }
    $pdo->exec("ALTER TABLE `admins` DROP PRIMARY KEY, ADD PRIMARY KEY (`admin_id`)");
    if (in_array('id', $cols, true)) {
        $pdo->exec("ALTER TABLE `admins` DROP COLUMN `id`");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("ALTER TABLE `admins` DROP COLUMN `code`");
    }
    echo "  admins migrated: primary key is now admin_id (VARCHAR).\n";

    // 3. Landlords table
    echo "- Migrating landlords...\n";
    $cols = get_columns($pdo, 'landlords');
    if (!in_array('landlord_id', $cols, true)) {
        $pdo->exec("ALTER TABLE `landlords` ADD COLUMN `landlord_id` VARCHAR(20) $COLLATE NULL FIRST");
    } else {
        $pdo->exec("ALTER TABLE `landlords` MODIFY COLUMN `landlord_id` VARCHAR(20) $COLLATE NOT NULL");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("UPDATE `landlords` SET `landlord_id` = `code` WHERE `code` IS NOT NULL AND `code` != ''");
    }
    if (in_array('id', $cols, true)) {
        $pdo->exec("UPDATE `landlords` SET `landlord_id` = CONCAT('L', `id`) WHERE `landlord_id` IS NULL OR `landlord_id` = '' OR `landlord_id` REGEXP '^[0-9]+$'");
    }
    $pdo->exec("ALTER TABLE `landlords` DROP PRIMARY KEY, ADD PRIMARY KEY (`landlord_id`)");
    if (in_array('id', $cols, true)) {
        $pdo->exec("ALTER TABLE `landlords` DROP COLUMN `id`");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("ALTER TABLE `landlords` DROP COLUMN `code`");
    }
    echo "  landlords migrated: primary key is now landlord_id (VARCHAR).\n";

    // 4. Tenants table
    echo "- Migrating tenants...\n";
    $cols = get_columns($pdo, 'tenants');
    if (!in_array('tenant_id', $cols, true)) {
        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `tenant_id` VARCHAR(20) $COLLATE NULL FIRST");
    } else {
        $pdo->exec("ALTER TABLE `tenants` MODIFY COLUMN `tenant_id` VARCHAR(20) $COLLATE NOT NULL");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("UPDATE `tenants` SET `tenant_id` = `code` WHERE `code` IS NOT NULL AND `code` != ''");
    }
    if (in_array('id', $cols, true)) {
        $pdo->exec("UPDATE `tenants` SET `tenant_id` = CONCAT('T', `id`) WHERE `tenant_id` IS NULL OR `tenant_id` = '' OR `tenant_id` REGEXP '^[0-9]+$'");
    }
    $pdo->exec("ALTER TABLE `tenants` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`)");
    if (in_array('id', $cols, true)) {
        $pdo->exec("ALTER TABLE `tenants` DROP COLUMN `id`");
    }
    if (in_array('code', $cols, true)) {
        $pdo->exec("ALTER TABLE `tenants` DROP COLUMN `code`");
    }
    echo "  tenants migrated: primary key is now tenant_id (VARCHAR).\n";

    // 5. Update dorms.landlord_id
    echo "- Updating dorms.landlord_id references...\n";
    $pdo->exec("ALTER TABLE `dorms` MODIFY COLUMN `landlord_id` VARCHAR(20) $COLLATE NOT NULL");
    $pdo->exec("UPDATE `dorms` SET `landlord_id` = CONCAT('L', `landlord_id`) WHERE `landlord_id` REGEXP '^[0-9]+$'");
    $pdo->exec("ALTER TABLE `dorms` ADD CONSTRAINT `fk_dorms_landlord` FOREIGN KEY (`landlord_id`) REFERENCES `landlords`(`landlord_id`) ON DELETE CASCADE");

    // 6. Update bookings.tenant_id
    echo "- Updating bookings.tenant_id references...\n";
    $pdo->exec("ALTER TABLE `bookings` MODIFY COLUMN `tenant_id` VARCHAR(20) $COLLATE NOT NULL");
    $pdo->exec("UPDATE `bookings` SET `tenant_id` = CONCAT('T', `tenant_id`) WHERE `tenant_id` REGEXP '^[0-9]+$'");
    $pdo->exec("ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`tenant_id`) ON DELETE CASCADE");

    // 7. Update polymorphic user_id tables
    echo "- Updating user_id in notifications, notification_preferences, and password_resets...\n";
    foreach (['notifications', 'notification_preferences', 'password_resets'] as $tbl) {
        $cols = get_columns($pdo, $tbl);
        if (in_array('user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN `user_id` VARCHAR(20) $COLLATE NOT NULL");
            $pdo->exec("UPDATE `$tbl` SET `user_id` = CONCAT(CASE `role` WHEN 'admin' THEN 'A' WHEN 'landlord' THEN 'L' ELSE 'T' END, `user_id`) WHERE `user_id` REGEXP '^[0-9]+$'");
            echo "  $tbl.user_id updated.\n";
        }
    }

    // 8. ID counters table & values
    echo "- Updating id_counters...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS id_counters (
          name VARCHAR(30) NOT NULL PRIMARY KEY,
          last_value INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $maxA = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(admin_id, 2) AS UNSIGNED)), 0) FROM admins")->fetchColumn();
    $maxL = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(landlord_id, 2) AS UNSIGNED)), 0) FROM landlords")->fetchColumn();
    $maxT = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(tenant_id, 2) AS UNSIGNED)), 0) FROM tenants")->fetchColumn();

    $stmtCounter = $pdo->prepare("INSERT INTO id_counters (name, last_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value))");
    $stmtCounter->execute(['admins', $maxA]);
    $stmtCounter->execute(['landlords', $maxL]);
    $stmtCounter->execute(['tenants', $maxT]);

    echo "  id_counters: admins=$maxA, landlords=$maxL, tenants=$maxT\n";

} finally {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

echo "\nMigration v3 executed successfully!\n";
