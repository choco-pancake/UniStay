<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = database();
    echo "Beginning database migration for rooms and dorms...\n";

    // 1. Update dorms table: add approved_by and feedback
    $dormsCols = $pdo->query("SHOW COLUMNS FROM dorms")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('approved_by', $dormsCols, true)) {
        echo "Adding 'approved_by' to dorms...\n";
        $pdo->exec("ALTER TABLE dorms ADD COLUMN approved_by INT NULL AFTER approval_status");
        try {
            $pdo->exec("ALTER TABLE dorms ADD CONSTRAINT fk_dorms_approved_by FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL");
        } catch (Throwable $e) {
            echo "Notice on foreign key constraint: " . $e->getMessage() . "\n";
        }
        $pdo->exec("ALTER TABLE dorms ADD INDEX idx_dorms_approved_by (approved_by)");
    } else {
        echo "'approved_by' already exists in dorms.\n";
    }

    if (!in_array('feedback', $dormsCols, true)) {
        echo "Adding 'feedback' to dorms...\n";
        $pdo->exec("ALTER TABLE dorms ADD COLUMN feedback TEXT NULL AFTER approved_by");
    } else {
        echo "'feedback' already exists in dorms.\n";
    }

    // 2. Update rooms table: add total_rent and rent_per_head, migrate from rent, drop rent
    $roomsCols = $pdo->query("SHOW COLUMNS FROM rooms")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('total_rent', $roomsCols, true)) {
        echo "Adding 'total_rent' to rooms...\n";
        $pdo->exec("ALTER TABLE rooms ADD COLUMN total_rent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER occupancy");
    }

    if (!in_array('rent_per_head', $roomsCols, true)) {
        echo "Adding 'rent_per_head' to rooms...\n";
        $pdo->exec("ALTER TABLE rooms ADD COLUMN rent_per_head DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_rent");
    }

    // If 'rent' column still exists, migrate values into total_rent and rent_per_head
    $freshRoomsCols = $pdo->query("SHOW COLUMNS FROM rooms")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('rent', $freshRoomsCols, true)) {
        echo "Migrating data from 'rent' to 'total_rent' and 'rent_per_head'...\n";
        // If occupancy = 1: total_rent = rent, rent_per_head = rent
        // If occupancy > 1: rent was per-head in our seed data/forms, so rent_per_head = rent, total_rent = rent * occupancy
        $pdo->exec("
            UPDATE rooms
            SET 
                rent_per_head = rent,
                total_rent = CASE 
                    WHEN occupancy <= 1 THEN rent 
                    ELSE ROUND(rent * occupancy, 2) 
                END
            WHERE total_rent = 0.00 OR rent_per_head = 0.00
        ");

        echo "Dropping legacy 'rent' column from rooms...\n";
        $pdo->exec("ALTER TABLE rooms DROP COLUMN rent");
    }

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

