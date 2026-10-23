<?php
$scripts = [
    'scratch/test_brown_panel_and_carousel_direction.php',
    'scratch/test_add_prop_and_carousel.php',
    'scratch/test_cleaner_functionality.php',
    'scratch/test_notifications_empty_and_1km.php',
    'scratch/test_landlord_features.php',
    'scratch/test_layout_dividers_and_transitions.php',
    'scratch/test_login_rendering.php',
    'scratch/test_oauth_picker.php',
    'scratch/test_tenant_topbar.php'
];

$failed = false;
foreach ($scripts as $s) {
    echo "\n>>> Running {$s}...\n";
    passthru("php " . escapeshellarg($s), $code);
    if ($code !== 0) {
        echo "FAILED: {$s}\n";
        $failed = true;
    }
}

if ($failed) {
    echo "\n❌ Some test suites failed.\n";
    exit(1);
} else {
    echo "\n✅ ALL 9 TEST SUITES PASSED FLAWLESSLY!\n";
}
