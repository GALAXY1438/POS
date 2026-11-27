<?php
/* In-app confirm/alert dialog. Include once per page, after Bootstrap JS.
   Usage: posConfirm('Message', {ok:'Delete', danger:true}).then(ok => ...)
          posAlert('Message')
          <form data-confirm="..."> / <a data-confirm="..." href="...">  */
if (defined('POS_CONFIRM_PARTIAL')) { return; }
define('POS_CONFIRM_PARTIAL', true);
?>
<div class="modal fade" id="posConfirmDialog" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
<div class="modal-content" style="border-radius:12px;border:1px solid var(--border-color);">
    <div class="modal-body" style="padding:20px 20px 16px;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <div class="pc-icon"><i class="fas fa-circle-info" id="pcIcon"></i></div>
            <div style="flex:1;min-width:0;">
                <div id="pcTitle" style="font-weight:600;font-size:.92rem;color:var(--text-primary);">Are you sure?</div>
                <div id="pcMsg" style="font-size:.82rem;color:var(--text-secondary);margin-top:4px;"></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="pcCancel">Cancel</button>
            <button type="button" class="btn btn-dark btn-sm" id="pcOk">Confirm</button>
        </div>
    </div>
</div>
</div>
</div>

<style>
#posConfirmDialog .pc-icon {
    width: 36px; height: 36px; border-radius: 10px; flex: 0 0 36px;
    background: var(--surface-2); display: flex; align-items: center; justify-content: center;
    color: var(--text-primary); font-size: .9rem;
}
#posConfirmDialog .pc-icon.danger { color: var(--danger); }
#posConfirmDialog #pcOk.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
#posConfirmDialog #pcCancel.pc-hide { display: none !important; }
</style>

<script>
(function () {
    'use strict';
    if (window.__posDialogReady) return;
    window.__posDialogReady = true;

    var root   = document.getElementById('posConfirmDialog');
    if (!root) return;
    var titleEl = document.getElementById('pcTitle');
    var msgEl   = document.getElementById('pcMsg');
    var iconEl  = document.getElementById('pcIcon');
    var iconBox = root.querySelector('.pc-icon');
    var okBtn   = document.getElementById('pcOk');
    var cancelBtn = document.getElementById('pcCancel');
    var modal   = null;
    var resolveFn = null;

    function getModal() {
        if (!modal) modal = new bootstrap.Modal(root);
        return modal;
    }

    function finish(ok) {
        var fn = resolveFn;
        resolveFn = null;
        try { getModal().hide(); } catch (e) {}
        if (fn) fn(!!ok);
    }

    root.addEventListener('hidden.bs.modal', function () {
        if (resolveFn) finish(false);
    });

    function ask(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            resolveFn = resolve;
            titleEl.textContent = opts.title || (opts.alert ? 'Notice' : 'Are you sure?');
            msgEl.textContent = message || '';
            msgEl.style.display = message ? '' : 'none';
            okBtn.textContent = opts.ok || (opts.alert ? 'OK' : 'Confirm');
            okBtn.classList.toggle('danger', !!opts.danger);
            iconBox.classList.toggle('danger', !!opts.danger);
            iconEl.className = 'fas ' + (opts.alert ? 'fa-circle-info' : (opts.danger ? 'fa-triangle-exclamation' : 'fa-circle-question'));
            cancelBtn.classList.toggle('pc-hide', !!opts.alert);
            getModal().show();
        });
    }

    var newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    okBtn = newOk;
    okBtn.addEventListener('click', function () { finish(true); });
    cancelBtn.addEventListener('click', function () { finish(false); });

    window.posConfirm = window.posConfirm || function (message, opts) { return ask(message, opts); };
    window.posAlert   = window.posAlert   || function (message, opts) {
        return ask(message, Object.assign({ alert: true, ok: 'OK' }, opts || {}));
    };

    // Declarative: <form data-confirm="..."> and <a data-confirm="..." href="...">
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        var msg = form.getAttribute('data-confirm');
        if (!msg || form.getAttribute('data-confirmed') === '1') return;
        e.preventDefault();
        ask(msg, {
            ok: form.getAttribute('data-confirm-ok') || 'Confirm',
            danger: form.hasAttribute('data-confirm-danger')
        }).then(function (ok) {
            if (!ok) return;
            form.setAttribute('data-confirmed', '1');
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
            // If validation blocked the submit, ask again next time.
            setTimeout(function () { form.removeAttribute('data-confirmed'); }, 1000);
        });
    }, true);

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-confirm]') : null;
        if (!a) return;
        e.preventDefault();
        ask(a.getAttribute('data-confirm'), {
            ok: a.getAttribute('data-confirm-ok') || 'Confirm',
            danger: a.hasAttribute('data-confirm-danger')
        }).then(function (ok) {
            if (ok) window.location.href = a.href;
        });
    }, true);
})();
</script>
