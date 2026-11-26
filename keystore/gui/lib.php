<?php
declare(strict_types=1);

/**
 * Shared signing and storage helpers for the TechShift licence manager.
 * Private tool: lives only in the keystore folder of the private repo.
 * The client app never sees this folder.
 */

const LM_PRIVATE_KEY = __DIR__ . '/../signing/license_private.pem';
const LM_PUBLIC_KEY  = __DIR__ . '/../signing/license_public.pem';
const LM_ISSUED_DIR  = __DIR__ . '/../issued';
const LM_PIN_FILE    = __DIR__ . '/../gui-data/pin.txt';
const LM_GRACE_DAYS  = 7;

function lm_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function lm_b64url_decode(string $value): string|false
{
    $value = strtr(trim($value), '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($value, true);
}

function lm_sign(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode the licence payload.');
    }
    $pem = @file_get_contents(LM_PRIVATE_KEY);
    if ($pem === false) {
        throw new RuntimeException('Private key not found at ' . LM_PRIVATE_KEY);
    }
    if (!openssl_sign($json, $signature, $pem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signing failed: ' . (openssl_error_string() ?: 'unknown error'));
    }
    return 'TS-' . lm_b64url($json) . '.' . lm_b64url($signature);
}

/**
 * Verify signature and payload shape only. Machine binding and expiry are
 * left to the app; this is for self-checking and for the record list.
 *
 * @return array{ok:bool,error?:string,payload?:array}
 */
function lm_check(string $key): array
{
    $key = preg_replace('/\s+/', '', $key);
    if ($key === '' || !str_starts_with($key, 'TS-')) {
        return ['ok' => false, 'error' => 'This is not a TechShift licence key.'];
    }
    $body = substr($key, 3);
    $dot = strrpos($body, '.');
    if ($dot === false) {
        return ['ok' => false, 'error' => 'The key is incomplete.'];
    }
    $json = lm_b64url_decode(substr($body, 0, $dot));
    $signature = lm_b64url_decode(substr($body, $dot + 1));
    if ($json === false || $signature === false) {
        return ['ok' => false, 'error' => 'The key is malformed.'];
    }
    $pem = @file_get_contents(LM_PUBLIC_KEY);
    if ($pem === false) {
        return ['ok' => false, 'error' => 'Public key not found at ' . LM_PUBLIC_KEY];
    }
    if (openssl_verify($json, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'error' => 'The signature does not match TechShift POS.'];
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'The key payload is not valid.'];
    }
    return ['ok' => true, 'payload' => $payload];
}

/** Accept 16 hex characters, with or without dashes. */
function lm_normalise_machine(string $value): ?string
{
    $hex = strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '', $value));
    if (strlen($hex) !== 16) {
        return null;
    }
    return implode('-', str_split($hex, 4));
}

/** Folder-safe customer name; keeps the record readable. */
function lm_folder_name(string $customer): string
{
    $name = (string)preg_replace('/[^A-Za-z0-9 ._()-]+/', ' ', $customer);
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    return $name === '' ? 'Unnamed' : $name;
}

/** @return array<string,string>|null */
function lm_read_record(string $file): ?array
{
    $text = @file_get_contents($file);
    if ($text === false) {
        return null;
    }
    if (preg_match('/^(TS-[A-Za-z0-9._-]+)$/m', $text, $m) !== 1) {
        return null;
    }
    $check = lm_check($m[1]);
    if (!$check['ok']) {
        return null;
    }
    $p = $check['payload'];
    return [
        'file'     => $file,
        'customer' => (string)($p['c'] ?? ''),
        'id'       => (string)($p['id'] ?? ''),
        'type'     => (string)($p['t'] ?? ''),
        'exp'      => (string)($p['exp'] ?? ''),
        'machine'  => (string)($p['m'] ?? ''),
        'issued'   => (string)($p['i'] ?? ''),
        'key'      => $m[1],
    ];
}

