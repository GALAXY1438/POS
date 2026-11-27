<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$error = '';
$success = '';

if (isset($_GET['badge_error'])) {
    $error = $_GET['badge_error'] === 'locked'
        ? 'Too many sign-in attempts. Wait a few minutes, then try again.'
        : 'That badge was not recognised. Try again or sign in with your password.';
}

// Check for flash messages
if (isset($_SESSION['login_message'])) {
    $success = $_SESSION['login_message'];
    unset($_SESSION['login_message']);
}
if (isset($_SESSION['login_message_type'])) {
    $type = $_SESSION['login_message_type'];
    unset($_SESSION['login_message_type']);
}
if (isset($_GET['restored'])) {
    $success = 'Backup restored. Sign in with an account from that backup.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ctrl   = new AuthController();
    $result = $ctrl->login(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? ''
    );
    if ($result['success']) {
        // Two-factor: send a code and hold the user on the verification page.
        $tfa = TwoFactor::startForCurrentUser();
        if ($tfa['started']) {
            $_SESSION['tfa_notice'] = $tfa['message'];
            header('Location: verify_2fa.php');
            exit();
        }
        // Straight to the correct dashboard — no artificial loading screen.
        header('Location: splash_redirect.php');
        exit();
    } elseif (isset($result['needs_verification']) && $result['needs_verification']) {
        // Redirect to email verification
        $_SESSION['verify_email'] = $result['email'];
        $_SESSION['verify_user_id'] = $result['user_id'];
        header("Location: verify_email.php?email=" . urlencode($result['email']) . "&user_id=" . $result['user_id']);
        exit();
    }
    $error = $result['message'];
}

$fingerprintReady = false;
try { $fingerprintReady = PosSecurity::fingerprintEnrolled(); }
catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 400px;
            padding: 32px;
        }
        .brand { text-align: center; margin-bottom: 24px; }
        .brand-mark {
            width: 48px; height: 48px;
            border-radius: 12px;
            background: var(--primary);
            color: var(--primary-contrast);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 600;
            margin-bottom: 14px;
        }
        .brand h1 {
            font-size: var(--fs-20); font-weight: 600;
            letter-spacing: -.01em; color: var(--text);
            margin: 0 0 4px;
        }
        .brand p { font-size: var(--fs-13); color: var(--text-muted); margin: 0; }

        .error, .success {
            border: 1px solid var(--border);
            border-left: 3px solid var(--danger);
            border-radius: var(--radius);
            padding: 9px 12px;
            font-size: var(--fs-13);
            color: var(--danger);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
            background: var(--surface-2);
        }
        .success { border-left-color: var(--success); color: var(--success); }

        .field { margin-bottom: 16px; }
        .field label {
            display: block; font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); margin-bottom: 6px;
        }
        .iw { position: relative; display: flex; align-items: center; }
        .iw .left-icon {
            position: absolute; left: 11px; top: 50%;
            transform: translateY(-50%);
            font-size: 15px; color: var(--text-subtle);
            pointer-events: none; line-height: 1;
        }
        .iw input {
            width: 100%; height: 36px;
            padding: 0 40px 0 34px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: var(--fs-13);
            color: var(--text);
            background: var(--surface);
            outline: none;
            font-family: var(--font);
            transition: border-color .15s ease, box-shadow .15s ease;
            line-height: 1;
        }
        .iw input::placeholder { color: var(--text-subtle); }
        .iw input:focus {
            border-color: var(--text);
            box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text);
        }
        .iw .eye-btn {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            padding: 0; width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-subtle); font-size: 15px; line-height: 1;
            transition: color .15s ease;
        }
        .iw .eye-btn:hover { color: var(--text); }
        .iw input.no-right { padding-right: 12px; }

        .btn-signin {
            width: 100%; height: 40px;
            background: var(--primary); color: var(--primary-contrast);
            border: 1px solid var(--primary); border-radius: var(--radius);
            font-size: var(--fs-14); font-weight: 500;
            cursor: pointer; font-family: var(--font);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background-color .15s ease;
            margin-top: 4px;
        }
        .btn-signin:hover { background: var(--primary-hover); }

        .links-row {
            display: flex; align-items: center;
            justify-content: flex-end;
            margin-top: 14px;
            font-size: var(--fs-13);
        }
        .links-row a { color: var(--text-muted) !important; }
        .links-row a:hover { color: var(--text) !important; }

        .signup-row {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: var(--fs-13); color: var(--text-muted);
        }
        .signup-row a { font-weight: 500; }

        /* ── Alternative sign-in (fingerprint / badge) ── */
        .alt-divider {
            display: flex; align-items: center; gap: 10px;
            margin: 18px 0 12px; color: var(--text-subtle);
            font-size: var(--fs-12); text-transform: uppercase; letter-spacing: .06em;
        }
        .alt-divider::before, .alt-divider::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }
        .alt-btn {
            width: 100%; height: 38px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--surface); color: var(--text);
            border: 1px solid var(--border); border-radius: var(--radius);
            font-size: var(--fs-13); font-weight: 500; font-family: var(--font);
            cursor: pointer; transition: border-color .15s ease;
        }
        .alt-btn:hover { border-color: var(--text-subtle); }
        .alt-btn:disabled { opacity: .55; cursor: wait; }
        .alt-btn + .alt-btn { margin-top: 8px; }
        .alt-msg { font-size: var(--fs-12); color: var(--danger); margin: 8px 0 0; min-height: 15px; }
        #badgePanel { margin-top: 10px; display: none; }
        #badgePanel.open { display: block; }
        #badgePanel .iw input { padding-left: 34px; padding-right: 12px; }
        .badge-hint { font-size: 11px; color: var(--text-subtle); margin: 6px 0 0; }
    </style>
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
</head>
<body>

