(function () {
    'use strict';

    var dlg = null;
    var resolver = null;
    var closing = false;
    var els = {};

    var DANGER_ICON = '<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    var DEFAULT_ICON = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

    function build() {
        dlg = document.createElement('dialog');
        dlg.className = 'us-confirm';
        dlg.setAttribute('aria-labelledby', 'usConfirmTitle');
        dlg.setAttribute('aria-describedby', 'usConfirmMsg');
        dlg.innerHTML =
            '<div class="us-confirm-panel" role="alertdialog" aria-modal="true">' +
                '<button type="button" class="us-confirm-close" data-us-cancel aria-label="Close">&times;</button>' +
                '<div class="us-confirm-icon" id="usConfirmIcon" aria-hidden="true">' + DEFAULT_ICON + '</div>' +
                '<h2 id="usConfirmTitle"></h2>' +
                '<p id="usConfirmMsg"></p>' +
                '<div class="us-confirm-actions">' +
                    '<button type="button" class="us-confirm-btn-secondary" id="usConfirmCancel" data-us-cancel></button>' +
                    '<button type="button" class="us-confirm-btn-primary" id="usConfirmOk"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(dlg);

        els.icon = dlg.querySelector('#usConfirmIcon');
        els.title = dlg.querySelector('#usConfirmTitle');
        els.msg = dlg.querySelector('#usConfirmMsg');
        els.cancel = dlg.querySelector('#usConfirmCancel');
        els.ok = dlg.querySelector('#usConfirmOk');

        dlg.addEventListener('click', function (e) {
            if (e.target === dlg || e.target.closest('[data-us-cancel]')) finish(false);
        });
        els.ok.addEventListener('click', function () { finish(true); });
        dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(false); });
    }

    function finish(result) {
        if (!dlg || !dlg.open || closing) return;
        closing = true;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        dlg.classList.add('is-closing');
        setTimeout(function () {
            dlg.close();
            dlg.classList.remove('is-closing');
            closing = false;
            var r = resolver;
            resolver = null;
            if (r) r(result);
        }, reduce ? 0 : 200);
    }

    function open(opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            if (typeof HTMLDialogElement === 'undefined') {
                resolve(window.confirm((opts.title || 'Are you sure?') + '\n\n' + (opts.message || '')));
                return;
            }
            if (!dlg) build();
            if (resolver) { resolver(false); resolver = null; }
            if (dlg.open) { dlg.close(); }
            dlg.classList.remove('is-closing');
            closing = false;
            resolver = resolve;

            els.title.textContent = opts.title || 'Please confirm';
            els.msg.textContent = opts.message || '';
            els.cancel.textContent = opts.cancelText || 'Cancel';
            els.ok.textContent = opts.confirmText || 'Confirm';

            var isDanger = !!opts.danger;
            els.ok.classList.toggle('is-danger', isDanger);
            els.icon.classList.toggle('is-danger', isDanger);
            els.icon.innerHTML = isDanger ? DANGER_ICON : DEFAULT_ICON;

            dlg.showModal();
            els.cancel.focus();
        });
    }

    window.UniStayConfirm = { open: open };
})();
