<?php
$slug = $slug ?? '';

require_once __DIR__ . '/policy-content.php';

$pages = [
  'room-finder' => [
    'title' => 'Room Finder',
    'sections' => [
      ['Search in seconds', 'Room Finder lets students browse verified rooms near their university, filtered by rent, room type, capacity, and amenities. Every listing is posted by a verified landlord so you know exactly what you are booking.'],
      ['What you can do', 'Compare rooms side by side, open any listing for photos and pricing, and jump straight to the map view to see how far the room is from campus.'],
    ],
  ],
  'property-lister' => [
    'title' => 'Property Lister',
    'sections' => [
      ['List once, reach every student', 'Property Lister gives landlords a guided form to publish a property with its university, address, description, photos, and exact map coordinates.'],
      ['Built for landlords', 'Add rooms, set rent and deposit ranges, choose the room type and capacity, and mark what amenities each room offers. New listings appear in search right away.'],
    ],
  ],
  'tenant-dashboard' => [
    'title' => 'Tenant Dashboard',
    'sections' => [
      ['Your stay at a glance', 'The tenant dashboard shows your current booking, monthly rent, due dates, and quick actions for the rooms and properties you are watching.'],
      ['Stay in the loop', 'Every booking request, approval, and update reaches you through your notification centre and your profile menu.'],
    ],
  ],
  'landlord-dashboard' => [
    'title' => 'Landlord Dashboard',
    'sections' => [
      ['Manage everything in one place', 'The landlord dashboard summarises your properties, occupied rooms, active tenants, and monthly income so you always know how your listings are performing.'],
      ['Full control', 'Update a property, publish a room, review tenants, and keep your occupancy numbers current without ever leaving the dashboard.'],
    ],
  ],
  'features' => [
    'title' => 'Features',
    'sections' => [
      ['Everything UniStay includes', 'Room search with filters, an interactive map, a booking manager, property and room listings, document verification, and instant notifications.'],
      ['Made for student housing', 'Room types from single to decuple, flexible rent and deposit ranges, amenity tags, photo galleries, and a responsive design that works on any device.'],
    ],
  ],
  'about-us' => [
    'title' => 'About Us',
    'sections' => [
      ['Who we are', 'UniStay is a student housing platform built to remove the guesswork from finding a place to live while studying away from home.'],
      ['What we do', 'We connect students with verified landlords, keep every listing transparent, and give both sides the tools they need to manage a tenancy from start to finish.'],
    ],
  ],
  'our-mission' => [
    'title' => 'Our Mission',
    'sections' => [
      ['Our mission', 'To make searching for, booking, and managing student accommodation simple, safe, and fair for everyone involved.'],
      ['How we get there', 'We verify listings, protect student data, and keep the experience fast and honest on every screen size.'],
    ],
  ],
  'contact-us' => [
    'title' => 'Contact Us',
    'sections' => [
      ['Get in touch', 'Questions about a listing, a booking, or your account? Send us a message and our team will get back to you within one business day.'],
      ['Support hours', 'Monday to Friday, 9:00 AM to 6:00 PM. Reach us at support@unistay.example or through the contact form inside your dashboard.'],
    ],
  ],
  'privacy-policy' => [
    'title' => 'Privacy Policy',
    'sections' => [
      ['What we collect', 'We collect only the information needed to run UniStay: your name, role, contact details, booking activity, and the listings a landlord publishes.'],
      ['How we use it', 'Your data is used to operate search, bookings, and notifications. We do not sell your personal information to third parties.'],
      ['Your choices', 'You can request a copy of your data or ask us to delete your account at any time from your settings page.'],
    ],
  ],
  'terms-of-service' => [
    'title' => 'Terms of Service',
    'sections' => [
      ['Using UniStay', 'By using UniStay you agree to provide accurate information, respect other members of the community, and use the platform only for lawful purposes.'],
      ['Bookings and listings', 'Landlords are responsible for keeping listings truthful and up to date. Students are responsible for reviewing a room before sending a booking request.'],
      ['Changes to the service', 'We may add, change, or remove features as UniStay evolves, and we will keep this page updated when our terms change.'],
    ],
  ],
  'community-guidelines' => [
    'title' => 'Community Guidelines',
    'sections' => [
      ['Be respectful', 'Treat landlords and students with respect. Harassment, discrimination, or hate speech of any kind is not tolerated.'],
      ['Be accurate', 'List rooms honestly with real photos and real prices, and only send booking requests you intend to follow through on.'],
      ['Keep it on UniStay', 'Handle payments and communication through the platform so both sides stay protected and documented.'],
    ],
  ],
];

// The legal pages are shared with the registration page's policy viewer.
foreach ($policyPages as $policySlug => $policy) {
    $pages[$policySlug] = $policy;
}

$page = $pages[$slug] ?? ['title' => 'Page not found', 'sections' => [['Not found', 'The page you are looking for does not exist. Head back to the landing page and try again.']]];
$meta = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $meta; ?> &middot; UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/pages.css">
</head>
<body>
<header class="bar">
  <a class="logo" href="../index.php"><img src="../assets/images/logo.png" width="44" height="44" alt="logo">UniStay</a>
  <a class="back" href="../index.php">&larr; Back to home</a>
</header>

<main class="wrap">
  <p class="crumb"><a href="../index.php">Home</a> / <?php echo $meta; ?></p>
  <h1><?php echo $meta; ?></h1>
  <?php foreach ($page['sections'] as $section) { ?>
    <section>
      <h2><?php echo htmlspecialchars($section[0], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p><?php echo htmlspecialchars($section[1], ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
  <?php } ?>
</main>

<footer class="foot">&copy; 2026 UniStay. Developed by Code of Duty. All Rights Reserved.</footer>
</body>
</html>
