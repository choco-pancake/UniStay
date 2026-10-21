document.querySelectorAll('.eye-icon').forEach(function (button) {
  button.addEventListener('click', function () {
    var password = button.closest('.input-wrapper').querySelector('input');
    var isVisible = password.type === 'text';

    password.type = isVisible ? 'password' : 'text';
    button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
    button.setAttribute('aria-pressed', String(!isVisible));
  });
});

document.querySelectorAll('form').forEach(function (form) {
  var validationMessages = {
    name: 'Use letters, spaces, apostrophes, or hyphens only (up to 50 characters).',
    email: 'Enter a valid email address.',
    phone: 'Enter a 10-digit Philippine mobile number starting with 9.',
    facebook: 'Enter a Facebook username using 5–50 letters, numbers, or periods.',
    password: 'Use 8–16 characters with at least one uppercase letter, one lowercase letter, one number, and one special symbol.',
    'confirm-password': 'Confirm the password; it must match and meet the password rules.',
    terms: 'Please agree to the terms to continue.'
  };
  var touchedFields = new WeakSet();

  function setPasswordMatchValidity() {
    var password = form.querySelector('[data-validation="password"]');
    var confirmPassword = form.querySelector('[data-validation="confirm-password"]');

    if (password && confirmPassword) {
      confirmPassword.setCustomValidity(
        confirmPassword.value && confirmPassword.value !== password.value
          ? validationMessages['confirm-password']
          : ''
      );
    }
  }

  function getErrorMessage(field) {
    if (field.validity.valueMissing) {
      return field.dataset.validation === 'terms'
        ? validationMessages.terms
        : 'This field is required.';
    }

    if (field.validity.typeMismatch || field.validity.patternMismatch) {
      return validationMessages[field.dataset.validation] || 'Check the format and try again.';
    }

    if (field.validity.tooShort || field.validity.tooLong) {
      return validationMessages[field.dataset.validation] || 'Check the allowed length and try again.';
    }

    if (field.validity.customError) {
      return field.validationMessage;
    }

    return '';
  }

  function updateFieldFeedback(field) {
    var isInvalid = !field.validity.valid;
    var error = document.getElementById(field.id + '-error');
    var wrapper = field.closest('.input-wrapper');
    var group = field.closest('.terms-group');

    field.setAttribute('aria-invalid', String(isInvalid));
    if (wrapper) {
      wrapper.classList.toggle('is-invalid', isInvalid);
    }
    if (group) {
      group.classList.toggle('is-invalid', isInvalid);
    }

    if (isInvalid) {
      error.textContent = getErrorMessage(field);
      error.hidden = false;
    } else {
      error.textContent = '';
      error.hidden = true;
    }
  }

  form.querySelectorAll('[data-validation]').forEach(function (field, index) {
    if (!field.id) {
      field.id = 'signup-field-' + index;
    }

    var error = document.createElement('span');
    error.className = 'field-error';
    error.id = field.id + '-error';
    error.setAttribute('aria-live', 'polite');

    var wrapper = field.closest('.input-wrapper');
    var group = field.closest('.terms-group');
    if (wrapper) {
      wrapper.insertAdjacentElement('afterend', error);
    } else if (group) {
      group.insertAdjacentElement('afterend', error);
    }

    field.setAttribute('aria-describedby', error.id);

    field.addEventListener('blur', function () {
      touchedFields.add(field);
      setPasswordMatchValidity();
      updateFieldFeedback(field);
    });

    field.addEventListener('input', function () {
      setPasswordMatchValidity();
      if (touchedFields.has(field)) {
        updateFieldFeedback(field);
      }

      if (field.dataset.validation === 'password') {
        var confirmPassword = form.querySelector('[data-validation="confirm-password"]');
        if (confirmPassword && touchedFields.has(confirmPassword)) {
          updateFieldFeedback(confirmPassword);
        }
      }
    });

    field.addEventListener('change', function () {
      if (touchedFields.has(field)) {
        updateFieldFeedback(field);
      }
    });
  });

  form.addEventListener('invalid', function (event) {
    var field = event.target;
    if (!field.matches('[data-validation]')) {
      return;
    }

    touchedFields.add(field);
    setPasswordMatchValidity();
    updateFieldFeedback(field);
  }, true);

  /* ---------- Two-step navigation ---------- */
  var steps = form.querySelectorAll('.step');

  if (steps.length === 2) {
    var nextButton = form.querySelector('[data-next]');
    var backButton = form.querySelector('[data-back]');

    function showStep(index) {
      for (var i = 0; i < steps.length; i++) {
        steps[i].hidden = i !== index;
      }

      var first = steps[index].querySelector('input:not([type="hidden"])');
      if (first) {
        first.focus();
      }

      window.scrollTo(0, 0);
    }

    function validateStep(step) {
      var firstInvalid = null;

      step.querySelectorAll('[data-validation]').forEach(function (field) {
        touchedFields.add(field);
        setPasswordMatchValidity();
        updateFieldFeedback(field);

        if (!field.validity.valid && !firstInvalid) {
          firstInvalid = field;
        }
      });

      if (firstInvalid) {
        firstInvalid.focus();
        return false;
      }

      return true;
    }

    function goNext() {
      if (validateStep(steps[0])) {
        showStep(1);
      }
    }

    if (nextButton) {
      nextButton.addEventListener('click', goNext);
    }

    if (backButton) {
      backButton.addEventListener('click', function () {
        showStep(0);
      });
    }

    // Pressing Enter on step 1 moves forward instead of submitting.
    steps[0].addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
        event.preventDefault();
        goNext();
      }
    });

    // Safety net: never submit while step 1 is showing.
    form.addEventListener('submit', function (event) {
      if (!steps[0].hidden) {
        event.preventDefault();
        goNext();
      }
    });
  }
});

