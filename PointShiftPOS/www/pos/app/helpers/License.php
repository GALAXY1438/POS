<?php
/**
 * Offline licence enforcement.
 *
 * Keys are RSA-SHA256 signed payloads issued by TechShift:
 *   TS-<base64url(payload)>.<base64url(signature)>
 *
 * Payload: {"v":1,"id":"TS-2026-001","c":"Customer","t":"perpetual|subscription",
 *           "exp":"YYYY-MM-DD","m":"XXXX-XXXX-XXXX-XXXX","i":"YYYY-MM-DD"}
 *
 * A key is valid only on the machine whose ID it carries. Subscription keys
 * stop working after exp plus a short grace period; perpetual keys never expire.
 */
class License
{
    public const GRACE_DAYS = 7;
    public const KEY_PREFIX = 'TS-';

    private static ?array $statusCache = null;

    /** Live fingerprint for this request; recomputed from hardware, never read from disk. */
    private static ?string $liveMachineId = null;

    /** Directory that survives app updates (desktop) or sits beside www (portable zip). */
    public static function dataDir(): string
    {
        $root = getenv('TECHSHIFT_DATA_ROOT');
        if (is_string($root) && trim($root) !== '') {
            return rtrim($root, "\\/") . DIRECTORY_SEPARATOR . 'license';
        }
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'data';
    }

    private static function licenseFile(): string
    {
        return self::dataDir() . DIRECTORY_SEPARATOR . 'license.json';
    }

    private static function machineFile(): string
    {
        return self::dataDir() . DIRECTORY_SEPARATOR . 'machine.id';
    }