<div class="card">

    <!-- Brand -->
    <div class="brand">
        <div class="brand-mark">T</div>
        <h1><?php echo SITE_NAME; ?></h1>
        <p>Sign in to continue</p>
    </div>

    <!-- Error message -->
    <?php if ($error): ?>
    <div class="error">
        <i class="fas fa-circle-xmark"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Success message -->
    <?php if ($success): ?>
    <div class="success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <!-- Login form -->
    <form method="POST" action="" autocomplete="off" id="loginForm">

        <div class="field">
            <label for="username">Username or email</label>
            <div class="iw">
                <i class="fas fa-user left-icon"></i>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="no-right"
                    placeholder="Username or email"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    required
                    autofocus
                    autocomplete="username"
                >
            </div>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="iw">
                <i class="fas fa-lock left-icon"></i>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    required
                    autocomplete="current-password"
                >
                <button type="button" class="eye-btn" id="eyeBtn" aria-label="Show password">
                    <i class="fas fa-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-signin">
            Sign in
        </button>

    </form>

    <!-- Alternative sign-in -->
    <div class="alt-divider">or</div>

    <button type="button" class="alt-btn" id="fpLoginBtn">
        <i class="fas fa-fingerprint"></i>
        <?php echo $fingerprintReady ? 'Sign in with fingerprint' : 'Set up fingerprint (sign in first)'; ?>
    </button>

    <button type="button" class="alt-btn" id="badgeToggleBtn">
        <i class="fas fa-barcode"></i> Sign in with badge / barcode
    </button>

    <form method="POST" action="login_secure.php" id="badgePanel">
        <input type="hidden" name="action" value="badge">
        <div class="iw">
            <i class="fas fa-id-card left-icon"></i>
            <input type="text" name="badge_code" id="badgeCode" placeholder="Scan or type badge code"
                   autocomplete="off">
        </div>
        <p class="badge-hint">Point the barcode scanner at your staff badge — it signs in automatically.</p>
    </form>

    <p class="alt-msg" id="fpMsg"></p>

    <!-- Forgot / Register links -->
    <div class="links-row">
        <a href="forgot_password.php">Forgot password?</a>
    </div>

    <!-- Sign up footer -->
    <div class="signup-row">
        Don't have an account? <a href="register.php">Create one</a>
    </div>

