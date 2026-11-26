<?php
/**
 * TechShift POS — licence key generator (internal TechShift tool).
 * NOT shipped to clients. Run with the portable PHP:
 *
 *   php licence.php machine
 *   php licence.php issue --type=perpetual  --name="Wajiku Stores" --machine=ABCD-1234-EF56-7890 --id=TS-2026-001
 *   php licence.php issue --type=subscription --name="Wajiku Stores" --machine=ABCD-... --days=30 --id=TS-2026-007
 *   php licence.php verify TS-...
 *
 * Private key: --key=path or env TSPOS_LICENSE_PRIVATE_KEY
 * Default: D:\wajiku stores\license-keys\license_private.pem
 */

const DEFAULT_KEY = __DIR__ . '/../signing/license_private.pem';
const PUBLIC_KEY  = __DIR__ . '/../signing/license_public.pem';

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64urlDecode(string $value): string|false
{
    $value = strtr(trim($value), '-_', '+/');
    $pad   = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($value, true);
}

function arg(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

function machineId(): string
{
    $uuid = '';
    if (stripos(PHP_OS, 'WIN') === 0 && function_exists('shell_exec')) {
        $uuid = trim((string)@shell_exec(
            'powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID"'
        ));
        if (preg_match('/^[0-9a-fA-F-]{20,}$/', $uuid) !== 1) {
            $uuid = '';
        }
    }
    if ($uuid === '') {
        $uuid = (string)(getenv('COMPUTERNAME') ?: gethostname());
    }
    $raw  = strtolower($uuid . '|' . (string)(getenv('COMPUTERNAME') ?: gethostname()));
    $hash = strtoupper(substr(hash('sha256', $raw), 0, 16));
    return implode('-', str_split($hash, 4));
}

function sign(array $payload, string $privateKeyPath): string
{
    $pem = @file_get_contents($privateKeyPath);
    if ($pem === false) {
        fail("private key not found at $privateKeyPath");
    }
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        fail('private key could not be loaded: ' . openssl_error_string());
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig  = '';
    if (!openssl_sign($json, $sig, $key, OPENSSL_ALGO_SHA256)) {
        fail('signing failed: ' . openssl_error_string());
    }
    return 'TS-' . b64url($json) . '.' . b64url($sig);
}

function verify(string $licenseKey): array
{
    $licenseKey = preg_replace('/\s+/', '', $licenseKey);
    if (!str_starts_with($licenseKey, 'TS-')) {
        return ['ok' => false, 'error' => 'missing TS- prefix'];
    }
    $body = substr($licenseKey, 3);
    $dot  = strrpos($body, '.');
    if ($dot === false) {
        return ['ok' => false, 'error' => 'missing signature separator'];
    }
    $json = b64urlDecode(substr($body, 0, $dot));
    $sig  = b64urlDecode(substr($body, $dot + 1));
    if ($json === false || $sig === false) {
        return ['ok' => false, 'error' => 'bad base64'];
    }
    $pem = @file_get_contents(PUBLIC_KEY);
    if ($pem === false) {
        return ['ok' => false, 'error' => 'public key not found at ' . PUBLIC_KEY];
    }
    if (openssl_verify($json, $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'error' => 'signature does not match'];
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload is not JSON'];
    }
    return ['ok' => true, 'payload' => $payload];
}

// ── Dispatch ─────────────────────────────────────────────────────────────────

$command = $argv[1] ?? 'help';

if ($command === 'machine') {
    echo machineId(), PHP_EOL;
    exit(0);
}

if ($command === 'verify') {
    $key = $argv[2] ?? arg('key', '');
    if (!$key) {
        fail('usage: licence.php verify TS-...');
    }
    $result = verify($key);
    if (!$result['ok']) {
        echo "INVALID: {$result['error']}\n";
        exit(1);
    }
    $p = $result['payload'];
    echo "VALID\n";
    echo "  type     : {$p['t']}\n";
    echo "  customer : {$p['c']}\n";
    echo "  key id   : {$p['id']}\n";
    echo "  machine  : {$p['m']}\n";
    echo "  issued   : {$p['i']}\n";
    echo "  expires  : " . (($p['t'] === 'perpetual') ? 'never' : ($p['exp'] ?? '?')) . "\n";
    exit(0);
}

if ($command !== 'issue') {
    echo <<<TXT
TechShift POS licence tool

  php licence.php machine
      Print this PC's machine ID.

  php licence.php issue --type=perpetual --name="Customer" --machine=ID [--id=TS-2026-001]
  php licence.php issue --type=subscription --name="Customer" --machine=ID --days=30
      --days=N or --exp=YYYY-MM-DD (default 30 days), --out=file.txt to save the key.

  php licence.php verify TS-...
      Check a key against the public key.

TXT;
    exit(0);
}

$type    = (string)arg('type', '');
$name    = (string)arg('name', '');
$machine = strtoupper((string)arg('machine', ''));
$id      = (string)arg('id', '');
$days    = (int)(arg('days', '0') ?: 0);
$exp     = (string)arg('exp', '');
$out     = (string)arg('out', '');
$keyPath = (string)(arg('key', '') ?: (getenv('TSPOS_LICENSE_PRIVATE_KEY') ?: DEFAULT_KEY));

if (!in_array($type, ['perpetual', 'subscription'], true)) {
    fail('--type must be perpetual or subscription');
}
if ($name === '') {
    fail('--name is required');
}
if (!preg_match('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $machine)) {
    fail('--machine must look like XXXX-XXXX-XXXX-XXXX');
}

if ($type === 'subscription' && $exp === '') {
    $days = $days > 0 ? $days : 30;
    $exp  = date('Y-m-d', strtotime('+' . $days . ' days'));
}
if ($type === 'subscription') {
    $ts = strtotime($exp);
    if ($ts === false) {
        fail('--exp must be YYYY-MM-DD');
    }
    if ($ts < strtotime('today')) {
        fail('--exp is in the past');
    }
    $exp = date('Y-m-d', $ts);
}

if ($id === '') {
    $id = 'TS-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

$payload = [
    'v' => 1,
    'id' => $id,
    'c' => $name,
    't' => $type,
    'exp' => $type === 'subscription' ? $exp : '9999-12-31',
    'm' => $machine,
    'i' => date('Y-m-d'),
];

$licenseKey = sign($payload, $keyPath);

$check = verify($licenseKey);
if (!$check['ok']) {
    fail('self-check failed: ' . $check['error']);
}

$typeLabel = $type === 'perpetual' ? 'One-time (perpetual) licence' : 'Subscription licence';
$expiryLine = $type === 'perpetual' ? 'Never' : date('j M Y', strtotime($payload['exp']));

$block = <<<TXT
TechShift POS — Licence details
--------------------------------
Customer    : {$name}
Key ID      : {$id}
Type        : {$typeLabel}
Expires     : {$expiryLine}
Machine ID  : {$machine}
Issued      : {$payload['i']}

Licence key (paste this into the activation screen):

{$licenseKey}

This key is bound to the machine ID above and will not work on another PC.
TXT;

echo $block, PHP_EOL;

if ($out !== '') {
    if (@file_put_contents($out, $block . PHP_EOL) === false) {
        fail("could not write $out");
    }
    echo "Saved to: $out\n";
}