    private static function ensureDir(): void
    {
        $dir = self::dataDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Per-machine identifier shown to the customer for activation.
     *
     * Always derived from the live hardware in this request. The machine.id
     * file is only a display/diagnostic cache and is refreshed when it no
     * longer matches; it is never trusted for licence validation, so copying
     * the data folder to another PC cannot carry a matching identity.
     */
    public static function machineId(): string
    {
        if (self::$liveMachineId !== null) {
            return self::$liveMachineId;
        }

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
        $id   = implode('-', str_split($hash, 4));

        $cached = @file_get_contents(self::machineFile());
        if (!is_string($cached) || trim($cached) !== $id) {
            self::ensureDir();
            @file_put_contents(self::machineFile(), $id);
        }

        return self::$liveMachineId = $id;
    }

    private static function publicKey(): ?string
    {
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'license_public.pem';
        $pem  = @file_get_contents($file);
        return $pem === false ? null : $pem;
    }

    private static function b64urlDecode(string $value): string|false
    {
        $value = strtr(trim($value), '-_', '+/');
        $pad   = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($value, true);
    }

    /**
     * Verify signature, payload shape and machine binding. Does not check expiry.
     *
     * @return array{ok:bool, error?:string, payload?:array}
     */
    public static function verify(string $key, ?string $machineId = null): array
    {
        $key = preg_replace('/\s+/', '', $key);
        if ($key === '' || strncmp($key, self::KEY_PREFIX, strlen(self::KEY_PREFIX)) !== 0) {
            return ['ok' => false, 'error' => 'This does not look like a TechShift licence key.'];
        }

        $body = substr($key, strlen(self::KEY_PREFIX));
        $dot  = strrpos($body, '.');
        if ($dot === false) {
            return ['ok' => false, 'error' => 'The licence key is incomplete. Paste the whole key.'];
        }

        $payloadJson = self::b64urlDecode(substr($body, 0, $dot));
        $signature   = self::b64urlDecode(substr($body, $dot + 1));
        if ($payloadJson === false || $signature === false) {
            return ['ok' => false, 'error' => 'The licence key is malformed. Paste it again carefully.'];
        }

        $pub = self::publicKey();
        if ($pub === null) {
            return ['ok' => false, 'error' => 'Licence public key is missing from this installation.'];
        }
        if (openssl_verify($payloadJson, $signature, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'error' => 'The licence key is not valid for TechShift POS.'];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Unsupported licence key version.'];
        }
        if (!in_array($payload['t'] ?? '', ['perpetual', 'subscription'], true)) {
            return ['ok' => false, 'error' => 'The licence key has an unknown type.'];
        }

        $machine = $machineId ?? self::machineId();
        if (strcasecmp((string)($payload['m'] ?? ''), $machine) !== 0) {
            return [
                'ok'    => false,
                'error' => 'This key was issued for a different PC. Send us this machine ID ('
                    . $machine . ') to get a matching key.',
            ];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * @return array{ok:bool, error?:string, payload?:array}
     */
    public static function activate(string $key): array
    {
        $check = self::verify($key);
        if (!$check['ok']) {
            return $check;
        }

        $payload = $check['payload'];
        $state   = self::expiryState($payload);
        if (!$state['ok']) {
            return ['ok' => false, 'error' => $state['error']];
        }

        self::ensureDir();
        $record = [
            'key'          => preg_replace('/\s+/', '', $key),
            'machine'      => self::machineId(),
            'activated_at' => date('c'),
            'last_seen'    => time(),
        ];
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents(self::licenseFile(), $json, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not save the licence on this PC. Check folder permissions.'];
        }

        self::$statusCache = null;
        return ['ok' => true, 'payload' => $payload];
    }

    private static function storedKey(): ?string
    {
        $raw = @file_get_contents(self::licenseFile());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['key']) ? (string)$data['key'] : null;
    }

    /**
     * Current licence state for this machine.
     *
     * @return array{active:bool, reason:string, payload:?array, days_left:?int, grace:bool}
     */
    public static function status(): array
    {
        if (self::$statusCache !== null) {
            return self::$statusCache;
        }

        $empty = ['active' => false, 'reason' => 'missing', 'payload' => null, 'days_left' => null, 'grace' => false];

        $key = self::storedKey();
        if ($key === null) {
            return self::$statusCache = $empty;
        }

        $check = self::verify($key);
        if (!$check['ok']) {
            return self::$statusCache = ['active' => false, 'reason' => 'invalid', 'error' => $check['error'] ?? '', 'payload' => null, 'days_left' => null, 'grace' => false];
        }

        $payload = $check['payload'];
        $expiry  = self::expiryState($payload);
        if (!$expiry['ok']) {
            return self::$statusCache = ['active' => false, 'reason' => 'expired', 'error' => $expiry['error'], 'payload' => $payload, 'days_left' => $expiry['days_left'] ?? null, 'grace' => false];
        }

        // Basic clock-rollback guard: never accept a date far behind the last run.
        $raw  = @file_get_contents(self::licenseFile());
        $data = $raw !== false ? json_decode($raw, true) : null;
        $lastSeen = is_array($data) ? (int)($data['last_seen'] ?? 0) : 0;
        if ($lastSeen > 0 && time() < $lastSeen - 172800) {
            return self::$statusCache = ['active' => false, 'reason' => 'clock', 'error' => 'The system clock was moved backwards. Set the correct date and time, then try again.', 'payload' => $payload, 'days_left' => $expiry['days_left'] ?? null, 'grace' => false];
        }

        if (is_array($data) && time() > $lastSeen) {
            $data['last_seen'] = time();
            @file_put_contents(self::licenseFile(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        return self::$statusCache = [
            'active'    => true,
            'reason'    => $expiry['grace'] ? 'grace' : 'ok',
            'payload'   => $payload,
            'days_left' => $expiry['days_left'] ?? null,
            'grace'     => $expiry['grace'],
        ];
    }

    public static function isActive(): bool
    {
        return self::status()['active'];
    }

    /** @return array{ok:bool,error?:string,grace?:bool,days_left?:int} */
    private static function expiryState(array $payload): array
    {
        if (($payload['t'] ?? '') === 'perpetual') {
            return ['ok' => true, 'grace' => false];
        }

        $exp = (string)($payload['exp'] ?? '');
        $ts  = strtotime($exp . ' 23:59:59');
        if ($ts === false) {
            return ['ok' => false, 'error' => 'This subscription key has an invalid expiry date.'];
        }

        $now      = time();
        $graceEnd = $ts + self::GRACE_DAYS * 86400;
        $days     = (int)ceil(($ts - $now) / 86400);

        if ($now > $graceEnd) {
            return ['ok' => false, 'error' => 'This subscription expired on ' . date('j M Y', $ts) . '. Enter a renewal key to continue.', 'days_left' => 0];
        }
        return ['ok' => true, 'grace' => $now > $ts, 'days_left' => max(0, $days)];
    }

    public static function typeLabel(?array $payload): string
    {
        if (!$payload) {
            return 'Not activated';
        }
        return ($payload['t'] ?? '') === 'perpetual' ? 'One-time licence' : 'Subscription';
    }

    public static function expiryLabel(?array $payload): string
    {
        if (!$payload) {
            return '—';
        }
        if (($payload['t'] ?? '') === 'perpetual') {
            return 'Never';
        }
        $exp = (string)($payload['exp'] ?? '');
        $ts  = strtotime($exp);
        return $ts ? date('j M Y', $ts) : $exp;
    }
}