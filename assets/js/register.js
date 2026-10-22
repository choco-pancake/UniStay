/**
 * UniStay Sign Up Page Logic
 * Handles role switching, password visibility, live validation, and policy dialog.
 */
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
      agreementsHtml: 'I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="conduct">Student Code of Conduct</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.'
    },
    landlord: {
      title: '<span>List your place</span><br><span>with confidence.</span>',
      copy: 'Publish verified rooms, connect directly with reliable students, and manage your tenancies with ease.',
      formTitle: 'Landlord Sign Up',
      submitText: 'Create landlord account',
      agreementsHtml: 'I agree to the <button type="button" class="policy-link" data-policy="terms">Terms of Service</button>, <button type="button" class="policy-link" data-policy="community">Community Guidelines</button>, and <button type="button" class="policy-link" data-policy="privacy">Privacy Policy</button>.'
    }
  };

  function setRole(role) {
    if (!ROLE_COPY[role]) role = 'tenant';

    rolePills.forEach(pill => {
      pill.classList.toggle('is-active', pill.dataset.role === role);
    });

    if (roleInput) roleInput.value = role;
    if (formTitle) formTitle.textContent = ROLE_COPY[role].formTitle;
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

    if (panelTitle) panelTitle.innerHTML = ROLE_COPY[role].title;
    if (panelCopy) panelCopy.textContent = ROLE_COPY[role].copy;
    if (termsTextManual) termsTextManual.innerHTML = ROLE_COPY[role].agreementsHtml;

    // Update query string in browser without reload
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('type', role);
      window.history.replaceState({}, '', url.toString());
    } catch (_) {}
  }

  rolePills.forEach(pill => {
    pill.addEventListener('click', function () {
      setRole(this.dataset.role);
    });
  });

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
        return `<h3>${sec[0]}</h3><p>${sec[1]}</p>`;
      }).join('');
    }

    policyModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closePolicyModal() {
    if (!policyModal) return;
    policyModal.hidden = true;
    document.body.style.overflow = '';
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