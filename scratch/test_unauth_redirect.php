<?php
// Test unauthenticated access to landlord/properties.php redirects to login
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

// Simulate clean session without auth
start_app_session();
unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['role']);

// Capture header calls
header_remove();
try {
    require_signed_in('landlord');
    echo "FAIL: Expected redirect or exit\n";
    exit(1);
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
