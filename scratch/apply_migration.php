<?php
require_once __DIR__ . '/../config/database.php';
$pdo = database();

// Check if approval_status column exists
$cols = $pdo->query("SHOW COLUMNS FROM properties LIKE 'approval_status'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE properties ADD COLUMN approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved'");
    echo "Column approval_status added successfully.\n";
} else {
    echo "Column approval_status already exists.\n";
}

// Update existing properties with varied statuses for demonstration
$pdo->exec("UPDATE properties SET approval_status = 'approved' WHERE id IN (101, 102)");
$pdo->exec("UPDATE properties SET approval_status = 'pending' WHERE id = 103");
$pdo->exec("UPDATE properties SET approval_status = 'rejected' WHERE id = 104");
$pdo->exec("UPDATE properties SET approval_status = 'pending' WHERE id = 105");

// Ensure sample tenants have realistic names and schools
$pdo->exec("UPDATE users SET first_name = 'Bea', last_name = 'Alvarez', school = 'University of Pangasinan' WHERE id = 11");
$pdo->exec("UPDATE users SET first_name = 'Noerly', last_name = 'Yvonne Idos', school = 'Universidad de Dagupan' WHERE id = 12");

// Add two more sample student tenants if they don't exist
$st1 = $pdo->query("SELECT id FROM users WHERE personal_email = 'mark.tan@example.com'")->fetchColumn();
if (!$st1) {
    $pdo->prepare("INSERT INTO users (first_name, last_name, personal_email, school, role, profile_completed) VALUES (?, ?, ?, ?, 'tenant', 1)")
        ->execute(['Mark', 'Tan', 'mark.tan@example.com', 'Lyceum Northwestern University']);
    $st1 = $pdo->lastInsertId();
}
$st2 = $pdo->query("SELECT id FROM users WHERE personal_email = 'claire.reyes@example.com'")->fetchColumn();
if (!$st2) {
    $pdo->prepare("INSERT INTO users (first_name, last_name, personal_email, school, role, profile_completed) VALUES (?, ?, ?, ?, 'tenant', 1)")
        ->execute(['Claire', 'Reyes', 'claire.reyes@example.com', 'Universidad de Dagupan']);
    $st2 = $pdo->lastInsertId();
}

// Link bookings to specific rooms so occupants show up for each room
// Room 22 (Property 101, Room 102 - Twin) -> user 11 (Bea Alvarez)
$pdo->exec("UPDATE bookings SET room_id = 22 WHERE id = 1");
// Room 23 (Property 101, Room 103 - Quad) -> user 12 (Noerly Yvonne)
$pdo->exec("UPDATE bookings SET room_id = 23 WHERE id = 4");

// Add a booking for Claire in Room 23
$bExists = $pdo->query("SELECT id FROM bookings WHERE user_id = $st2 AND room_id = 23")->fetchColumn();
if (!$bExists) {
    $pdo->prepare("INSERT INTO bookings (user_id, property_id, room_id, status) VALUES (?, 101, 23, 'active')")
        ->execute([$st2]);
}

// Add a booking for Mark in Room 26 (Property 102, Room 203)
$bExists2 = $pdo->query("SELECT id FROM bookings WHERE user_id = $st1 AND room_id = 26")->fetchColumn();
if (!$bExists2) {
    $pdo->prepare("INSERT INTO bookings (user_id, property_id, room_id, status) VALUES (?, 102, 26, 'active')")
        ->execute([$st1]);
}

echo "Migration & sample tenant occupants configured successfully!\n";
