<?php require_once __DIR__ . '/version.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TechShift POS</title>
    <style>
        @font-face {
            font-family: "Inter";
            src: url("/pos/assets/fonts/InterVariable.woff2") format("woff2-variations");
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            background: #0A0A0A;
            color: #FAFAFA;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        .splash {
            width: 100%;
            max-width: 520px;
            padding: 48px 40px;
            text-align: center;
        }
        .mark {
            width: 56px; height: 56px;
            margin: 0 auto 20px;
            background: #FAFAFA;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: #0A0A0A;
            font-size: 26px; font-weight: 600;
            letter-spacing: -0.02em;
        }
        h1 {
            font-size: 24px; font-weight: 600;
            letter-spacing: -0.01em;
            color: #FAFAFA;
            margin-bottom: 6px;
        }
        .kicker {
            font-size: 12px; font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #A3A3A3;
            margin-bottom: 28px;
        }
        .welcome {
            font-size: 13px;
            color: #A3A3A3;
            margin-bottom: 40px;
        }
        .track {
            height: 2px;
            background: #262626;
            border-radius: 2px;
            overflow: hidden;
            width: 240px;
            margin: 0 auto 14px;
        }
        .bar {
            height: 100%;
            width: 0%;
            background: #FAFAFA;
            transition: width .35s ease;
        }
        .status {
            font-size: 12px;
            color: #737373;
            min-height: 18px;
        }
        .foot {
            display: flex;
            justify-content: space-between;
            margin-top: 56px;
            font-size: 11px;
            color: #525252;
        }
        .fade-out { opacity: 0; transition: opacity .2s ease; }
    </style>
</head>
<body>
    <div class="splash" id="splash">
        <div class="mark">T</div>
        <h1>TechShift POS</h1>
        <div class="kicker">Point of sale</div>
        <p class="welcome">Welcome. Getting your store ready.</p>

        <div class="track"><div class="bar" id="bar"></div></div>
        <p class="status" id="status">Loading your store</p>

        <div class="foot">
            <span>v<?php echo htmlspecialchars(APP_VERSION); ?></span>
            <span>&copy; <?php echo date('Y'); ?> TechShift</span>
        </div>
    </div>

    <script>
        var stages = [
            { pct: 40, text: 'Loading your store' },
            { pct: 80, text: 'Preparing your dashboard' },
            { pct: 100, text: 'Ready' }
        ];
        var bar = document.getElementById('bar');
        var status = document.getElementById('status');
        var splash = document.getElementById('splash');
        var i = 0;

        function next() {
            if (i >= stages.length) {
                setTimeout(function () {
                    splash.classList.add('fade-out');
                    setTimeout(function () { window.location.href = 'splash_redirect.php'; }, 200);
                }, 250);
                return;
            }
            var s = stages[i++];
            bar.style.width = s.pct + '%';
            status.textContent = s.text;
            setTimeout(next, 380);
        }
        setTimeout(next, 200);
    </script>
</body>
</html>
