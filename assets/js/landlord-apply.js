(function () {
    var dialog = document.getElementById('landlord-dialog');
    var form = document.getElementById('landlord-form');
    if (!dialog || !form) return;

    var msg = document.getElementById('apply-msg');
    var btn = document.getElementById('apply-btn');
    var cancelBtn = form.querySelector('.apply-actions [data-close-landlord-form]');
    var submittedSuccessfully = false;
    var isSubmitting = false;
    var isClosing = false;
    var closeTimer = null;

    function show(text, state) { msg.textContent = text; msg.className = 'apply-msg ' + (state || ''); }

    function openDialog() {
        if (dialog.open || isClosing) return;
        show('');
        submittedSuccessfully = false;
        document.body.classList.add('landlord-dialog-open');
        dialog.showModal();
        // Force the initial hidden state to render before transitioning in.
        void dialog.offsetWidth;
        dialog.classList.add('is-open');
        var first = form.querySelector('input:not([type="hidden"])');
        if (first) setTimeout(function () { first.focus(); }, 0);
    }

    function closeDialog() {
        if (!dialog.open || isClosing || isSubmitting) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            dialog.close();
            return;
        }
        isClosing = true;
        dialog.classList.remove('is-open');
        closeTimer = window.setTimeout(function () {
            closeTimer = null;
            dialog.close();
        }, 220);
    }

    document.querySelectorAll('[data-open-landlord-form]').forEach(function (el) {
        el.addEventListener('click', openDialog);
    });
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href="#landlord-application"]');
        if (!link) return;
        e.preventDefault();
        openDialog();
    });
    if (window.location.hash === '#landlord-application') {
        openDialog();
    }
    document.querySelectorAll('[data-close-landlord-form]').forEach(function (el) {
        el.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('cancel', function (e) {
        e.preventDefault();
        closeDialog();
    });
    dialog.addEventListener('click', function (e) {
        if (e.target !== dialog || isSubmitting) return;
        var rect = dialog.getBoundingClientRect();
        var inside = e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom;
        if (!inside) closeDialog();
    });
    dialog.addEventListener('close', function () {
        if (closeTimer !== null) window.clearTimeout(closeTimer);
        closeTimer = null;
        isClosing = false;
        dialog.classList.remove('is-open');
        document.body.classList.remove('landlord-dialog-open');
        if (submittedSuccessfully) {
            form.reset();
            Array.prototype.forEach.call(form.elements, function (field) { field.disabled = false; });
            btn.textContent = 'Submit Application';
            if (cancelBtn) cancelBtn.textContent = 'Cancel';
            submittedSuccessfully = false;
        }
    });

    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (isSubmitting || e.submitter !== btn) return;
        var data = new FormData(form);

        if (!form.checkValidity()) {
            show('Please fill in all required fields with valid details.', 'error');
            form.reportValidity();
            return;
        }

        isSubmitting = true;
        btn.disabled = true;
        btn.textContent = 'Submitting...';
        fetch('api/landlord-apply.php', {
            method: 'POST',
            body: data
        })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                if (!res || res.ok === false) { show((res && res.error) || 'Something went wrong. Please try again.', 'error'); return; }
                show('Application sent! We will email you once the admin has reviewed it.', 'success');
                submittedSuccessfully = true;
                Array.prototype.forEach.call(form.elements, function (field) {
                    if (!field.hasAttribute('data-close-landlord-form')) field.disabled = true;
                });
                btn.textContent = 'Application Sent';
                if (cancelBtn) cancelBtn.textContent = 'Close';
            })
            .catch(function () { show('Could not reach the server. Please try again.', 'error'); })
            .then(function () {
                isSubmitting = false;
                if (!submittedSuccessfully) {
                    btn.disabled = false;
                    btn.textContent = 'Submit Application';
                }
            });
    });
})();
