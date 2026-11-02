(() => {
  'use strict';
  let pending = false;
  const originals = new Map();
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    if (pending) { event.preventDefault(); return; }
    const action = form.querySelector('input[name="action"]')?.value;
    const approving = /^approve_(user|landlord|tenant)$/.test(action || '');
    const declining = /^reject_(user|landlord|tenant)$/.test(action || '');
    if (!approving && !declining) return;
    const button = event.submitter || form.querySelector('button[type="submit"]');
    if (!button) return;
    pending = true;
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    document.querySelectorAll('.btn-approve, .btn-reject').forEach(control => {
      originals.set(control, {text: control.textContent, disabled: control.disabled});
      control.disabled = true;
    });
    button.textContent = approving ? 'Approving...' : 'Declining...';
    // Action, user, role and reason are hidden inputs/textarea and remain submitted.
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    originals.forEach((original, control) => {
      control.textContent = original.text;
      control.disabled = original.disabled;
    });
    originals.clear(); pending = false;
    document.querySelectorAll('form[data-submitting]').forEach(form => {
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
    });
    // Reload current verification statuses instead of displaying a stale review card.
    window.location.reload();
  });
})();
