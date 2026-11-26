<?php
declare(strict_types=1);

/**
 * TechShift Licence Manager — local-only key issuing GUI.
 * Run with "Start Licence Manager.bat". Binds to 127.0.0.1 only.
 * This folder belongs to the private keystore and is never shipped.
 */

session_name('ts_licence_manager');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lm_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function lm_require_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request. Reload the page and try again.');
    }
}

function lm_pin_hash(): ?string
{
    $raw = @file_get_contents(LM_PIN_FILE);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    return trim($raw);
}

/**
 * @return array{error?:string,payload?:array,key?:string,file?:string,prior?:int}
 */
function lm_issue(array $post): array
{
    $customer = trim((string)($post['customer'] ?? ''));
    $machine  = lm_normalise_machine((string)($post['machine'] ?? ''));
    $type     = (string)($post['type'] ?? '');
    $id       = strtoupper(trim((string)($post['key_id'] ?? '')));
    $notes    = trim((string)($post['notes'] ?? ''));
    $days     = (int)($post['days'] ?? 0);
    $expIn    = trim((string)($post['exp'] ?? ''));

    if ($customer === '') {
        return ['error' => 'Enter the client or shop name.'];
    }
    if ($machine === null) {
        return ['error' => 'The machine ID needs 16 hex characters, like 9E21-3DE6-C779-C015.'];
    }
    if (!in_array($type, ['perpetual', 'subscription'], true)) {
        return ['error' => 'Choose a licence type.'];
    }
    if ($id === '') {
        $id = lm_next_key_id();
    }
    if (preg_match('/^[A-Za-z0-9._-]{3,40}$/', $id) !== 1) {
        return ['error' => 'The key ID may only use letters, numbers, dots, dashes and underscores.'];
    }
    if (lm_record_exists($customer, $id)) {
        return ['error' => 'Key ID ' . $id . ' already exists for this customer. Use another.'];
    }
    foreach (lm_records() as $record) {
        if ($record['id'] === $id) {
            return ['error' => 'Key ID ' . $id . ' is already used by ' . $record['customer'] . '. Use another.'];
        }
    }

    $exp = '9999-12-31';
    if ($type === 'subscription') {
        if ($expIn !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $expIn);
            if (!$date || $date->format('Y-m-d') !== $expIn) {
                return ['error' => 'The expiry date must be a real date.'];
            }
            if ($expIn < date('Y-m-d')) {
                return ['error' => 'The expiry date is in the past.'];
            }
            $exp = $expIn;
        } else {
            if ($days < 1 || $days > 3650) {
                return ['error' => 'Enter a subscription length between 1 and 3650 days.'];
            }
            $exp = date('Y-m-d', strtotime('+' . $days . ' days'));
        }
    }

    $payload = [
        'v'   => 1,
        'id'  => $id,
        'c'   => $customer,
        't'   => $type,
        'exp' => $exp,
        'm'   => $machine,
        'i'   => date('Y-m-d'),
    ];

    try {
        $key = lm_sign($payload);
        $check = lm_check($key);
        if (!$check['ok']) {
            return ['error' => 'Self-check failed: ' . ($check['error'] ?? 'unknown error')];
        }
        $file = lm_save_record($payload, $key, $notes);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    $prior = 0;
    foreach (lm_records() as $record) {
        if ($record['machine'] === $machine && $record['id'] !== $id) {
            $prior++;
        }
    }

    return ['payload' => $payload, 'key' => $key, 'file' => $file, 'prior' => $prior];
}

