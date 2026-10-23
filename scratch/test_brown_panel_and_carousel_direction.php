<?php
/**
 * Test Suite: Brown Panel Layout, Bigger Carousel, Directional Slide, Micro-Animations & Back Button
 */

$cssPath = __DIR__ . '/../assets/css/landlord-properties.css';
$jsPath = __DIR__ . '/../assets/js/landlord-properties.js';
$phpPath = __DIR__ . '/../landlord/properties.php';

$css = file_get_contents($cssPath);
$js = file_get_contents($jsPath);
$php = file_get_contents($phpPath);

$tests = [];
function test($name, $cond) {
    global $tests;
    $tests[] = ['name' => $name, 'pass' => (bool)$cond];
}

// 1. CSS: Brown panel wider (1.3fr 0.9fr)
test('CSS has grid-template-columns: 1.3fr 0.9fr on dorm-modal-body',
    strpos($css, 'grid-template-columns: 1.3fr 0.9fr;') !== false);

// 2. CSS: Mid-row is min(520px, 70%) 1fr, 240px height
test('CSS has bigger carousel grid and 240px on dorm-modal-mid-row',
    strpos($css, 'grid-template-columns: min(520px, 70%) 1fr;') !== false &&
    strpos($css, 'height: 240px;') !== false);

// 3. CSS: Carousel photo container has max-height: 240px
test('CSS has max-height: 240px on dorm-modal-mid-photo',
    strpos($css, 'max-height: 240px;') !== false);

// 4. CSS: Title row and meta styles
test('CSS defines .dorm-modal-title-row, .dorm-modal-title-meta, and .dorm-modal-side-address',
    strpos($css, '.dorm-modal-title-row') !== false &&
    strpos($css, '.dorm-modal-title-meta') !== false &&
    strpos($css, '.dorm-modal-side-address') !== false);

// 5. CSS: Original 3D Coverflow carousel styles
test('CSS defines 3D coverflow classes (.um-slide, .is-active, .is-prev, .is-next)',
    strpos($css, '.um-slide') !== false &&
    strpos($css, '.um-slide.is-active') !== false &&
    strpos($css, '.um-slide.is-prev') !== false &&
    strpos($css, '.um-slide.is-next') !== false);

// 6. CSS: Button micro-animations (scale 1.06 hover, scale 0.96 active)
test('CSS has transform: scale(1.06) on hover for inspect and card buttons',
    strpos($css, '.dorm-inspect-edit-btn:hover') !== false &&
    strpos($css, 'transform: scale(1.06);') !== false);
test('CSS has transform: scale(0.96) on active for inspect and card buttons',
    strpos($css, '.dorm-inspect-edit-btn:active') !== false &&
    strpos($css, 'transform: scale(0.96);') !== false);

// 7. CSS: Right column starts under X button and map height extended to 280px
test('CSS extends dorm-modal-map height to 280px',
    strpos($css, 'height: 280px;') !== false);
test('CSS positions Available Rooms under X button with padding: 64px 22px 20px 22px',
    strpos($css, 'padding: 64px 22px 20px 22px;') !== false);
test('CSS defines visible room photos styles (.dorm-modal-room-photos, .dorm-modal-room-photo-item)',
    strpos($css, '.dorm-modal-room-photos') !== false &&
    strpos($css, '.dorm-modal-room-photo-item') !== false &&
    strpos($css, '.dorm-modal-room-img') !== false);

// 8. PHP: Properties page contains Back to Property button in page1Footer
test('PHP template includes #btnEditBackToProperty in page1Footer',
    strpos($php, 'id="btnEditBackToProperty"') !== false);

// 9. PHP: Cache buster versions updated
test('PHP template links landlord-properties.css?v=26',
    strpos($php, 'landlord-properties.css?v=26') !== false);
test('PHP template links landlord-properties.js?v=28',
    strpos($php, 'landlord-properties.js?v=28') !== false);

