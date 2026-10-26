<?php
// Test rendering of register.php in both Tenant and Landlord modes
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

// 1. Test Default / Tenant
$_GET = [];
$_POST = [];
ob_start();
include __DIR__ . '/../auth/register.php';
$htmlTenant = ob_get_clean();

$tChecks = [
    'Tab Indicator exists' => strpos($htmlTenant, 'class="tab-indicator"') !== false,
    'Role toggle has tabs class' => strpos($htmlTenant, 'class="role-toggle tabs"') !== false,
    'Tenant active pill' => strpos($htmlTenant, 'data-role="tenant" role="tab" aria-selected="true"') !== false,
    'Landlord inactive pill' => strpos($htmlTenant, 'data-role="landlord" role="tab" aria-selected="false"') !== false,
    'Tenant Landing SVG graduation cap' => strpos($htmlTenant, 'd="M22 10 12 5 2 10l10 5 10-5z"') !== false,
    'Landlord Landing SVG building' => strpos($htmlTenant, 'd="M3 21V10l6-4 6 4v11M15 21V13h6v8M2 21h20"') !== false,
    'Submit button text is Create account' => strpos($htmlTenant, '<button type="submit" class="btn-register" id="submitBtn">') !== false && strpos($htmlTenant, '<span>Create account</span>') !== false,
    'Left panel is Your new place starts here' => strpos($htmlTenant, 'Your new place') !== false,
];

echo "--- TENANT MODE CHECKS ---\n";
$allTPassed = true;
foreach ($tChecks as $label => $pass) {
    echo ($pass ? "PASS" : "FAIL") . ": $label\n";
    if (!$pass) $allTPassed = false;
}

// 2. Test Landlord (?type=landlord)
$_GET = ['type' => 'landlord'];
$_POST = [];
ob_start();
include __DIR__ . '/../auth/register.php';
$htmlLandlord = ob_get_clean();

$lChecks = [
    'Landlord active pill' => strpos($htmlLandlord, 'data-role="landlord" role="tab" aria-selected="true"') !== false,
    'Tenant inactive pill' => strpos($htmlLandlord, 'data-role="tenant" role="tab" aria-selected="false"') !== false,
    'Submit button text is STILL Create account' => strpos($htmlLandlord, '<span>Create account</span>') !== false,
    'Left panel is List your place with confidence' => strpos($htmlLandlord, 'List your place') !== false,
    'Terms mentions Community Guidelines' => strpos($htmlLandlord, 'Community Guidelines') !== false,
];

echo "\n--- LANDLORD MODE CHECKS ---\n";
$allLPassed = true;
foreach ($lChecks as $label => $pass) {
    echo ($pass ? "PASS" : "FAIL") . ": $label\n";
    if (!$pass) $allLPassed = false;
}

if ($allTPassed && $allLPassed) {
    echo "\nAll registration role switcher and button checks passed successfully!\n";
    exit(0);
} else {
    echo "\nSome checks failed!\n";
    exit(1);
}
