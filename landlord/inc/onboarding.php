<script id="landlord-onboarding-data" type="application/json"><?= json_encode([
    'userId' => $userId,
    'isNew' => $isNewLandlord,
    'incomplete' => $profileIncomplete,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<dialog id="landlord-guide-modal" class="guide-modal" aria-labelledby="landlord-guide-title-1">
  <div class="guide-modal-card">
    <div class="guide-header">
      <span class="guide-step-pill" id="landlord-guide-step" aria-live="polite">Step 1 of 4</span>
      <button type="button" class="guide-close-btn" id="landlord-guide-skip">Skip Guide &times;</button>
    </div>
    <div class="guide-slides-wrapper">
      <section class="guide-slide active" data-step="1">
        <div class="guide-icon-banner"><svg aria-hidden="true"><use href="#user-icon"/></svg></div>
        <h3 id="landlord-guide-title-1" tabindex="-1">1. Complete registration information</h3>
        <p>Make it easy for students to get to know and contact you.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">Complete your name, phone number, Facebook link, and email in Settings.</div>
          <div class="guide-highlight-item">Upload a clear government ID for admin verification.</div>
        </div>
      </section>
      <section class="guide-slide" data-step="2" hidden>
        <div class="guide-icon-banner"><svg aria-hidden="true"><use href="#home-icon"/></svg></div>
        <h3 id="landlord-guide-title-2" tabindex="-1">2. List a property</h3>
        <p>Help students find a home near their university.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">Add your dorm's details, photos, location, and proof of property ownership.</div>
          <div class="guide-highlight-item">List room units, amenities, monthly rates, and any security deposit.</div>
        </div>
      </section>
      <section class="guide-slide" data-step="3" hidden>
        <div class="guide-icon-banner"><svg aria-hidden="true"><use href="#booking-icon"/></svg></div>
        <h3 id="landlord-guide-title-3" tabindex="-1">3. Approve or decline tenant requests</h3>
        <p>Manage incoming room reservations from the Requests page.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">Review the student's information and requested room.</div>
          <div class="guide-highlight-item">Approve or decline the request, then keep track of your residents.</div>
        </div>
      </section>
      <section class="guide-slide" data-step="4" hidden>
        <div class="guide-icon-banner"><svg aria-hidden="true"><use href="#home-icon"/></svg></div>
        <h3 id="landlord-guide-title-4" tabindex="-1">List, host, thrive.</h3>
        <p>A welcoming home helps students focus on their future. Build trust, care for your residents, and grow with UniStay.</p>
      </section>
    </div>
    <div class="guide-footer">
      <div class="guide-dots" aria-label="Guide slides">
        <?php for ($step = 1; $step <= 4; $step++): ?>
          <button type="button" class="guide-dot<?= $step === 1 ? ' active' : '' ?>" data-goto="<?= $step ?>" aria-label="Go to slide <?= $step ?>"></button>
        <?php endfor; ?>
      </div>
      <div class="guide-buttons">
        <button type="button" class="btn-guide-prev" id="landlord-guide-back" hidden>Back</button>
        <button type="button" class="btn-guide-next" id="landlord-guide-next">Next &rarr;</button>
      </div>
    </div>
  </div>
</dialog>
<dialog id="landlord-completion-modal" class="onboarding-toast-modal" aria-labelledby="landlord-completion-title">
  <div class="onboarding-toast-card">
    <div class="onboarding-toast-icon"><svg aria-hidden="true"><use href="#user-icon"/></svg></div>
    <h2 id="landlord-completion-title">Finish setting up your profile</h2>
    <p>Would you like to complete your registration information and upload your government ID now, or finish it later?</p>
    <div class="onboarding-toast-actions">
      <a href="settings.php?mode=edit" class="btn-toast-primary" id="landlord-completion-now">Now</a>
      <button type="button" class="btn-toast-secondary" id="landlord-completion-later">Later</button>
    </div>
  </div>
</dialog>