document.querySelectorAll('.card-switch a').forEach(function (link) {
  link.addEventListener('click', function (event) {
    var destination = new URL(link.href, window.location.href);
    var isSignupPage = /\/register\.php$/.test(destination.pathname);
    var isModifiedClick = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;

    if (
      !isSignupPage ||
      destination.origin !== window.location.origin ||
      destination.href === window.location.href ||
      link.target === '_blank' ||
      isModifiedClick ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
      return;
    }

    event.preventDefault();
    document.body.classList.add('page-leaving');
    window.setTimeout(function () {
      window.location.href = destination.href;
    }, 200);
  });
});

(function () {
  var dataElement = document.getElementById('policyData');
  var modal = document.getElementById('policyModal');
  var body = document.getElementById('policyModalBody');
  var title = document.getElementById('policyModalTitle');
  var pageLink = document.getElementById('policyModalPageLink');
  var lastFocused = null;

  if (!dataElement || !modal || !body || !title || !pageLink) {
    return;
  }

  var policies;

  try {
    policies = JSON.parse(dataElement.textContent);
  } catch (error) {
    return;
  }

  function renderPolicy(policy) {
    title.textContent = policy.title;

    body.innerHTML = '';

    policy.sections.forEach(function (section) {
      var block = document.createElement('section');
      var heading = document.createElement('h3');
      var paragraph = document.createElement('p');

      heading.textContent = section[0];
      paragraph.textContent = section[1];

      block.appendChild(heading);
      block.appendChild(paragraph);
      body.appendChild(block);
    });
  }

  function openPolicy(slug) {
    var policy = policies[slug];

    if (!policy) {
      return;
    }

    renderPolicy(policy);
    pageLink.href = '../pages/' + slug + '.php';

    lastFocused = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('modal-open');

    var closeButton = modal.querySelector('.policy-modal-close');
    if (closeButton) {
      closeButton.focus();
    }
  }

  function closePolicy() {
    modal.hidden = true;
    document.body.classList.remove('modal-open');

    if (lastFocused) {
      lastFocused.focus();
    }
  }

  document.querySelectorAll('.policy-link').forEach(function (button) {
    button.addEventListener('click', function () {
      openPolicy(button.dataset.policy);
    });
  });

  modal.querySelectorAll('[data-policy-close]').forEach(function (element) {
    element.addEventListener('click', closePolicy);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      closePolicy();
    }
  });
})();