<?php
$type = isset($_GET['type']) && $_GET['type'] === 'landlord' ? 'landlord' : 'tenant';
$isLandlord = $type === 'landlord';
$pageTitle = $isLandlord ? 'Landlord Sign Up' : 'Tenant Sign Up';
$heading = $isLandlord ? 'Your Space, Their New Home' : 'Your New Place Starts Here';
$switchText = $isLandlord ? 'Looking for a place to stay?' : 'Looking to list a property?';
$switchLinkText = $isLandlord ? 'Sign up as Tenant' : 'Apply as Landlord';
$switchType = $isLandlord ? 'tenant' : 'landlord';
$accountType = $isLandlord ? 'landlord' : 'tenant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UniStay - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/css/register.css">
</head>
<body>

  <header class="header">
    <a class="logo-container" href="../index.php" aria-label="UniStay home">
      <img class="logo-icon" src="../assets/images/logo.png" alt="logo">
      <span class="logo-text">UniStay</span>
    </a>
  </header>

  <main class="container">
    <div class="top-switch">
      <?= htmlspecialchars($switchText, ENT_QUOTES, 'UTF-8') ?>
      <a href="register.php?type=<?= rawurlencode($switchType) ?>"><?= htmlspecialchars($switchLinkText, ENT_QUOTES, 'UTF-8') ?></a>
    </div>

    <div class="card">
      <h1><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="subtitle">Description Description Description Description</p>

      <form method="post" action="register.php?type=<?= rawurlencode($type) ?>">
        <div class="form-grid">
          <div class="form-group">
            <label>Full Name <span class="required">*</span></label>
            <div class="sub-labels">
              <div class="sub-label-item">
                <div class="input-wrapper">
                  <input type="text" name="first_name" aria-label="First Name" autocomplete="given-name" placeholder="First Name" required minlength="1" maxlength="50" pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:(?:[ ']|-)[A-Za-zÀ-ÖØ-öø-ÿ]+)*" data-validation="name">
                </div>
                <span class="sub-text">First Name</span>
              </div>
              <div class="sub-label-item">
                <div class="input-wrapper">
                  <input type="text" name="middle_name" aria-label="Middle Name (optional)" autocomplete="additional-name" placeholder="Middle Name" maxlength="50" pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:(?:[ ']|-)[A-Za-zÀ-ÖØ-öø-ÿ]+)*" data-validation="name">
                  <span class="optional-badge">Optional</span>
                </div>
                <span class="sub-text">Middle Name</span>
              </div>
              <div class="sub-label-item">
                <div class="input-wrapper">
                  <input type="text" name="last_name" aria-label="Last Name" autocomplete="family-name" placeholder="Last Name" required minlength="1" maxlength="50" pattern="[A-Za-zÀ-ÖØ-öø-ÿ]+(?:(?:[ ']|-)[A-Za-zÀ-ÖØ-öø-ÿ]+)*" data-validation="name">
                </div>
                <span class="sub-text">Last Name</span>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <div class="input-wrapper">
              <span class="input-prefix" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false"><path d="M2.5 4.5h15v11h-15zM3 5l7 5 7-5"/></svg>
              </span>
              <input type="email" name="email" autocomplete="email" placeholder="Email Address" required maxlength="254" pattern="[^@\s]+@[^@\s]+\.[^@\s]{2,}" data-validation="email">
            </div>
          </div>

          <div class="form-group">
            <label>Phone Number <span class="required">*</span></label>
            <div class="input-wrapper">
              <span class="input-prefix input-prefix--country" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false"><path d="M6.1 2.8 8.4 6l-1.6 1.7a12 12 0 0 0 5.5 5.5l1.7-1.6 3.2 2.3-.8 3a1.2 1.2 0 0 1-1.3.9A14.4 14.4 0 0 1 2.7 5a1.2 1.2 0 0 1 .9-1.3z"/></svg>
                <span>+63</span>
              </span>
              <input type="tel" name="phone" autocomplete="tel-national" inputmode="numeric" placeholder="9XXXXXXXXX" required minlength="10" maxlength="10" pattern="9[0-9]{9}" data-validation="phone">
            </div>
          </div>

          <div class="form-group">
            <label>Messenger Link <span class="required">*</span></label>
            <div class="input-wrapper">
              <span class="input-prefix input-prefix--messenger" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false"><path d="M10 2.5a7 7 0 0 0-6.1 10.4L3 17l4.2-1a7 7 0 1 0 2.8-13.5z"/><path d="m6.5 11 3-3 2 2 2.5-2"/></svg>
                <span>https://m.me/</span>
              </span>
              <input type="text" name="messenger" autocomplete="url" placeholder="Your Messenger username" required minlength="5" maxlength="50" pattern="[A-Za-z0-9.]{5,50}" data-validation="messenger">
            </div>
          </div>

          <div class="form-group">
            <label>Create Password <span class="required">*</span></label>
            <div class="input-wrapper">
              <input type="password" name="password" autocomplete="new-password" placeholder="8–16 characters with a symbol" required minlength="8" maxlength="16" pattern="(?!.*\s)(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}" data-validation="password">
              <button class="eye-icon" type="button" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 20 20" focusable="false"><path d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5z"/><circle cx="10" cy="10" r="2.3"/><path class="eye-slash" d="m3 3 14 14"/></svg>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label>Confirm Password <span class="required">*</span></label>
            <div class="input-wrapper">
              <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Confirm Password" required minlength="8" maxlength="16" pattern="(?!.*\s)(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}" data-validation="confirm-password">
              <button class="eye-icon" type="button" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 20 20" focusable="false"><path d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5z"/><circle cx="10" cy="10" r="2.3"/><path class="eye-slash" d="m3 3 14 14"/></svg>
              </button>
            </div>
          </div>

          <div class="form-group full-width terms-group">
            <input type="checkbox" id="terms" name="terms" required data-validation="terms">
            <label for="terms">
              I agree to the <a href="#">Terms of Residence</a>, <a href="#">Student Code of Conduct</a>, and <a href="#">Privacy Policy</a>.
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-primary"><span>Create Account</span></button>
          <div class="bottom-link">
            Already a <?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>? <a href="#">Log in</a>
          </div>
        </div>
      </form>
    </div>
  </main>

  <script src="../assets/js/register.js"></script>
</body>
</html>