</div><!-- /card -->

<script>
    /* ── Password visibility toggle ── */
    (function () {
        var btn   = document.getElementById('eyeBtn');
        var input = document.getElementById('password');
        var icon  = document.getElementById('eyeIcon');

        if (!btn || !input || !icon) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var show = (input.type === 'password');
            input.type      = show ? 'text' : 'password';
            icon.className  = show ? 'fas fa-eye-slash' : 'fas fa-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    })();

    /* ── Badge / barcode sign-in ── */
    (function () {
        var toggle = document.getElementById('badgeToggleBtn');
        var panel  = document.getElementById('badgePanel');
        var input  = document.getElementById('badgeCode');
        if (!toggle || !panel || !input) return;

        toggle.addEventListener('click', function () {
            panel.classList.toggle('open');
            if (panel.classList.contains('open')) input.focus();
        });

        panel.addEventListener('submit', function (e) {
            if (!input.value.trim()) { e.preventDefault(); input.focus(); }
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (input.value.trim()) panel.submit();
            }
        });
    })();

    /* ── Fingerprint sign-in (WebAuthn) ── */
    (function () {
        var btn = document.getElementById('fpLoginBtn');
        var msg = document.getElementById('fpMsg');
        var ready = <?php echo $fingerprintReady ? 'true' : 'false'; ?>;
        if (!btn) return;

        function b64uToBuf(s) {
            s = s.replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4) s += '=';
            var bin = atob(s), buf = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
            return buf.buffer;
        }
        function bufToB64u(buf) {
            var bytes = new Uint8Array(buf), bin = '';
            for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }
        function fail(text) {
            btn.disabled = false;
            msg.textContent = text;
        }

        btn.addEventListener('click', function () {
            msg.textContent = '';
            if (!ready) {
                msg.textContent = 'Sign in with your password once, then enroll a fingerprint in Settings → Security.';
                return;
            }
            if (!window.PublicKeyCredential || !navigator.credentials) {
                fail('This browser does not support fingerprint sign-in.');
                return;
            }
            if (!window.isSecureContext) {
                fail('Fingerprint needs http://localhost or HTTPS. Use badge sign-in instead.');
                return;
            }
            btn.disabled = true;
            fetch('login_secure.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'fp_begin' })
            }).then(function (r) { return r.json(); }).then(function (begin) {
                if (!begin.success) throw new Error(begin.message || 'Fingerprint could not start.');
                return navigator.credentials.get({
                    publicKey: {
                        challenge: b64uToBuf(begin.challenge),
                        rpId: begin.rpId,
                        allowCredentials: (begin.allowCredentials || []).map(function (c) {
                            return { type: c.type, id: b64uToBuf(c.id) };
                        }),
                        timeout: begin.timeout || 60000,
                        userVerification: 'required'
                    }
                });
            }).then(function (cred) {
                if (!cred) throw new Error('No fingerprint was returned.');
                return fetch('login_secure.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fp_finish',
                        credential_id: bufToB64u(cred.rawId),
                        client_data: bufToB64u(cred.response.clientDataJSON),
                        authenticator_data: bufToB64u(cred.response.authenticatorData),
                        signature: bufToB64u(cred.response.signature)
                    })
                });
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    fail((data && data.message) || 'Fingerprint verification failed.');
                }
            }).catch(function (e) {
                var text = e && e.message ? e.message : 'Fingerprint verification failed.';
                if (e && e.name === 'NotAllowedError') text = 'Fingerprint was cancelled.';
                fail(text);
            });
        });
    })();
</script>

</body>
</html>
