<?php
echo "=======================================================\n";
echo "ADD PROPERTY REORDERING & CAROUSEL VERIFICATION TEST\n";
echo "=======================================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertTest($condition, $message) {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message\n";
    }
}

// 1. Verify HTML DOM field order in landlord/properties.php
$html = file_get_contents(__DIR__ . '/../landlord/properties.php');

$posName = strpos($html, 'id="propName"');
$posUni = strpos($html, 'id="universityInput"');
$posAddress = strpos($html, 'id="addressInput"');
$posDesc = strpos($html, 'id="propDesc"');
$posPhotos = strpos($html, 'id="photoZone"');

assertTest($posName !== false, "propName input exists in properties.php");
assertTest($posUni !== false, "universityInput exists in properties.php");
assertTest($posAddress !== false, "addressInput exists in properties.php");
assertTest($posDesc !== false, "propDesc textarea exists in properties.php");
assertTest($posPhotos !== false, "photoZone exists in properties.php");

assertTest($posName < $posUni, "1. Dorm Name appears before University selection");
assertTest($posUni < $posAddress, "2. University selection appears before Address input");
assertTest($posAddress < $posDesc, "3. Address / location input appears BEFORE Description");
assertTest($posDesc < $posPhotos, "4. Description appears BEFORE Dorm Photos");

// 2. Verify Carousel logic in assets/js/landlord-properties.js
$js = file_get_contents(__DIR__ . '/../assets/js/landlord-properties.js');

assertTest(strpos($js, 'dorm-single-square-wrap') !== false, "Single photo centered square wrap exists in JS");
assertTest(strpos($js, 'inspectCarousel') !== false, "inspectCarousel element exists in JS");
assertTest(strpos($js, 'carouselPrevBtn') !== false, "carouselPrevBtn element exists in JS");
assertTest(strpos($js, 'carouselNextBtn') !== false, "carouselNextBtn element exists in JS");
assertTest(strpos($js, 'carouselCount') !== false, "carouselCount element exists in JS");

// Check bounded linear carousel button logic
assertTest(strpos($js, "currentIdx > 0") !== false && strpos($js, "currentIdx--") !== false, "Prev button uses bounded navigation logic");
assertTest(strpos($js, "currentIdx < photos.length - 1") !== false && strpos($js, "currentIdx++") !== false, "Next button uses bounded navigation logic");
assertTest(strpos($js, "slide-out-right") !== false || strpos($js, "is-prev") !== false, "Prev button triggers directional slide out right");
assertTest(strpos($js, "slide-out-left") !== false || strpos($js, "is-next") !== false, "Next button triggers directional slide out left");

// 3. Verify CSS styling in assets/css/landlord-properties.css
$css = file_get_contents(__DIR__ . '/../assets/css/landlord-properties.css');

assertTest(strpos($css, '.dorm-single-square-wrap') !== false, ".dorm-single-square-wrap style rule exists in CSS");
assertTest(strpos($css, 'max-height: 220px') !== false, "Single photo has compact max-height: 220px");
assertTest(strpos($css, '.um-carousel-btn') !== false, ".um-carousel-btn style rule exists in CSS");
assertTest(strpos($css, 'border-radius: 50%') !== false, "Carousel button has circular border-radius: 50%");
assertTest(strpos($css, '.um-slide.is-prev') !== false, ".um-slide.is-prev rule exists in CSS");
assertTest(strpos($css, '.um-slide.is-next') !== false, ".um-slide.is-next rule exists in CSS");
assertTest(strpos($css, '.um-slide.is-active') !== false, ".um-slide.is-active rule exists in CSS");

echo "\n-------------------------------------------------------\n";
echo "SUMMARY: $passCount / $totalTests tests passed.\n";
echo "-------------------------------------------------------\n";
