<?php
/**
 * Automated Verification Script for Add Property Page 2 Refinements and Modal Typography
 */

$cssPath = __DIR__ . '/../assets/css/landlord-properties.css';
$jsPath = __DIR__ . '/../assets/js/landlord-properties.js';
$phpPath = __DIR__ . '/../landlord/properties.php';

$css = file_get_contents($cssPath);
$js = file_get_contents($jsPath);
$php = file_get_contents($phpPath);

$tests = [];

// 1. Typography in CSS
$tests['CSS Modal Title (24px Fraunces)'] = strpos($css, 'font: 700 24px "Fraunces"') !== false;
$tests['CSS Labels (14px Bold)'] = strpos($css, 'font-size: 14px;') !== false && strpos($css, '.field-label') !== false;
$tests['CSS Inputs (44px Height)'] = strpos($css, 'height: 44px;') !== false && strpos($css, 'select.field-input') !== false;
$tests['CSS Textarea (14px font & 90px min-height)'] = strpos($css, 'min-height: 90px;') !== false;
$tests['CSS Buttons (44px Height & 14.5px font)'] = strpos($css, 'height: 44px;') !== false && strpos($css, 'font: 600 14.5px "DM Sans"') !== false;
$tests['CSS Photo Dropzone Icon (28px)'] = strpos($css, 'font-size: 28px;') !== false;
$tests['CSS Room Items Grid (.room-fields-grid)'] = strpos($css, '.room-fields-grid') !== false && strpos($css, 'repeat(4, 1fr)') !== false;
$tests['CSS Amenities Grid (.amenities-grid)'] = strpos($css, '.amenities-grid') !== false && strpos($css, '.amenity-checkbox-item') !== false;

// 2. JavaScript Logic
$tests['JS Removed priceGroup helper'] = strpos($js, 'priceGroup =') === false;
$tests['JS Removed Range/Fixed Mode Toggle Handler'] = strpos($js, 'd.querySelectorAll(\'[data-price]\')') === false;
$tests['JS Fixed Monthly Rent Input'] = strpos($js, '<input type="number" data-f="rent" min="0" step="1" required class="field-input"') !== false;
$tests['JS Fixed Deposit Input'] = strpos($js, '<input type="number" data-f="deposit" min="0" step="1" class="field-input"') !== false;
$tests['JS Uniform Room Photo Picker (attachRoomPhotoPicker)'] = strpos($js, 'function attachRoomPhotoPicker') !== false;
$tests['JS Room Photo Dropzone Uniform Class (.photo-drop-box)'] = strpos($js, 'data-zone class="photo-drop-box"') !== false;
$tests['JS Room Photo Preview Grid (.photo-preview-grid)'] = strpos($js, 'data-preview class="photo-preview-grid"') !== false;

// 3. Expanded Amenities List (12 items)
$amenitiesExpected = [
    'WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen',
    'Refrigerator', 'Water heater', 'CCTV & Security', 'Own electric submeter', 'Balcony', 'Bed with mattress'
];
$allAmenitiesFound = true;
foreach ($amenitiesExpected as $amenity) {
    if (strpos($js, "'" . $amenity . "'") === false) {
        $allAmenitiesFound = false;
        break;
    }
}
$tests['JS All 12 Amenities Included'] = $allAmenitiesFound;

// 4. Submit handler simplification
$tests['JS Direct Numeric Rent Extraction'] = strpos($js, 'const rentVal = +(g(\'rent\') || 0);') !== false;
$tests['JS Direct Numeric Deposit Extraction'] = strpos($js, 'const depVal = +(g(\'deposit\') || 0);') !== false;

// 5. Option 1 Bottom Dashed Add Room Button & Header Removal
$tests['PHP Removed Button from Header'] = strpos($php, '<div class="rooms-section-header">' . "\n" . '            <div>' . "\n" . '              <h3>Rooms &amp; Inventory</h3>') !== false;
$tests['PHP Bottom Dashed Add Room Button Exists'] = strpos($php, 'class="add-room-dashed-btn"') !== false;
$tests['CSS Dashed Add Room Styling (.add-room-dashed-btn)'] = strpos($css, '.add-room-dashed-btn') !== false && strpos($css, 'border: 2px dashed') !== false;
$tests['JS Dynamic Room Numbering (updateRoomHeaders)'] = strpos($js, 'function updateRoomHeaders()') !== false && strpos($js, 'Room ${idx + 1} details') !== false;

// 6. Cache buster versions in properties.php
$tests['PHP Cache buster CSS v=4'] = strpos($php, 'landlord-properties.css?v=4') !== false;
$tests['PHP Cache buster JS v=5'] = strpos($php, 'landlord-properties.js?v=5') !== false;

echo "=== Page 2 & Modal Typography Test Results ===\n";
$allPassed = true;
foreach ($tests as $name => $passed) {
    echo ($passed ? "[PASS]" : "[FAIL]") . " " . $name . "\n";
    if (!$passed) $allPassed = false;
}

if ($allPassed) {
    echo "\nALL " . count($tests) . " TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "\nSOME TESTS FAILED!\n";
    exit(1);
}
