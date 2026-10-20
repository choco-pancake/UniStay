<?php
require_once __DIR__ . '/../auth/google_oauth.php';

try {
    $authenticatedUser = google_oauth_require_user('landlord');
} catch (RuntimeException $error) {
    http_response_code(401);
    $signInError = $error->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Landlord Dashboard | UniStay</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #3f302b; background: #fdf9f3; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 18px max(24px, calc((100% - 1040px) / 2)); background: #fff; border-bottom: 1px solid #eadfd4; }
        .brand { display: flex; align-items: center; gap: 12px; color: #5c3327; font-family: Georgia, serif; font-size: 20px; font-weight: 700; }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        a { color: #7b4a3b; }
        main { width: min(100% - 40px, 1040px); margin: 48px auto; }
        h1 { color: #5c3327; font-family: Georgia, serif; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 28px; }
        .card { padding: 24px; border: 1px solid #eadfd4; border-radius: 14px; background: #fff; }
        .card h2 { margin-top: 0; font-size: 18px; }
        .card a { display: inline-block; margin-top: 8px; font-weight: 600; }
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<header>
    <div class="brand"><img src="../assets/images/tenant/logo.png" alt=""><span>UniStay</span></div>
    <?php if (isset($authenticatedUser)): ?>
        <span><?php echo htmlspecialchars($authenticatedUser['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="../auth/login.php?action=logout">Sign out</a>
    <?php endif; ?>
</header>
<main>
    <?php if (isset($signInError)): ?>
        <p role="alert"><?php echo htmlspecialchars($signInError, ENT_QUOTES, 'UTF-8'); ?> <a href="../auth/login.php">Return to sign in</a></p>
    <?php endif; ?>
    <h1>Landlord Dashboard</h1>
    <p>Manage your UniStay listings and rooms.</p>
    <section class="cards" aria-label="Landlord tools">
        <article class="card">
            <h2>Properties</h2>
            <p>Add and manage your dorm listings.</p>
            <a href="properties.php">Manage properties</a>
        </article>
        <article class="card">
            <h2>Rooms</h2>
            <p>Review room details and availability.</p>
            <a href="rooms.php">Manage rooms</a>
        </article>
    </section>
</main>
</body>
</html>