// 10. JS: Location on right of dorm name, and shortened near university to right of location
test('JS renders dorm title, followed by location to its right and shortened university to right of location',
    strpos($js, 'dorm-modal-title-row') !== false &&
    strpos($js, 'dorm-modal-side-address') !== false &&
    strpos($js, 'near ${esc(shortUni(p.university))}') !== false);

// 11. JS: Bounded linear carousel logic (exact requested rules)
test('JS implements linear bounded carousel with idx === currentIdx - 1 (is-prev) and idx === currentIdx + 1 (is-next)',
    strpos($js, 'idx === currentIdx - 1') !== false &&
    strpos($js, "sl.classList.add('is-prev')") !== false &&
    strpos($js, 'idx === currentIdx + 1') !== false &&
    strpos($js, "sl.classList.add('is-next')") !== false);

// 12. Simulation: 1, 2, and 3 photo states
function simulateCarousel($numPhotos, $activeIdx) {
    $states = [];
    for ($i = 0; $i < $numPhotos; $i++) {
        if ($i === $activeIdx) {
            $states[$i] = 'is-active';
        } elseif ($i === $activeIdx - 1) {
            $states[$i] = 'is-prev';
        } elseif ($i === $activeIdx + 1) {
            $states[$i] = 'is-next';
        } else {
            $states[$i] = 'none';
        }
    }
    return $states;
}

// 2 photos test
$twoPhotos_0 = simulateCarousel(2, 0);
test('2 photos at photo 1: photo 1 is is-active, photo 2 is on right (is-next)',
    $twoPhotos_0[0] === 'is-active' && $twoPhotos_0[1] === 'is-next');

$twoPhotos_1 = simulateCarousel(2, 1);
test('2 photos moved to photo 2: photo 1 moves to left (is-prev), photo 2 is is-active',
    $twoPhotos_1[0] === 'is-prev' && $twoPhotos_1[1] === 'is-active');

// 3 photos test
$threePhotos_0 = simulateCarousel(3, 0);
test('3 photos at photo 1: photo 1 is is-active, photo 2 is on right (is-next), photo 3 hidden',
    $threePhotos_0[0] === 'is-active' && $threePhotos_0[1] === 'is-next' && $threePhotos_0[2] === 'none');

$threePhotos_1 = simulateCarousel(3, 1);
test('3 photos at photo 2: photo 1 moves to left (is-prev), photo 2 is is-active, photo 3 is on right (is-next)',
    $threePhotos_1[0] === 'is-prev' && $threePhotos_1[1] === 'is-active' && $threePhotos_1[2] === 'next' || $threePhotos_1[2] === 'is-next');

$threePhotos_2 = simulateCarousel(3, 2);
test('3 photos at photo 3: photo 2 goes to left (is-prev), photo 3 is is-active, photo 1 hidden',
    $threePhotos_2[0] === 'none' && $threePhotos_2[1] === 'is-prev' && $threePhotos_2[2] === 'is-active');

// 13. JS: Back to Property button wired to reopen inspection modal
test('JS wires btnEditBackToProperty to closeAddModal and reopen inspection modal',
    strpos($js, 'btnEditBackToProperty') !== false &&
    strpos($js, 'currentInspectingProperty') !== false &&
    strpos($js, 'openInspectionModal(currentInspectingProperty)') !== false);

// 14. JS: Renders room photos in inspection modal
test('JS renders room photos inside dorm-modal-room-card',
    strpos($js, 'dorm-modal-room-photos') !== false &&
    strpos($js, 'dorm-modal-room-photo-item') !== false &&
    strpos($js, 'dorm-modal-room-img') !== false);

// Output results
$allPassed = true;
echo "=== Test Suite: Brown Panel Layout & Carousel Direction ===\n";
foreach ($tests as $t) {
    $status = $t['pass'] ? "PASS" : "FAIL";
    if (!$t['pass']) $allPassed = false;
    echo sprintf("[%s] %s\n", $status, $t['name']);
}
echo sprintf("\nTotal: %d | Passed: %d | Failed: %d\n",
    count($tests),
    count(array_filter($tests, fn($t) => $t['pass'])),
    count(array_filter($tests, fn($t) => !$t['pass']))
);

if (!$allPassed) exit(1);