$pinHash = lm_pin_hash();
$authed = !empty($_SESSION['authed']);
$error = '';
$notice = '';
$verify = null;
$form = [
    'customer' => '',
    'machine'  => '',
    'type'     => 'subscription',
    'days'     => '365',
    'exp'      => '',
    'key_id'   => '',
    'notes'    => '',
];

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'setup_pin') {
        if ($pinHash !== null) {
            $error = 'A PIN is already set. Sign in instead.';
        } else {
            lm_require_csrf();
            $pin = (string)($_POST['pin'] ?? '');
            $pin2 = (string)($_POST['pin2'] ?? '');
            if (strlen($pin) < 4) {
                $error = 'Use at least 4 characters.';
            } elseif ($pin !== $pin2) {
                $error = 'The two PINs do not match.';
            } elseif (!lm_save_pin($pin)) {
                $error = 'Could not save the PIN file in keystore\gui-data.';
            } else {
                $_SESSION['authed'] = true;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    } elseif ($action === 'login') {
        lm_require_csrf();
        $pin = (string)($_POST['pin'] ?? '');
        if ($pinHash === null) {
            $error = 'No PIN is set yet. Reload the page to set one.';
        } elseif (!password_verify($pin, $pinHash)) {
            sleep(1);
            $error = 'Wrong PIN.';
        } else {
            session_regenerate_id(true);
            $_SESSION['authed'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    } elseif ($action === 'change_pin') {
        if (!$authed) {
            http_response_code(403);
            exit('Not signed in.');
        }
        lm_require_csrf();
        $current = (string)($_POST['current_pin'] ?? '');
        $pin = (string)($_POST['new_pin'] ?? '');
        $pin2 = (string)($_POST['new_pin2'] ?? '');
        if ($pinHash === null || !password_verify($current, $pinHash)) {
            $error = 'The current PIN is wrong.';
        } elseif (strlen($pin) < 4) {
            $error = 'Use at least 4 characters.';
        } elseif ($pin !== $pin2) {
            $error = 'The new PINs do not match.';
        } elseif (!lm_save_pin($pin)) {
            $error = 'Could not save the new PIN.';
        } else {
            $notice = 'PIN changed.';
        }
    } elseif ($action === 'issue') {
        if (!$authed) {
            http_response_code(403);
            exit('Not signed in.');
        }
        lm_require_csrf();
        $result = lm_issue($_POST);
        if (isset($result['error'])) {
            $error = $result['error'];
            foreach (array_keys($form) as $field) {
                if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
                    $form[$field] = (string)$_POST[$field];
                }
            }
        } else {
            $_SESSION['flash_issue'] = $result;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?issued=1');
            exit;
        }
    } elseif ($action === 'verify') {
        if (!$authed) {
            http_response_code(403);
            exit('Not signed in.');
        }
        lm_require_csrf();
        $verify = lm_check((string)($_POST['key'] ?? ''));
    }
}

$issued = null;
if (!empty($_SESSION['flash_issue'])) {
    $issued = $_SESSION['flash_issue'];
    unset($_SESSION['flash_issue']);
}

$records = [];
$stats = ['total' => 0, 'perpetual' => 0, 'subscription' => 0, 'expiring' => 0, 'expired' => 0];
$search = '';
if ($authed) {
    if ($form['key_id'] === '') {
        $form['key_id'] = lm_next_key_id();
    }
    $search = trim((string)($_GET['q'] ?? ''));
    foreach (lm_records() as $record) {
        $status = lm_status($record);
        $stats['total']++;
        if ($record['type'] === 'perpetual') {
            $stats['perpetual']++;
        } else {
            $stats['subscription']++;
            if ($status['label'] === 'Expired') {
                $stats['expired']++;
            } elseif ($status['class'] === 'warn') {
                $stats['expiring']++;
            }
        }
        if ($search !== '') {
            $hay = strtolower($record['customer'] . ' ' . $record['id'] . ' ' . $record['machine'] . ' ' . $record['type']);
            if (!str_contains($hay, strtolower($search))) {
                continue;
            }
        }
        $record['status'] = $status;
        $records[] = $record;
    }
}

if ($authed && isset($_GET['download'], $_GET['id'], $_GET['c'])) {
    $id = (string)$_GET['id'];
    $customer = (string)$_GET['c'];
    foreach (lm_records() as $record) {
        if ($record['id'] === $id && $record['customer'] === $customer) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $id) . '.txt"');
            readfile($record['file']);
            exit;
        }
    }
    http_response_code(404);
    exit('Record not found.');
}

function lm_type_label(string $type): string
{
    return $type === 'perpetual' ? 'One-time' : 'Subscription';
}

