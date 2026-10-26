/* UniStay confirmation modal.
   Usage:
     const ok = await UniStayConfirm.open({
       crumb: 'UniStay • Booking',
       title: 'Cancel your stay at Dorm Name?',
       message: 'Your booking will be cancelled.',
       confirmText: 'Yes, cancel stay',
       cancelText: 'Keep my stay',
       danger: true
     });
     if (!ok) return;
*/
(function () {
    'use strict';

    var dlg = null;
    var resolver = null;
    var closing = false;
    var els = {};

    function build() {
        dlg = document.createElement('dialog');
        dlg.className = 'us-confirm';
        dlg.setAttribute('aria-labelledby', 'usConfirmTitle');
        dlg.setAttribute('aria-describedby', 'usConfirmMsg');
        dlg.innerHTML =
            '<div class="us-confirm-panel" role="alertdialog" aria-modal="true">' +
            '<header class="us-confirm-header">' +
            '<div class="us-confirm-title-group">' +
            '<p class="us-confirm-crumb" id="usConfirmCrumb"></p>' +
            '<h2 id="usConfirmTitle"></h2>' +
            '</div>' +
            '<button type="button" class="us-confirm-close" data-us-cancel aria-label="Close">&times;</button>' +
            '</header>' +
            '<div class="us-confirm-body">' +
            '<section class="us-confirm-card"><p id="usConfirmMsg"></p></section>' +
            '</div>' +
            '<footer class="us-confirm-footer">' +
            '<button type="button" class="us-confirm-btn-secondary" id="usConfirmCancel" data-us-cancel></button>' +
            '<button type="button" class="us-confirm-btn-primary" id="usConfirmOk"></button>' +
            '</footer>' +
            '</div>';
        document.body.appendChild(dlg);

        els.crumb = dlg.querySelector('#usConfirmCrumb');
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
        }, reduce ? 0 : 220);
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

            els.crumb.textContent = opts.crumb || 'UniStay \u2022 Please confirm';
            els.title.textContent = opts.title || 'Are you sure?';
            els.msg.textContent = opts.message || '';
            els.cancel.textContent = opts.cancelText || 'Cancel';
            els.ok.textContent = opts.confirmText || 'Confirm';
            els.ok.classList.toggle('is-danger', !!opts.danger);

            dlg.showModal();
            els.cancel.focus();
        });
    }

    window.UniStayConfirm = { open: open };
})();