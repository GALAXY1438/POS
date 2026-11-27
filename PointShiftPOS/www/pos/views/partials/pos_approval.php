<?php
/* Manager approval dialog for POS pages. Included by cashier/pos.php and staff/pos.php. */
if (!class_exists('PosSecurity')) { return; }
$__psCfg = PosSecurity::clientConfig();
?>
<style>
#posApprovalModal .modal-content { border-radius: 12px; border: 1px solid var(--border-color); }
#posApprovalModal .approval-icon {
    width: 44px; height: 44px; border-radius: 12px; background: var(--surface-2);
    display: flex; align-items: center; justify-content: center; color: var(--text-primary);
}
#posApprovalModal .approval-option { border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; }
#posApprovalModal .approval-error { display: none; font-size: .78rem; color: var(--danger); margin-top: 8px; }
#posApprovalModal .approval-hint { font-size: .72rem; color: var(--text-subtle); margin-top: 4px; }
</style>

<div class="modal fade" id="posApprovalModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
<div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
<div class="modal-content">
    <div class="modal-body" style="padding:20px;">
        <div class="d-flex align-items-start gap-3 mb-3">
            <div class="approval-icon"><i class="fas fa-shield"></i></div>
            <div>
                <div style="font-weight:600;font-size:.95rem;">Manager approval required</div>
                <div id="posApprovalReason" style="font-size:.8rem;color:var(--text-secondary);margin-top:2px;">This action needs approval.</div>
            </div>
        </div>

        <div class="approval-option mb-2" id="posApprovalBarcodeWrap">
            <label class="form-label" style="font-size:.78rem;">Scan the manager barcode</label>
            <div class="d-flex gap-2">
                <input type="text" id="posApprovalBarcode" class="form-control form-control-sm" autocomplete="off" placeholder="Scan or type the code, then Enter">
                <button type="button" class="btn btn-dark btn-sm" id="posApprovalBarcodeBtn">Approve</button>
            </div>
            <div class="approval-hint">Use the barcode printed from Settings → Security.</div>
        </div>

        <div class="approval-option" id="posApprovalFpWrap">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div style="font-size:.8rem;font-weight:600;">Fingerprint</div>
                    <div class="approval-hint">The enrolled device fingerprint or Windows Hello PIN.</div>
                </div>
                <button type="button" class="btn btn-dark btn-sm" id="posApprovalFpBtn">Use fingerprint</button>
            </div>
        </div>

        <div class="approval-option mt-2" id="posApprovalOtpWrap">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div style="font-size:.8rem;font-weight:600;">One-time code</div>
                    <div class="approval-hint" id="posApprovalOtpHint">Send a code to the manager's phone or email.</div>
                </div>
                <button type="button" class="btn btn-dark btn-sm" id="posApprovalOtpBtn">Send code</button>
            </div>
            <div id="posApprovalOtpFields" style="display:none;margin-top:10px;">
                <div class="d-flex gap-2">
                    <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" id="posApprovalOtpCode" class="form-control form-control-sm" placeholder="6-digit code">
                    <button type="button" class="btn btn-dark btn-sm" id="posApprovalOtpVerifyBtn">Verify</button>
                </div>
            </div>
            <div class="approval-hint" id="posApprovalOtpMsg"></div>
        </div>

        <div class="approval-error" id="posApprovalError"></div>

        <div class="d-flex justify-content-end mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="posApprovalCancel">Cancel</button>
        </div>
    </div>
</div>
</div>
</div>