/** @return list<array<string,string>> newest first */
function lm_records(): array
{
    $records = [];
    foreach (glob(LM_ISSUED_DIR . '/*/*.txt') ?: [] as $file) {
        $record = lm_read_record($file);
        if ($record !== null) {
            $records[] = $record;
        }
    }
    usort($records, static function (array $a, array $b): int {
        return strcmp($b['issued'] . '|' . $b['id'], $a['issued'] . '|' . $a['id']);
    });
    return $records;
}

/** @return array{label:string,class:string,detail:string} */
function lm_status(array $record): array
{
    if (($record['type'] ?? '') === 'perpetual') {
        return ['label' => 'Active', 'class' => 'ok', 'detail' => 'Never expires'];
    }
    $ts = strtotime(($record['exp'] ?? '') . ' 23:59:59');
    if ($ts === false) {
        return ['label' => 'Bad date', 'class' => 'bad', 'detail' => ''];
    }
    $now = time();
    if ($now > $ts + LM_GRACE_DAYS * 86400) {
        return ['label' => 'Expired', 'class' => 'bad', 'detail' => 'Locked after grace'];
    }
    if ($now > $ts) {
        return ['label' => 'Grace period', 'class' => 'warn', 'detail' => 'Renew now'];
    }
    $days = (int)ceil(($ts - $now) / 86400);
    return [
        'label'  => $days <= 30 ? 'Expires soon' : 'Active',
        'class'  => $days <= 30 ? 'warn' : 'ok',
        'detail' => $days . ' day' . ($days === 1 ? '' : 's') . ' left',
    ];
}

function lm_next_key_id(): string
{
    $year = date('Y');
    $max = 0;
    foreach (lm_records() as $record) {
        if (preg_match('/^TS-' . $year . '-(\d+)$/', $record['id'], $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return sprintf('TS-%s-%03d', $year, $max + 1);
}

function lm_record_exists(string $customer, string $id): bool
{
    return is_file(LM_ISSUED_DIR . '/' . lm_folder_name($customer) . '/' . $id . '.txt');
}

/** Saves the PIN hash outside the served folder. */
function lm_save_pin(string $pin): bool
{
    $dir = dirname(LM_PIN_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    return @file_put_contents(LM_PIN_FILE, password_hash($pin, PASSWORD_DEFAULT), LOCK_EX) !== false;
}

/** Writes the customer block (same format as the CLI) and returns its path. */
function lm_save_record(array $payload, string $key, string $notes = ''): string
{
    $dir = LM_ISSUED_DIR . '/' . lm_folder_name((string)$payload['c']);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the records folder: ' . $dir);
    }
    $file = $dir . '/' . $payload['id'] . '.txt';
    if (is_file($file)) {
        throw new RuntimeException('Key ID ' . $payload['id'] . ' already exists. Choose another.');
    }

    $typeLabel = $payload['t'] === 'perpetual' ? 'One-time (perpetual) licence' : 'Subscription licence';
    $expiry = $payload['t'] === 'perpetual' ? 'Never' : date('j M Y', (int)strtotime((string)$payload['exp']));

    $block = "TechShift POS — Licence details\n"
        . "--------------------------------\n"
        . 'Customer    : ' . $payload['c'] . "\n"
        . 'Key ID      : ' . $payload['id'] . "\n"
        . 'Type        : ' . $typeLabel . "\n"
        . 'Expires     : ' . $expiry . "\n"
        . 'Machine ID  : ' . $payload['m'] . "\n"
        . 'Issued      : ' . $payload['i'] . "\n"
        . ($notes !== '' ? 'Notes       : ' . str_replace(["\r", "\n"], ' ', $notes) . "\n" : '')
        . "\nLicence key (paste this into the activation screen):\n\n"
        . $key . "\n\n"
        . "This key is bound to the machine ID above and will not work on another PC.\n";

    if (file_put_contents($file, $block, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the record file.');
    }
    return $file;
}
