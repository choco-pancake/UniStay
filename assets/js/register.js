(function () {
  'use strict';

  // --- Password visibility toggles ---
  function setupEyeToggle(btnId, inputId) {
    const btn = document.getElementById(btnId);
    const input = document.getElementById(inputId);
    if (!btn || !input) return;

    btn.addEventListener('click', function () {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  }

  setupEyeToggle('toggleRegPassword', 'reg-password');
  setupEyeToggle('toggleRegConfirmPassword', 'reg-confirm-password');

  // --- Role Switching (Tenant vs Landlord) ---
  const rolePills = document.querySelectorAll('.role-pill');
  const roleInput = document.getElementById('roleInput');
  const formTitle = document.getElementById('formTitle');
  const submitBtn = document.getElementById('submitBtn');
  const googleBtn = document.getElementById('googleSignUpBtn');
  const panelTitle = document.getElementById('panelTitle');
  const panelCopy = document.getElementById('panelCopy');
  const termsTextManual = document.getElementById('termsTextManual');

  const ROLE_COPY = {
    tenant: {
      title: '<span>Your new place</span><br><span>starts here.</span>',
      copy: 'Find verified rooms near your university, book with confidence, and settle in without the guesswork.',
      formTitle: 'Create your account',
      submitText: 'Create account',
      agreementsHtml: 'I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.'
    },
    landlord: {
      title: '<span>List your place</span><br><span>with confidence.</span>',
      copy: 'Publish verified rooms, connect directly with reliable students, and manage your tenancies with ease.',
      formTitle: 'Landlord Sign Up',
      submitText: 'Create account',
      agreementsHtml: 'I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.'
    }
  };

  function placeIndicator() {
    const toggle = document.querySelector('.role-toggle');
    const indicator = toggle && toggle.querySelector('.tab-indicator');
    if (!indicator) return;
    const active = toggle.querySelector('.role-pill.is-active, .role-pill.active');
    if (!active) return;
    if (!placeIndicator.ready) { indicator.style.transition = 'none'; }
    indicator.style.width = active.offsetWidth + 'px';
    indicator.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    if (!placeIndicator.ready) {
      void indicator.offsetWidth;
      indicator.style.transition = '';
      placeIndicator.ready = true;
    }
  }

  function setRole(role, animated = true) {
    if (!ROLE_COPY[role]) role = 'tenant';

    rolePills.forEach(pill => {
      const on = pill.dataset.role === role;
      pill.classList.toggle('is-active', on);
      pill.classList.toggle('active', on);
      pill.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    if (roleInput) roleInput.value = role;

    if (submitBtn) {
      const span = submitBtn.querySelector('span');
      if (span) {
        span.textContent = ROLE_COPY[role].submitText;
      } else {
        submitBtn.textContent = ROLE_COPY[role].submitText;
      }
    }

    if (googleBtn) {
      googleBtn.href = 'google_oauth.php?role=' + encodeURIComponent(role);
    }

    const googleBadgeTitle = document.getElementById('googleBadgeTitle');
    if (googleBadgeTitle) {
      googleBadgeTitle.textContent = role === 'landlord' ? 'Signing up as Landlord' : 'Signing up as Tenant';
    }

    const googleChangeLink = document.getElementById('googleChangeLink');
    if (googleChangeLink) {
      googleChangeLink.href = 'register.php?action=clear_google&role=' + encodeURIComponent(role);
    }

    placeIndicator();

    const fadeTargets = document.querySelectorAll('.role-fade-target');

    const updateContent = () => {
      if (formTitle) formTitle.textContent = ROLE_COPY[role].formTitle;
      if (panelTitle) panelTitle.innerHTML = ROLE_COPY[role].title;
      if (panelCopy) panelCopy.textContent = ROLE_COPY[role].copy;
      if (termsTextManual) termsTextManual.innerHTML = ROLE_COPY[role].agreementsHtml;
    };

    if (animated && fadeTargets.length) {
      fadeTargets.forEach(el => el.classList.add('is-switching'));
      setTimeout(() => {
        updateContent();
        requestAnimationFrame(() => {
          fadeTargets.forEach(el => el.classList.remove('is-switching'));
        });
      }, 120);
    } else {
      updateContent();
    }

    // Update query string in browser without reload
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('type', role);
      window.history.replaceState({}, '', url.toString());
    } catch (_) { }
  }

  rolePills.forEach(pill => {
    pill.addEventListener('click', function () {
      setRole(this.dataset.role);
    });
  });

  placeIndicator();
  window.addEventListener('load', placeIndicator);
  window.addEventListener('resize', placeIndicator);
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(placeIndicator);
  }

  // --- Real-time password confirmation check ---
  const regPassword = document.getElementById('reg-password');
  const regConfirm = document.getElementById('reg-confirm-password');

  function checkPasswordMatch() {
    if (!regPassword || !regConfirm) return;
    if (regConfirm.value && regPassword.value !== regConfirm.value) {
      regConfirm.setCustomValidity('Passwords do not match.');
      regConfirm.classList.add('is-invalid');
    } else {
      regConfirm.setCustomValidity('');
      regConfirm.classList.remove('is-invalid');
    }
  }

  if (regPassword && regConfirm) {
    regPassword.addEventListener('input', checkPasswordMatch);
    regConfirm.addEventListener('input', checkPasswordMatch);
  }

  // --- Policy Dialog Modal ---
  const policyModal = document.getElementById('policyModal');
  const policyTitle = document.getElementById('policyModalTitle');
  const policyBody = document.getElementById('policyModalBody');
  const policyFooterNote = document.getElementById('policyModalFooterNote');
  let policyData = {};

  try {
    const dataEl = document.getElementById('policyData');
    if (dataEl) policyData = JSON.parse(dataEl.textContent);
  } catch (err) {
    console.error('Failed to parse policy data', err);
  }

  function openPolicyModal(key) {
    if (!policyModal || !policyData[key]) return;
    const item = policyData[key];

    if (policyTitle) policyTitle.textContent = item.title || 'Policy';
    if (policyBody) {
      policyBody.innerHTML = (item.sections || []).map(sec => {
        return `<section class="policy-card"><h2>${sec[0]}</h2><p>${sec[1]}</p></section>`;
      }).join('');
    }
    if (policyFooterNote) {
      if (item.slug) {
        policyFooterNote.innerHTML = `<a href="../pages/${item.slug}.php" target="_blank" class="policy-modal-page-link">Read full page &rarr;</a>`;
      } else {
        policyFooterNote.textContent = '© 2026 UniStay Legal Standards';
      }
    }

    policyModal.classList.remove('is-closing');
    policyModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closePolicyModal() {
    if (!policyModal || policyModal.hidden) return;
    policyModal.classList.add('is-closing');
    setTimeout(() => {
      policyModal.hidden = true;
      policyModal.classList.remove('is-closing');
      document.body.style.overflow = '';
    }, 220);
  }

  // Delegated listener for policy buttons (supports dynamic role switches)
  document.addEventListener('click', function (e) {
    const link = e.target.closest('.policy-link');
    if (link) {
      e.preventDefault();
      const key = link.dataset.policy;
      if (key) openPolicyModal(key);
      return;
    }

    if (e.target.closest('[data-policy-close]')) {
      e.preventDefault();
      closePolicyModal();
    }
  });

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && policyModal && !policyModal.hidden) {
      closePolicyModal();
    }
  });

})();