<script>
(function () {
    'use strict';
    var CONFIG = <?php echo json_encode($__psCfg); ?>;
    var root = document.getElementById('posApprovalModal');
    if (!root) return;

    var reasonEl = document.getElementById('posApprovalReason');
    var errEl    = document.getElementById('posApprovalError');
    var codeEl   = document.getElementById('posApprovalBarcode');
    var codeBtn  = document.getElementById('posApprovalBarcodeBtn');
    var codeWrap = document.getElementById('posApprovalBarcodeWrap');
    var fpWrap   = document.getElementById('posApprovalFpWrap');
    var fpBtn    = document.getElementById('posApprovalFpBtn');
    var otpWrap  = document.getElementById('posApprovalOtpWrap');
    var otpBtn   = document.getElementById('posApprovalOtpBtn');
    var otpFields = document.getElementById('posApprovalOtpFields');
    var otpCodeEl = document.getElementById('posApprovalOtpCode');
    var otpVerifyBtn = document.getElementById('posApprovalOtpVerifyBtn');
    var otpMsg   = document.getElementById('posApprovalOtpMsg');
    var otpHint  = document.getElementById('posApprovalOtpHint');
    var cancelEl = document.getElementById('posApprovalCancel');
    var modal    = null;
    var resolveFn = null;
    var pendingScope = 'sale';

    var showBarcode = CONFIG.mode !== 'fingerprint' && CONFIG.mode !== 'otp' && CONFIG.barcode;
    var showFp      = CONFIG.mode !== 'barcode' && CONFIG.mode !== 'otp' && CONFIG.fingerprint;
    var showOtp     = (CONFIG.mode === 'either' || CONFIG.mode === 'otp') && CONFIG.otp;
    codeWrap.style.display = showBarcode ? '' : 'none';
    fpWrap.style.display   = showFp ? '' : 'none';
    otpWrap.style.display  = showOtp ? '' : 'none';
    if (showOtp && CONFIG.otpMasked) {
        otpHint.textContent = 'Send a code to ' + CONFIG.otpMasked + '.';
    }

    function showError(msg) {
        if (!errEl) return;
        errEl.textContent = msg || '';
        errEl.style.display = msg ? 'block' : 'none';
    }

    function finish(ok) {
        var fn = resolveFn;
        resolveFn = null;
        if (modal) modal.hide();
        if (fn) fn(!!ok);
    }

    function getModal() {
        if (!modal) modal = new bootstrap.Modal(root);
        return modal;
    }

    root.addEventListener('hidden.bs.modal', function () {
        if (resolveFn) finish(false);
    });

    function openModal(reason) {
        showError('');
        if (codeEl) codeEl.value = '';
        if (otpFields) otpFields.style.display = 'none';
        if (otpCodeEl) otpCodeEl.value = '';
        if (otpMsg) otpMsg.textContent = '';
        reasonEl.textContent = reason || 'This action needs approval.';
        getModal().show();
        if (showBarcode) {
            window.setTimeout(function () { if (codeEl) codeEl.focus(); }, 250);
        }
    }

    function b64uToBuf(v) {
        var s = atob(String(v).replace(/-/g, '+').replace(/_/g, '/'));
        var bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) bytes[i] = s.charCodeAt(i);
        return bytes.buffer;
    }
    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function submitBarcode() {
        var token = (codeEl && codeEl.value || '').trim();
        if (!token) { showError('Scan or type the manager barcode.'); return; }
        codeBtn.disabled = true;
        showError('');
        fetch('/pos/pos_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'barcode', token: token, scope: pendingScope })
        }).then(function (r) { return r.json(); }).then(function (data) {
            codeBtn.disabled = false;
            if (data && data.success) {
                if (pendingScope === 'sale') POS_SECURITY.saleApproved = true;
                else POS_SECURITY.approved = true;
                finish(true);
            } else {
                showError((data && data.message) || 'Approval failed.');
                if (codeEl) { codeEl.value = ''; codeEl.focus(); }
            }
        }).catch(function () {
            codeBtn.disabled = false;
            showError('Approval could not be completed. Check the connection.');
        });
    }

    function useFingerprint() {        if (!window.PublicKeyCredential || !navigator.credentials) {
            showError('This browser does not support fingerprint approval.');
            return;
        }
        if (!window.isSecureContext) {
            showError('Fingerprint needs http://localhost or HTTPS. Use the manager barcode instead.');
            return;
        }
        fpBtn.disabled = true;
        showError('');
        fetch('/pos/security_webauthn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assert_begin' })
        }).then(function (r) { return r.json(); }).then(function (begin) {
            if (!begin.success) throw new Error(begin.message || 'Fingerprint could not start.');
            var pk = {
                challenge: b64uToBuf(begin.challenge),
                rpId: begin.rpId,
                allowCredentials: (begin.allowCredentials || []).map(function (c) {
                    return { type: c.type, id: b64uToBuf(c.id) };
                }),
                timeout: begin.timeout || 60000,
                userVerification: 'required'
            };
            return navigator.credentials.get({ publicKey: pk });
        }).then(function (cred) {
            if (!cred) throw new Error('No fingerprint was returned.');
            return fetch('/pos/security_webauthn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'assert_finish',
                    scope: pendingScope,
                    credential_id: bufToB64u(cred.rawId),
                    client_data: bufToB64u(cred.response.clientDataJSON),
                    authenticator_data: bufToB64u(cred.response.authenticatorData),
                    signature: bufToB64u(cred.response.signature)
                })
            });
        }).then(function (r) { return r.json(); }).then(function (data) {
            fpBtn.disabled = false;
            if (data && data.success) {
                if (pendingScope === 'sale') POS_SECURITY.saleApproved = true;
                else POS_SECURITY.approved = true;
                finish(true);
            } else {
                showError((data && data.message) || 'Fingerprint verification failed.');
            }
        }).catch(function (e) {
            fpBtn.disabled = false;
            var msg = e && e.message ? e.message : 'Fingerprint verification failed.';
            if (e && e.name === 'NotAllowedError') msg = 'Fingerprint was cancelled.';
            showError(msg);
        });
    }

    function sendOtp() {
        otpBtn.disabled = true;
        otpMsg.textContent = 'Sending…';
        fetch('/pos/pos_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'otp_send', scope: pendingScope })
        }).then(function (r) { return r.json(); }).then(function (data) {
            otpBtn.disabled = false;
            if (data && data.success) {
                otpFields.style.display = '';
                otpMsg.textContent = data.message || 'Code sent.';
                if (data.dest) otpHint.textContent = 'Send a code to ' + data.dest + '.';
                if (otpCodeEl) otpCodeEl.focus();
            } else {
                otpMsg.textContent = (data && data.message) || 'The code could not be sent.';
            }
        }).catch(function () {
            otpBtn.disabled = false;
            otpMsg.textContent = 'The code could not be sent. Check the connection.';
        });
    }

    function verifyOtp() {
        var code = (otpCodeEl && otpCodeEl.value || '').replace(/\D/g, '');
        if (!/^\d{6}$/.test(code)) {
            otpMsg.textContent = 'Enter the 6-digit code.';
            return;
        }
        otpVerifyBtn.disabled = true;
        otpMsg.textContent = 'Checking…';
        fetch('/pos/pos_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'otp_verify', code: code, scope: pendingScope })
        }).then(function (r) { return r.json(); }).then(function (data) {
            otpVerifyBtn.disabled = false;
            if (data && data.success) {
                if (pendingScope === 'sale') POS_SECURITY.saleApproved = true;
                else POS_SECURITY.approved = true;
                finish(true);
            } else {
                otpMsg.textContent = (data && data.message) || 'Code not accepted.';
            }
        }).catch(function () {
            otpVerifyBtn.disabled = false;
            otpMsg.textContent = 'Code check failed. Check the connection.';
        });
    }

    codeBtn.addEventListener('click', function () { submitBarcode(); });
    if (codeEl) {
        codeEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitBarcode(); }
        });
    }
    fpBtn.addEventListener('click', function () { useFingerprint(); });
    if (otpBtn) otpBtn.addEventListener('click', function () { sendOtp(); });
    if (otpVerifyBtn) otpVerifyBtn.addEventListener('click', function () { verifyOtp(); });
    if (otpCodeEl) {
        otpCodeEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); verifyOtp(); }
        });
    }
    cancelEl.addEventListener('click', function () { finish(false); });

    window.POS_SECURITY = {
        config: CONFIG,
        approved: false,
        saleApproved: false,
        needsApproval: function (total) {
            return CONFIG.restricted && CONFIG.saleLimit > 0 && Number(total) > CONFIG.saleLimit;
        },
        approve: function (reason, total) {
            if (!showBarcode && !showFp && !showOtp) {
                var msg = 'No approval method is configured. Ask an administrator to enroll a fingerprint, create a barcode or set a manager phone/email for codes.';
                if (typeof window.posAlert === 'function') window.posAlert(msg); else window.alert(msg);
                return Promise.resolve(false);
            }
            pendingScope = reason === 'remove' ? 'remove' : 'sale';
            var text;
            if (pendingScope === 'remove') {
                text = 'A manager must approve removing this item.';
            } else if (total !== undefined && total !== null) {
                text = 'This sale is ' + Number(total).toFixed(2) + ', above the cashier limit. A manager must approve it.';
            } else {
                text = 'This action needs approval.';
            }
            return new Promise(function (resolve) {
                resolveFn = resolve;
                openModal(text);
            });
        },
        reset: function () {
            this.approved = false;
            this.saleApproved = false;
        },
        sync: function () {
            fetch('/pos/pos_approve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'status' })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.success || !d.active) return;
                if (d.scope === 'sale' || d.scope === 'all') POS_SECURITY.saleApproved = true;
                if (d.scope === 'remove' || d.scope === 'all') POS_SECURITY.approved = true;
            }).catch(function () {});
        }
    };

    window.POS_SECURITY.sync();
})();
</script>