function lm_expiry_label(array $record): string
{
    if (($record['type'] ?? '') === 'perpetual') {
        return 'Never';
    }
    $ts = strtotime((string)($record['exp'] ?? ''));
    return $ts ? date('j M Y', $ts) : (string)($record['exp'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Licence Manager — TechShift POS</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
        --bg: #f6f6f7; --surface: #ffffff; --border: #e4e4e7; --border-strong: #d4d4d8;
        --text: #18181b; --muted: #71717a; --subtle: #a1a1aa;
        --primary: #18181b; --primary-text: #fafafa;
        --ok-bg: #ecfdf5; --ok-text: #065f46;
        --warn-bg: #fffbeb; --warn-text: #92400e;
        --bad-bg: #fef2f2; --bad-text: #991b1b;
    }
    body {
        font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5;
    }
    .bar {
        background: var(--surface); border-bottom: 1px solid var(--border);
        padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .mark {
        width: 34px; height: 34px; border-radius: 8px; background: var(--primary); color: var(--primary-text);
        display: flex; align-items: center; justify-content: center; font-weight: 600;
    }
    .brand strong { display: block; font-size: 15px; }
    .brand span { display: block; font-size: 12px; color: var(--muted); }
    .wrap { max-width: 1080px; margin: 0 auto; padding: 24px; display: grid; gap: 18px; }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
    .card h2 { font-size: 15px; margin-bottom: 14px; }
    .card h3 { font-size: 13px; margin-bottom: 8px; }
    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
    .stat b { display: block; font-size: 22px; }
    .stat span { font-size: 12px; color: var(--muted); }
    label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 12px; }
    label strong { color: var(--text); font-weight: 500; }
    input[type=text], input[type=password], input[type=number], input[type=date], textarea, select {
        width: 100%; margin-top: 5px; padding: 9px 11px; border: 1px solid var(--border-strong);
        border-radius: 8px; font: inherit; color: var(--text); background: var(--surface);
    }
    textarea { resize: vertical; font-family: "Cascadia Mono", Consolas, monospace; font-size: 12px; }
    input:focus, textarea:focus, select:focus { outline: 2px solid #a1a1aa33; border-color: #a1a1aa; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    .row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 16px; }
    fieldset { border: 0; }
    .types { display: flex; gap: 10px; margin: 4px 0 14px; }
    .types label {
        flex: 1; margin: 0; border: 1px solid var(--border-strong); border-radius: 10px; padding: 10px 12px;
        cursor: pointer; display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: var(--muted);
    }
    .types input { margin-top: 3px; }
    .types b { display: block; color: var(--text); font-size: 13px; font-weight: 500; }
    .hint { font-size: 12px; color: var(--muted); margin-top: 6px; }
    .preview { font-size: 12px; color: var(--text); background: var(--bg); border-radius: 8px; padding: 8px 10px; }
    button, .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        height: 38px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--primary);
        background: var(--primary); color: var(--primary-text); font: inherit; font-size: 13px;
        font-weight: 500; cursor: pointer; text-decoration: none;
    }
    .btn.ghost, button.ghost { background: var(--surface); color: var(--text); border-color: var(--border-strong); }
    .btn.small, button.small { height: 28px; padding: 0 10px; font-size: 12px; border-radius: 6px; }
    .actions { display: flex; gap: 10px; align-items: center; margin-top: 6px; flex-wrap: wrap; }
    .flash { border-radius: 10px; padding: 10px 14px; font-size: 13px; }
    .flash.bad { background: var(--bad-bg); color: var(--bad-text); border: 1px solid #fecaca; }
    .flash.ok { background: var(--ok-bg); color: var(--ok-text); border: 1px solid #a7f3d0; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 9px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 500; }
    td.mono, .mono { font-family: "Cascadia Mono", Consolas, monospace; font-size: 12px; }
    .chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; }
    .chip.ok { background: var(--ok-bg); color: var(--ok-text); }
    .chip.warn { background: var(--warn-bg); color: var(--warn-text); }
    .chip.bad { background: var(--bad-bg); color: var(--bad-text); }
    .chip.neutral { background: var(--bg); color: var(--muted); }
    .sub { display: block; font-size: 11px; color: var(--subtle); margin-top: 2px; }
    .cardhead { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
    .cardhead h2 { margin: 0; }
    .search input { margin: 0; width: 240px; }
    .empty { color: var(--muted); font-size: 13px; padding: 12px 0; }
    .result .big { font-family: "Cascadia Mono", Consolas, monospace; font-size: 12px; word-break: break-all; background: var(--bg); border-radius: 8px; padding: 10px; }
    details summary { cursor: pointer; font-size: 13px; }
    details form { margin-top: 12px; }
    .auth { max-width: 420px; margin: 80px auto; }
    .auth .card { padding: 26px; }
    .auth h1 { font-size: 18px; margin-bottom: 4px; }
    .auth p { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
    .toolrow { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; align-items: start; }
    .verify-ok { background: var(--ok-bg); border-radius: 8px; padding: 10px; font-size: 12px; margin-top: 10px; }
    .verify-bad { background: var(--bad-bg); border-radius: 8px; padding: 10px; font-size: 12px; margin-top: 10px; }
    .foot { text-align: center; font-size: 11px; color: var(--subtle); padding: 8px 0 24px; }
    @media (max-width: 760px) {
        .row2, .row3, .toolrow, .stats { grid-template-columns: 1fr; }
        .types { flex-direction: column; }
    }
</style>
</head>
<body>
<header class="bar">
    <div class="brand">
        <div class="mark">T</div>
        <div>
            <strong>TechShift Licence Manager</strong>
            <span>Private tool — records save to keystore\issued</span>
        </div>
    </div>
    <?php if ($authed): ?>
        <a class="btn ghost small" href="?logout=1">Sign out</a>
    <?php endif; ?>
</header>

<?php if ($pinHash === null): ?>
<main class="auth">
    <div class="card">
        <h1>First run</h1>
        <p>Set a PIN to protect key issuing. You can change it later inside the manager.</p>
        <?php if ($error): ?><div class="flash bad" style="margin-bottom:12px;"><?php echo h($error); ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="setup_pin">
            <input type="hidden" name="csrf" value="<?php echo h(lm_csrf()); ?>">
            <label><strong>New PIN</strong> (at least 4 characters)
                <input type="password" name="pin" autofocus required>
            </label>
            <label><strong>Repeat PIN</strong>
                <input type="password" name="pin2" required>
            </label>
            <button type="submit">Set PIN and continue</button>
        </form>
    </div>
    <p class="foot">Only reachable from this computer (127.0.0.1).</p>
</main>

<?php elseif (!$authed): ?>
<main class="auth">
    <div class="card">
        <h1>Licence Manager</h1>
        <p>Enter the PIN set for this tool.</p>
        <?php if ($error): ?><div class="flash bad" style="margin-bottom:12px;"><?php echo h($error); ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf" value="<?php echo h(lm_csrf()); ?>">
            <label><strong>PIN</strong>
                <input type="password" name="pin" autofocus required>
            </label>
            <button type="submit">Sign in</button>
        </form>
        <p class="hint">Forgot the PIN? Delete keystore\gui-data\pin.txt and reload to set a new one.</p>
    </div>
</main>

<?php else: ?>
<main class="wrap">
    <?php if ($error): ?><div class="flash bad"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="flash ok"><?php echo h($notice); ?></div><?php endif; ?>

    <section class="stats">
        <div class="stat"><b><?php echo $stats['total']; ?></b><span>Keys issued</span></div>
        <div class="stat"><b><?php echo $stats['perpetual']; ?></b><span>One-time licences</span></div>
        <div class="stat"><b><?php echo $stats['subscription']; ?></b><span>Subscriptions</span></div>
        <div class="stat"><b><?php echo $stats['expiring'] + $stats['expired']; ?></b><span>Need renewing or expired</span></div>
    </section>

    <?php if ($issued): ?>
    <?php
        $recordPath = (string)$issued['file'];
        $root = realpath(dirname(__DIR__));
        $real = realpath($recordPath);
        if ($root !== false && $real !== false) {
            $recordPath = 'keystore' . substr($real, strlen($root));
        }
    ?>
    <section class="card result">
        <h2>Licence issued — send this key to the client</h2>
        <div class="row3" style="margin-bottom:14px;">
            <div><span class="sub">Customer</span><?php echo h($issued['payload']['c']); ?></div>
            <div><span class="sub">Key ID</span><?php echo h($issued['payload']['id']); ?></div>
            <div><span class="sub">Type</span><?php echo h(lm_type_label($issued['payload']['t'])); ?></div>
            <div><span class="sub">Machine ID</span><span class="mono"><?php echo h($issued['payload']['m']); ?></span></div>
            <div><span class="sub">Expires</span><?php echo $issued['payload']['t'] === 'perpetual' ? 'Never' : h(date('j M Y', (int)strtotime($issued['payload']['exp']))); ?></div>
            <div><span class="sub">Saved to</span><?php echo h($recordPath); ?></div>
        </div>
        <div class="big" id="keyOut"><?php echo h($issued['key']); ?></div>
        <div class="actions">
            <button type="button" class="copy" data-target="keyOut">Copy key</button>
            <a class="btn ghost" href="?download=1&amp;id=<?php echo rawurlencode($issued['payload']['id']); ?>&amp;c=<?php echo rawurlencode($issued['payload']['c']); ?>">Download record .txt</a>
        </div>
        <p class="hint">
            Paste the whole key into the client's activation screen. Spaces and line breaks do not matter.
            <?php if ((int)$issued['prior'] > 0): ?>
                <br>This machine already had <?php echo (int)$issued['prior']; ?> key<?php echo (int)$issued['prior'] === 1 ? '' : 's'; ?> on file — check the table below if this is a renewal.
            <?php endif; ?>
        </p>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Issue a licence</h2>
        <form method="post">
            <input type="hidden" name="action" value="issue">
            <input type="hidden" name="csrf" value="<?php echo h(lm_csrf()); ?>">
            <div class="row2">
                <label><strong>Customer / shop name</strong>
                    <input type="text" name="customer" value="<?php echo h($form['customer']); ?>" placeholder="Wajiku Stores - Kutus" required>
                </label>
                <label><strong>Machine ID</strong> (from the activation screen)
                    <input type="text" name="machine" id="machine" value="<?php echo h($form['machine']); ?>" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="19" autocomplete="off" required>
                </label>
            </div>

            <fieldset class="types">
                <label><input type="radio" name="type" value="subscription" <?php echo $form['type'] === 'subscription' ? 'checked' : ''; ?>>
                    <span><b>Subscription</b>Expires on a date; renew each term.</span>
                </label>
                <label><input type="radio" name="type" value="perpetual" <?php echo $form['type'] === 'perpetual' ? 'checked' : ''; ?>>
                    <span><b>One-time (lifetime)</b>Never expires, no renewal.</span>
                </label>
            </fieldset>

            <div id="subFields">
                <div class="row2">
                    <label><strong>Length in days</strong>
                        <input type="number" name="days" id="days" min="1" max="3650" value="<?php echo h($form['days']); ?>">
                    </label>
                    <label><strong>Or exact expiry date</strong> (wins over days)
                        <input type="date" name="exp" id="expDate" value="<?php echo h($form['exp']); ?>">
                    </label>
                </div>
            </div>

            <div class="row3">
                <label><strong>Key ID</strong>
                    <input type="text" name="key_id" value="<?php echo h($form['key_id']); ?>" placeholder="<?php echo h(lm_next_key_id()); ?>">
                </label>
                <label><strong>Notes</strong> (optional, invoice or phone)
                    <input type="text" name="notes" value="<?php echo h($form['notes']); ?>" placeholder="Invoice 0142">
                </label>
            </div>

            <p class="preview" id="expPreview"></p>
            <div class="actions" style="margin-top:12px;">
                <button type="submit">Generate licence key</button>
                <span class="hint" style="margin:0;">The key is signed and the record is saved before it is shown.</span>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="cardhead">
            <h2>Issued keys <span class="chip neutral"><?php echo count($records); ?></span></h2>
            <form class="search" method="get">
                <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search client, key ID, machine…">
            </form>
        </div>
        <?php if (!$records): ?>
            <p class="empty"><?php echo $search !== '' ? 'No keys match this search.' : 'No keys issued yet.'; ?></p>
        <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Customer</th>
                <th>Key ID</th>
                <th>Type</th>
                <th>Machine ID</th>
                <th>Issued</th>
                <th>Expires</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?php echo h($record['customer']); ?></td>
                    <td class="mono"><?php echo h($record['id']); ?></td>
                    <td><?php echo h(lm_type_label($record['type'])); ?></td>
                    <td class="mono"><?php echo h($record['machine']); ?></td>
                    <td><?php echo h($record['issued']); ?></td>
                    <td><?php echo h(lm_expiry_label($record)); ?></td>
                    <td>
                        <span class="chip <?php echo h($record['status']['class']); ?>"><?php echo h($record['status']['label']); ?></span>
                        <span class="sub"><?php echo h($record['status']['detail']); ?></span>
                    </td>
                    <td>
                        <div class="actions" style="margin:0;">
                            <button type="button" class="ghost small copy" data-key="<?php echo h($record['key']); ?>">Copy</button>
                            <a class="btn ghost small" href="?download=1&amp;id=<?php echo rawurlencode($record['id']); ?>&amp;c=<?php echo rawurlencode($record['customer']); ?>">.txt</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <div class="toolrow">
        <section class="card">
            <h2>Check a key</h2>
            <form method="post">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="csrf" value="<?php echo h(lm_csrf()); ?>">
                <textarea name="key" rows="3" placeholder="Paste any licence key (TS-…)" required><?php echo $verify !== null ? h((string)($_POST['key'] ?? '')) : ''; ?></textarea>
                <div class="actions" style="margin-top:10px;"><button type="submit" class="ghost">Check signature</button></div>
            </form>
            <?php if ($verify !== null): ?>
                <?php if ($verify['ok']): ?>
                    <?php $p = $verify['payload']; ?>
                    <div class="verify-ok">
                        <b>Signature valid</b><br>
                        <?php echo h((string)($p['c'] ?? '')); ?> · <?php echo h((string)($p['id'] ?? '')); ?><br>
                        <?php echo h(lm_type_label((string)($p['t'] ?? ''))); ?> ·
                        <?php echo ($p['t'] ?? '') === 'perpetual' ? 'never expires' : 'expires ' . h((string)($p['exp'] ?? '')); ?><br>
                        Machine <span class="mono"><?php echo h((string)($p['m'] ?? '')); ?></span>
                    </div>
                <?php else: ?>
                    <div class="verify-bad"><?php echo h($verify['error'] ?? 'Invalid key.'); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <details>
                <summary>Change PIN</summary>
                <form method="post">
                    <input type="hidden" name="action" value="change_pin">
                    <input type="hidden" name="csrf" value="<?php echo h(lm_csrf()); ?>">
                    <label><strong>Current PIN</strong><input type="password" name="current_pin" required></label>
                    <label><strong>New PIN</strong><input type="password" name="new_pin" required></label>
                    <label><strong>Repeat new PIN</strong><input type="password" name="new_pin2" required></label>
                    <button type="submit" class="ghost small">Change PIN</button>
                </form>
            </details>
            <p class="hint" style="margin-top:14px;">
                Every key is saved as a .txt record under <span class="mono">keystore\issued\&lt;Customer&gt;\</span>
                before it is shown. Those records are your backup — keep the keystore folder in the private repo.
            </p>
        </section>
    </div>

    <p class="foot">Runs only on 127.0.0.1:8799 · TechShift POS private tool</p>
</main>

<script>
(function () {
    var machine = document.getElementById('machine');
    if (machine) {
        machine.addEventListener('input', function () {
            var hex = machine.value.toUpperCase().replace(/[^0-9A-F]/g, '').slice(0, 16);
            var groups = hex.match(/.{1,4}/g) || [];
            machine.value = groups.join('-');
        });
    }

    var subFields = document.getElementById('subFields');
    var daysInput = document.getElementById('days');
    var expInput = document.getElementById('expDate');
    var preview = document.getElementById('expPreview');
    var typeInputs = document.querySelectorAll('input[name=type]');

    function syncType() {
        var checked = document.querySelector('input[name=type]:checked');
        var subscription = checked && checked.value === 'subscription';
        if (subFields) { subFields.style.display = subscription ? '' : 'none'; }
        updatePreview();
    }

    function updatePreview() {
        if (!preview) { return; }
        var checked = document.querySelector('input[name=type]:checked');
        if (!checked || checked.value !== 'subscription') {
            preview.textContent = 'One-time licence: the key never expires.';
            return;
        }
        var text = '';
        if (expInput && expInput.value) {
            text = 'Expires on ' + expInput.value;
        } else if (daysInput && parseInt(daysInput.value, 10) > 0) {
            var d = new Date();
            d.setDate(d.getDate() + parseInt(daysInput.value, 10));
            text = 'Expires on ' + d.toISOString().slice(0, 10);
        } else {
            text = 'Enter a length or an expiry date.';
        }
        preview.textContent = text + ' (7-day grace period after expiry).';
    }

    typeInputs.forEach(function (el) { el.addEventListener('change', syncType); });
    if (daysInput) { daysInput.addEventListener('input', updatePreview); }
    if (expInput) { expInput.addEventListener('input', updatePreview); }
    syncType();

    function copyText(text, button) {
        function done() {
            if (!button) { return; }
            var old = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () { button.textContent = old; }, 1200);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
            done();
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('button.copy');
        if (!button) { return; }
        var text = button.getAttribute('data-key');
        if (!text) {
            var target = document.getElementById(button.getAttribute('data-target'));
            text = target ? target.textContent : '';
        }
        if (text) { copyText(text, button); }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
