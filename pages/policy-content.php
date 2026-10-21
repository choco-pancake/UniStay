<?php
/**
 * Shared policy content used by the standalone pages in /pages and by the
 * policy viewer section on the registration page.
 */
$policyPages = [
    'terms-of-residence' => [
        'title' => 'Terms of Residence',
        'sections' => [
            ['Living in a UniStay property', 'Every tenant receives a signed residential agreement that states the rent, deposit, utilities, house rules, and the exact move-in and move-out dates.'],
            ['Taking care of your home', 'Keep the unit clean, report repairs quickly, and respect the neighbours. Damage beyond normal wear may be deducted from your deposit.'],
            ['Payments and due dates', 'Rent is due on the agreed date each month. Late payments may incur a fee, and continued non-payment can end the agreement after the usual notice.'],
            ['Leaving the property', 'Give at least thirty days notice before moving out and return all keys, ID cards, and move-out clearance forms.'],
        ],
    ],
    'student-code-of-conduct' => [
        'title' => 'Student Code of Conduct',
        'sections' => [
            ['Treat people with respect', 'Harassment, discrimination, hate speech, and bullying are not tolerated on the platform, in listings, or in any message you send.'],
            ['Keep communication on UniStay', 'Handle bookings, payments, and conversations through the platform so both students and landlords stay protected and documented.'],
            ['Be honest in your listings', 'Photos, descriptions, and prices must reflect the real property. Fake listings and misleading advertisements are removed immediately.'],
            ['Acceptable use', 'Do not attempt to disrupt the platform, scrape listings without permission, or post content that is abusive, infringing, or unlawful.'],
        ],
    ],
    'privacy-policy' => [
        'title' => 'Privacy Policy',
        'sections' => [
            ['What we collect', 'We collect only what UniStay needs to run: your name, contact details, verification documents, booking activity, and the listings a landlord publishes.'],
            ['How we use it', 'Your data powers search, bookings, payments, and notifications. We never sell your personal information to third parties.'],
            ['How we protect it', 'Passwords are hashed, verification files are stored outside the public web root, and database access is restricted to the application.'],
            ['Your choices', 'You can request a copy of your data or ask us to delete your account at any time from your settings page.'],
        ],
    ],
];
