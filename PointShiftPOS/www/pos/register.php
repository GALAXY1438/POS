<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    // Ensure business_type is passed through to register
    $data['business_type'] = trim($_POST['business_type'] ?? '');

    $ctrl   = new AuthController();
    $result = $ctrl->register($data);
    
    // Debug logging
    error_log("Registration result: " . print_r($result, true));
    
    if ($result['success']) {
        if (isset($result['needs_verification']) && $result['needs_verification']) {
            // Redirect to email verification
            $_SESSION['verify_email'] = $result['email'];
            $_SESSION['verify_user_id'] = $result['user_id'];
            header("Location: verify_email.php?email=" . urlencode($result['email']) . "&user_id=" . $result['user_id']);
            exit();
        }
        $success = $result['message'];
    } else {
        $error = $result['message'];
    }
}

// Restore posted values for sticky form
$v = [
    'first_name'    => htmlspecialchars($_POST['first_name']    ?? ''),
    'last_name'     => htmlspecialchars($_POST['last_name']     ?? ''),
    'username'      => htmlspecialchars($_POST['username']      ?? ''),
    'email'         => htmlspecialchars($_POST['email']         ?? ''),
    'business_type' => htmlspecialchars($_POST['business_type'] ?? ''),
];

$business_types = [
    ['value' => 'retail',         'label' => 'Retail Store',    'icon' => 'M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z M9 22V12h6v10'],
    ['value' => 'pharmacy',       'label' => 'Pharmacy',        'icon' => 'M12 2a4 4 0 014 4v1h1a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V9a2 2 0 012-2h1V6a4 4 0 014-4z M9 14h6 M12 11v6'],
    ['value' => 'restaurant',     'label' => 'Restaurant',      'icon' => 'M18 8h1a4 4 0 010 8h-1 M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z M6 1v3 M10 1v3 M14 1v3'],
    ['value' => 'boutique',       'label' => 'Boutique',        'icon' => 'M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z M3 6h18 M16 10a4 4 0 01-8 0'],
    ['value' => 'wine_spirits',   'label' => 'Wine & Spirits',  'icon' => 'M8 22l4-11 4 11 M5 8c0 4.418 3.134 8 7 8s7-3.582 7-8'],
    ['value' => 'hardware',       'label' => 'Hardware',        'icon' => 'M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z'],
    ['value' => 'club_bar',       'label' => 'Club / Bar',      'icon' => 'M9 18V5l12-2v13 M6 21a3 3 0 100-6 3 3 0 000 6z M18 19a3 3 0 100-6 3 3 0 000 6z'],
    ['value' => 'supermarket',    'label' => 'Supermarket',     'icon' => 'M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z M3 6h18 M12 12H8 M16 16H8 M16 8H8'],
    ['value' => 'salon',          'label' => 'Salon / Spa',     'icon' => 'M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z'],
    ['value' => 'electronics',    'label' => 'Electronics',     'icon' => 'M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18'],
    ['value' => 'coffee',         'label' => 'Café / Coffee',   'icon' => 'M18 8h1a4 4 0 010 8h-1 M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z'],
    ['value' => 'other',          'label' => 'Other',           'icon' => 'M12 2a10 10 0 100 20A10 10 0 0012 2z M12 8v4 M12 16h.01'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account — <?php echo SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100vh;
            display: flex; align-items: flex-start; justify-content: center;
            padding: 32px 20px;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 100%; max-width: 480px;
            padding: 32px 32px 24px;
            box-shadow: var(--shadow);
            animation: rise .3s ease;
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* ── Brand ── */
        .brand { text-align: center; margin-bottom: 24px; }
        .brand-mark {
            width: 48px; height: 48px; border-radius: var(--radius-lg);
            background: var(--primary); color: var(--primary-contrast);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 600; margin-bottom: 12px;
        }
        .brand h1 { font-size: var(--fs-20); font-weight: 600; letter-spacing: -.01em; color: var(--text); margin: 0 0 4px; }
        .brand p  { font-size: var(--fs-13); color: var(--text-muted); margin: 0; }

        hr { border: none; border-top: 1px solid var(--border); margin: 0 0 20px; }

        /* ── Flash messages ── */
        .msg {
            border-radius: var(--radius); padding: 9px 12px; font-size: var(--fs-13);
            margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
        }
        .msg.error   { color: var(--danger); }
        .msg.success { color: var(--success); }
        .msg.success a { color: inherit; font-weight: 600; }

        /* ── Section heading ── */
        .section-label {
            font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); margin: 20px 0 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .section-label::after {
            content: ''; flex: 1;
            border-top: 1px solid var(--border);
        }

        /* ── Two-col grid ── */
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        /* ── Field ── */
        .field { margin-bottom: 14px; }
        .field label {
            display: block; font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); margin-bottom: 6px;
        }

        /* ── Input wrapper ── */
        .iw { position: relative; display: flex; align-items: center; }
        .iw .li {
            position: absolute; left: 11px; top: 50%;
            transform: translateY(-50%);
            font-size: 15px; color: var(--text-subtle);
            pointer-events: none; line-height: 1;
        }
        .iw input {
            width: 100%; height: 36px;
            padding: 0 40px 0 34px;
            border: 1px solid var(--border); border-radius: var(--radius);
            font-size: var(--fs-13); color: var(--text); background: var(--surface);
            outline: none; font-family: var(--font);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .iw input.nr { padding-right: 12px; } /* no right button */
        .iw input::placeholder { color: var(--text-subtle); }
        .iw input:focus { border-color: var(--text); box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text); }

        /* eye toggle */
        .iw .eye {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 0;
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-subtle); font-size: 15px; line-height: 1;
            transition: color .15s ease;
        }
        .iw .eye:hover { color: var(--text); }
        .hint { font-size: var(--fs-12); color: var(--text-subtle); margin-top: 4px; }

        /* ── Business type grid ── */
        .biz-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 4px;
        }
        .biz-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 6px 10px;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s ease, background-color .15s ease;
            background: var(--surface);
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .biz-card:hover { border-color: var(--border-strong); }
        .biz-card.selected {
            border-color: var(--text);
            background: var(--text);
        }
        .biz-card.selected .biz-icon svg { stroke: var(--text-on-primary); }
        .biz-card.selected .biz-name { color: var(--text-on-primary); }
        .biz-icon {
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 6px; height: 28px;
        }
        .biz-icon svg {
            width: 22px; height: 22px;
            stroke: var(--text-muted);
            fill: none;
            stroke-width: 1.75;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: stroke .15s ease;
        }
        .biz-name {
            font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); line-height: 1.25;
            transition: color .15s ease;
        }

        /* ── Submit button ── */
        .btn-submit {
            width: 100%; height: 40px;
            background: var(--primary); color: var(--primary-contrast);
            border: 1px solid var(--primary); border-radius: var(--radius);
            font-size: var(--fs-14); font-weight: 500;
            cursor: pointer; font-family: var(--font);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background-color .15s ease; margin-top: 6px;
        }
        .btn-submit:hover  { background: var(--primary-hover); }
        .btn-submit:active { transform: scale(.98); }

        /* ── OR divider ── */
        .or-divider {
            display: flex; align-items: center; gap: 10px;
            margin: 18px 0 14px;
            font-size: var(--fs-12); color: var(--text-subtle); font-weight: 500;
        }
        .or-divider::before, .or-divider::after {
            content: ''; flex: 1; border-top: 1px solid var(--border);
        }

        /* ── Social buttons — icon only ── */
        .social-grid {
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;
        }
        .btn-social {
            height: 40px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--surface); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: border-color .15s ease, background-color .15s ease; padding: 0;
        }
        .btn-social svg { display: block; width: 20px; height: 20px; flex-shrink: 0; }
        .btn-social:hover { border-color: var(--border-strong); background: var(--surface-2); }
        .btn-social:active { transform: scale(.97); }

        /* ── Sign in row ── */
        .signin-row {
            margin-top: 20px; padding-top: 16px;
            border-top: 1px solid var(--border);
            text-align: center; font-size: var(--fs-13); color: var(--text-muted);
        }
        .signin-row a { color: var(--text); font-weight: 500; text-decoration: none; }
        .signin-row a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">

    <!-- Brand -->
    <div class="brand">
        <div class="brand-mark"><i class="fas fa-circle-dot"></i></div>
        <h1><?php echo SITE_NAME; ?></h1>
        <p>Create your account</p>
    </div>

    <hr>

    <!-- Flash messages -->
    <?php if ($error): ?>
    <div class="msg error">
        <i class="fas fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="msg success">
        <i class="fas fa-circle-check"></i>
        <?php echo htmlspecialchars($success); ?> &nbsp;<a href="login.php">Sign in →</a>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="" autocomplete="off" id="regForm">
        <!-- Hidden field for selected business type -->
        <input type="hidden" name="business_type" id="businessTypeInput" value="<?php echo $v['business_type']; ?>" required>

        <!-- ── Name row ── -->
        <div class="row2">
            <div class="field">
                <label>First name</label>
                <div class="iw">
                    <i class="fas fa-user li"></i>
                    <input type="text" name="first_name" class="nr" placeholder="John"
                           value="<?php echo $v['first_name']; ?>" required autofocus autocomplete="given-name">
                </div>
            </div>
            <div class="field">
                <label>Last name</label>
                <div class="iw">
                    <i class="fas fa-user li"></i>
                    <input type="text" name="last_name" class="nr" placeholder="Doe"
                           value="<?php echo $v['last_name']; ?>" required autocomplete="family-name">
                </div>
            </div>
        </div>

        <!-- ── Username + Email ── -->
        <div class="row2">
            <div class="field">
                <label>Username</label>
                <div class="iw">
                    <i class="fas fa-at li"></i>
                    <input type="text" name="username" class="nr" placeholder="johndoe"
                           value="<?php echo $v['username']; ?>" required autocomplete="username">
                </div>
            </div>
            <div class="field">
                <label>Email</label>
                <div class="iw">
                    <i class="fas fa-envelope li"></i>
                    <input type="email" name="email" class="nr" placeholder="john@email.com"
                           value="<?php echo $v['email']; ?>" required autocomplete="email">
                </div>
            </div>
        </div>

        <!-- ── Password row ── -->
        <div class="row2">
            <div class="field">
                <label>Password</label>
                <div class="iw">
                    <i class="fas fa-lock li"></i>
                    <input type="password" id="pw1" name="password" placeholder="min. 6 chars"
                           required autocomplete="new-password">
                    <button type="button" class="eye" data-target="pw1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="hint">At least 6 characters</div>
            </div>
            <div class="field">
                <label>Confirm</label>
                <div class="iw">
                    <i class="fas fa-lock li"></i>
                    <input type="password" id="pw2" name="confirm_password" placeholder="repeat"
                           required autocomplete="new-password">
                    <button type="button" class="eye" data-target="pw2">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Business type ── -->
        <div class="section-label">Business type</div>

        <div class="biz-grid" id="bizGrid">
            <?php foreach ($business_types as $bt): ?>
            <div class="biz-card <?php echo $v['business_type'] === $bt['value'] ? 'selected' : ''; ?>"
                 data-value="<?php echo $bt['value']; ?>"
                 title="<?php echo $bt['label']; ?>">
                <div class="biz-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="<?php echo $bt['icon']; ?>"/>
                    </svg>
                </div>
                <div class="biz-name"><?php echo $bt['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Submit ── -->
        <button type="submit" class="btn-submit" style="margin-top:18px;">
            Create account <i class="fas fa-arrow-right"></i>
        </button>

    </form>

    <!-- OR social -->
    <div class="or-divider">Or sign up with</div>
    <div class="social-grid">
        <button class="btn-social" type="button" title="Sign up with Google" onclick="socialSignup('google')">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
        </button>
        <button class="btn-social" type="button" title="Sign up with Facebook" onclick="socialSignup('facebook')">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.235 2.686.235v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.269h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" fill="#1877F2"/>
            </svg>
        </button>
        <button class="btn-social" type="button" title="Sign up with X" onclick="socialSignup('x')">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.747l7.73-8.835L1.254 2.25H8.08l4.253 5.622 5.911-5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z" fill="#000"/>
            </svg>
        </button>
    </div>
    <?php endif; ?>

    <div class="signin-row">
        Already have an account? <a href="login.php">Sign in</a>
    </div>

</div><!-- /card -->

<script>
    /* ── Eye toggle ── */
    document.querySelectorAll('.eye').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var input = document.getElementById(btn.getAttribute('data-target'));
            var icon  = btn.querySelector('i');
            if (!input || !icon) return;
            var show   = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
            input.focus();
        });
    });

    /* ── Business type card selector ── */
    (function () {
        var hiddenInput = document.getElementById('businessTypeInput');
        var cards       = document.querySelectorAll('.biz-card');

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                // deselect all
                cards.forEach(function (c) { c.classList.remove('selected'); });
                // select this
                card.classList.add('selected');
                hiddenInput.value = card.getAttribute('data-value');
            });
        });

        /* Validate business type on submit */
        var form = document.getElementById('regForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!hiddenInput.value) {
                    e.preventDefault();
                    // highlight grid
                    var grid = document.getElementById('bizGrid');
                    grid.style.outline = '2px solid var(--danger)';
                    grid.style.outlineOffset = '4px';
                    grid.style.borderRadius = '10px';
                    grid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(function () {
                        grid.style.outline = '';
                        grid.style.outlineOffset = '';
                    }, 2000);
                }
            });
        }
    })();

    function socialSignup(provider) {
        alert('Sign up with ' + provider.charAt(0).toUpperCase() + provider.slice(1) + ' — connect your OAuth credentials to activate.');
    }
</script>

</body>
</html>
