(() => {
  'use strict';

  const form = document.getElementById('profileEditForm');
  const save = document.getElementById('profileSaveButton');
  const hint = document.getElementById('profileSaveHint');
  if (!form || !save) return;

  const requiredDocs = (form.dataset.requiredDocs || '')
    .split(',')
    .map((key) => key.trim())
    .filter(Boolean);

  const labels = {
    first_name: 'First Name',
    last_name: 'Last Name',
    phone_number: 'Phone Number',
    facebook_link: 'Facebook Link',
    email: 'Email Address',
    university: 'School / University',
    emergency_name: 'Emergency Contact Name',
    emergency_relationship: 'Relationship',
    emergency_phone: 'Emergency Phone',
  };

  const phoneFields = ['phone_number', 'emergency_phone'];

  function updatePhoneValidity() {
    phoneFields.forEach((name) => {
      const input = form.elements.namedItem(name);
      if (!(input instanceof HTMLInputElement)) return;
      let val = input.value.trim();
      if (!val) {
        input.setCustomValidity(input.required ? `${labels[name] || 'Phone number'} is required.` : '');
        return;
      }
      let digits = val.replace(/\D/g, '');
      if (digits.startsWith('63')) digits = digits.slice(2);
      else if (digits.startsWith('0')) digits = digits.slice(1);
      if (!/^9\d{9}$/.test(digits)) {
        input.setCustomValidity(`${labels[name] || 'Phone number'} must contain 10 digits and begin with 9.`);
      } else {
        input.setCustomValidity('');
      }
    });
  }

  function hasDocument(key) {
    const input = document.getElementById(`${key}_input`);
    const existing = form.querySelector(`[name="existing_${key}"]`);
    const remove = document.getElementById(`remove_${key}`);
    const selected = !!(input?.files?.length);
    const stored = !!(existing?.value.trim() && (!remove || remove.value !== '1'));
    return selected || stored;
  }

  function issueFor(control) {
    const name = control.name || control.id;
    const label = labels[name] || 'This field';
    if (phoneFields.includes(name) && control.value.trim()) {
      return `${label} must contain 10 digits and begin with 9.`;
    }
    if (control.validity?.typeMismatch) return `${label} must be a valid email address.`;
    if (control.validity?.patternMismatch) return `${label} has an invalid format.`;
    return `${label} is required.`;
  }

  function collectIssues() {
    updatePhoneValidity();
    const issues = [];
    const checked = new Set();

    Array.from(form.elements).forEach((control) => {
      if (!(control instanceof HTMLElement) || typeof control.checkValidity !== 'function') return;
      if (control.type === 'hidden' || control.disabled || checked.has(control)) return;
      checked.add(control);
      if (!control.checkValidity()) issues.push({ message: issueFor(control), target: control });
    });

    [
      ['university', 'school-select'],
      ['emergency_relationship', 'relationship-select'],
    ].forEach(([name, rootId]) => {
      const control = form.elements.namedItem(name);
      if (!control) return;
      if (control.value && control.value.trim()) return;
      const target = document.querySelector(`#${rootId} .um-select-btn`) || document.getElementById(rootId);
      issues.push({ message: `${labels[name] || name} is required.`, target });
    });

    requiredDocs.forEach((key) => {
      const dropzone = document.getElementById(`dropzone_${key}`);
      if (!dropzone) return;
      if (hasDocument(key)) return;
      const documentName = key === 'school_id' ? 'School ID' : 'Government ID';
      issues.push({ message: `${documentName} photo is required.`, target: dropzone });
    });

    return issues;
  }

  function sync() {
    const issues = collectIssues();
    save.disabled = false;
    save.setAttribute('aria-disabled', 'false');
    if (hint) {
      hint.hidden = issues.length === 0;
      hint.textContent = issues.length ? `Please correct: ${issues.map(({ message }) => message).join(' ')}` : '';
    }
    return { complete: issues.length === 0, issues };
  }

  phoneFields.forEach((name) => {
    const input = form.elements.namedItem(name);
    if (!(input instanceof HTMLInputElement)) return;
    input.addEventListener('input', () => {
      let v = input.value;
      if (v.startsWith('+63')) v = v.slice(3).trim();
      else if (v.startsWith('63')) v = v.slice(2).trim();
      else if (v.startsWith('0')) v = v.slice(1).trim();
      if (v !== input.value) input.value = v;
    });
  });

  form.addEventListener('input', sync);
  form.addEventListener('change', sync);
  // Removal and custom dropzone controls update hidden fields in their own
  // click handlers, so recheck immediately after those handlers finish.
  form.addEventListener('click', () => setTimeout(sync, 0));
  form.addEventListener('submit', (event) => {
    const state = sync();
    if (state.complete) return;
    event.preventDefault();
    const first = state.issues[0]?.target;
    if (first instanceof HTMLInputElement || first instanceof HTMLSelectElement || first instanceof HTMLTextAreaElement) {
      first.reportValidity();
      first.focus({ preventScroll: true });
    } else if (first instanceof HTMLElement) {
      first.focus?.({ preventScroll: true });
    }
    first?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
  });

  window.syncProfileSaveState = sync;
  sync();
})();
