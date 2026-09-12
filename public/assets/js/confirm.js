/* กล่องยืนยันกลางของระบบ — ดูคำอธิบายการใช้งานใน resources/views/_partials/confirm.latte */
(function () {
    'use strict';

    var modal, titleEl, textEl, markEl, okBtn, pending = null, lastFocus = null;

    function ready() {
        modal = document.getElementById('confirmModal');
        if (!modal) { return false; }
        titleEl = document.getElementById('confirmTitle');
        textEl = document.getElementById('confirmText');
        markEl = document.getElementById('confirmMark');
        okBtn = document.getElementById('confirmOk');

        return true;
    }

    function close(answer) {
        modal.hidden = true;
        var resolve = pending;
        pending = null;
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        if (resolve) { resolve(answer); }
    }

    window.rvcConfirm = function (options) {
        var opts = options || {};

        return new Promise(function (resolve) {
            if (!modal && !ready()) {
                resolve(window.confirm(opts.message || 'ยืนยันการทำรายการ?'));

                return;
            }

            pending = resolve;
            lastFocus = document.activeElement;
            titleEl.textContent = opts.title || 'ยืนยันการทำรายการ';
            textEl.textContent = opts.message || '';
            okBtn.textContent = opts.confirmText || 'ยืนยัน';
            markEl.textContent = opts.danger ? '!' : '?';
            markEl.classList.toggle('is-danger', !!opts.danger);
            okBtn.classList.toggle('btn-danger', !!opts.danger);
            okBtn.classList.toggle('btn-primary', !opts.danger);
            modal.hidden = false;
            okBtn.focus();
        });
    };

    document.addEventListener('click', function (event) {
        if (!modal) { return; }
        if (event.target === modal || event.target.closest('[data-confirm-cancel]')) { close(false); }
        if (event.target.closest('#confirmOk')) { close(true); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) { close(false); }
    });

    /* ฟอร์มที่ติด data-confirm ไว้ จะถูกถามก่อนส่งเสมอ */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed === '1') { return; }

        event.preventDefault();
        window.rvcConfirm({
            message: message,
            title: form.getAttribute('data-confirm-title') || undefined,
            confirmText: form.getAttribute('data-confirm-ok') || undefined,
            danger: form.hasAttribute('data-confirm-danger')
        }).then(function (ok) {
            if (!ok) { return; }
            form.dataset.confirmed = '1';
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        });
    }, true);
})();
