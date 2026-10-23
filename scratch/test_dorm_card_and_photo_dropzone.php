<?php
/**
 * Automated Verification Script for In-Box Photo Previews, Streamlined Gallery Cards,
 * and Floating 2-Column Dorm Inspection Modal with Carousel.
 */

$cssPath = __DIR__ . '/../assets/css/landlord-properties.css';
$jsPath = __DIR__ . '/../assets/js/landlord-properties.js';
$phpPath = __DIR__ . '/../landlord/properties.php';

$css = file_get_contents($cssPath);
$js = file_get_contents($jsPath);
$php = file_get_contents($phpPath);

$tests = [];

// 1. In-box photo upload previews
$tests['PHP Page 1 Previews Nested Inside #photoZone'] = strpos($php, '<div id="photoZone" class="photo-drop-box">' . "\n" . '                <div id="photoPromptWrap"') !== false
    && strpos($php, '<div id="photoPreview" class="photo-preview-grid"') !== false;

$tests['JS Page 1 Photo Previews Inside photoZone'] = strpos($js, 'const prompt = $(\'#photoPromptWrap\');') !== false
    && strpos($js, 'photoAddSlot') !== false;

$tests['JS Page 2 Room Previews Nested Inside data-zone'] = strpos($js, 'data-prompt class="photo-prompt-wrap"') !== false
    && strpos($js, 'data-add-slot') !== false;

$tests['CSS In-box Previews & Add Slot Styled'] = strpos($css, '.photo-prompt-wrap') !== false
    && strpos($css, '.photo-add-slot') !== false;

// 2. Streamlined Gallery Cards
$tests['JS Gallery Card Removed Landlord Avatar & Name'] = strpos($js, 'dorm-card-landlord') === false;
$tests['JS Gallery Card Removed View Details & Delete Buttons'] = strpos($js, 'dorm-view-details-btn') === false
    && strpos($js, 'dorm-delete-btn') === false;
$tests['JS Gallery Card Clean Price Summary'] = strpos($js, 'Starting rent:') !== false;
$tests['JS Gallery Card Click Opens Inspection Modal'] = strpos($js, 'grid.querySelectorAll(\'.dorm-card\').forEach(card => {') !== false
    && strpos($js, 'openInspectionModal(p);') !== false;

// 3. Floating 2-Column Dorm Inspection Modal (#dorm-modal)
$tests['CSS Modal Full Screen Floating Aesthetic'] = strpos($css, 'dialog.dorm-modal') !== false
    && strpos($css, 'width: min(calc(100vw - 48px), 1360px)') !== false
    && strpos($css, 'height: min(calc(100vh - 48px), 880px)') !== false
    && strpos($css, 'border-radius: 24px') !== false;

$tests['CSS Non-scrollable Modal Shell with 2-Column Grid'] = strpos($css, '.dorm-modal-body') !== false
    && strpos($css, 'grid-template-columns: 1.15fr 1fr') !== false
    && strpos($css, '.dorm-inspect-col-left') !== false
    && strpos($css, '.dorm-inspect-col-right') !== false;

$tests['CSS Photo Carousel Matching tenant/map.php'] = strpos($css, '.um-carousel') !== false
    && strpos($css, '.um-carousel-track') !== false
    && strpos($css, '.um-slide.is-active') !== false
    && strpos($css, '.um-slide.is-prev') !== false
    && strpos($css, '.um-slide.is-next') !== false
    && strpos($css, '.um-carousel-btn') !== false
    && strpos($css, '.um-carousel-count') !== false;

$tests['PHP Inspection Modal Header Has Delete Button'] = strpos($php, 'id="dormModalDeleteBtn"') !== false;

$tests['JS Inspection Modal Left Column Contains Carousel, Info & Map'] = strpos($js, 'class="dorm-inspect-col-left"') !== false
    && strpos($js, 'carouselHtml') !== false
    && strpos($js, 'inspectionModalMap') !== false;

$tests['JS Inspection Modal Right Column Contains Available Rooms'] = strpos($js, 'class="dorm-inspect-col-right"') !== false
    && strpos($js, 'Available Rooms') !== false;

$tests['JS Inspection Modal Has Photo Carousel Sliding Logic'] = strpos($js, 'slides.forEach((slide, idx) => {') !== false
    && strpos($js, 'slide.classList.toggle(\'is-active\', idx === i);') !== false
    && strpos($js, 'slide.classList.toggle(\'is-next\', idx === (i + 1) % n);') !== false;

$tests['CSS Modal Hidden By Default (:not([open]))'] = strpos($css, 'dialog.dorm-modal:not([open])') !== false
    && strpos($css, 'display: none !important') !== false;

// 4. Cache buster versions
$tests['PHP Cache buster CSS v=6'] = strpos($php, 'landlord-properties.css?v=6') !== false;
$tests['PHP Cache buster JS v=6'] = strpos($php, 'landlord-properties.js?v=6') !== false;

echo "=== Dorm Card & Photo Dropzone Test Results ===\n";
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
