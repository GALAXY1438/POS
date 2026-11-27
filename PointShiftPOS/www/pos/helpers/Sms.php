<?php
/**
 * SMS Helper — BlessedTexts provider
 *
 * Wraps the BlessedTexts REST API (https://sms.blessedtexts.com/api/sms/v1)
 * and falls back gracefully for other configured providers.
 *
 * Usage:
 *   $result = Sms::send('254722XXXXXX', 'Your message here');
 *   if ($result['success']) { ... }
 */

class Sms
{
    // ── BlessedTexts API base ────────────────────────────────────────────
    const BLESSEDTEXTS_BASE   = 'https://sms.blessedtexts.com/api/sms/v1';
    const BLESSEDTEXTS_SEND   = self::BLESSEDTEXTS_BASE . '/sendsms';
    const BLESSEDTEXTS_BALANCE = self::BLESSEDTEXTS_BASE . '/credit-balance';

    // ── Defaults (used when DB settings are empty) ───────────────────────
    // The API key is deliberately blank: each installation enters its own
    // key in Settings → SMS. Never ship a vendor key with the app.
    const DEFAULT_API_KEY   = '';
    const DEFAULT_SENDER_ID = 'BLESSEDTEXT';
    const DEFAULT_PROVIDER  = 'blessedtexts';

    // ── Load settings from DB (cached per request) ───────────────────────
    private static ?array $cfg = null;

    private static function cfg(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }

        try {
            global $conn;
            $res = $conn->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN ('sms_provider','sms_api_key','sms_sender_id')"
            );
            $rows = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[$r['setting_key']] = $r['setting_value'];
                }
            }

            self::$cfg = [
                'provider'  => $rows['sms_provider']  ?? self::DEFAULT_PROVIDER,
                'api_key'   => $rows['sms_api_key']   ?? self::DEFAULT_API_KEY,
                'sender_id' => $rows['sms_sender_id'] ?? self::DEFAULT_SENDER_ID,
            ];
        } catch (Exception $e) {
            self::$cfg = [
                'provider'  => self::DEFAULT_PROVIDER,
                'api_key'   => self::DEFAULT_API_KEY,
                'sender_id' => self::DEFAULT_SENDER_ID,
            ];
        }

        return self::$cfg;
    }

    /**
     * Send a single SMS.
     *
     * @param  string|array $phone   E.g. "254722XXXXXX" or ["254721X","254722X"]
     * @param  string        $message
     * @param  array|null    $cfg     Override config ['api_key','sender_id','provider']
     * @param  int           $timeout cURL timeout in seconds
     * @return array  ['success'=>bool, 'message'=>string, 'data'=>mixed]
     */
    public static function send($phone, string $message, ?array $cfg = null, int $timeout = 15): array
    {
        $c = $cfg ?? self::cfg();

        if (empty($c['provider']) || $c['provider'] === 'none' || $c['provider'] === 'disabled') {
            return ['success' => false, 'message' => 'SMS provider is disabled.', 'data' => null];
        }

        if ($c['provider'] === 'blessedtexts') {
            return self::sendViaBlessedTexts($phone, $message, $c, $timeout);
        }

        // Stub for other providers — extend as needed
        return ['success' => false, 'message' => 'Provider "' . $c['provider'] . '" not implemented.', 'data' => null];
    }

    /**
     * Send multiple different messages to different numbers in one API call.
     *
     * @param  array  $messages  [['phone'=>'254722X','message'=>'Hello'],…]
     * @param  array|null $cfg
     * @return array
     */
    public static function sendBulk(array $messages, ?array $cfg = null): array
    {
        $c = $cfg ?? self::cfg();

        if (empty($c['provider']) || $c['provider'] === 'none' || $c['provider'] === 'disabled') {
            return ['success' => false, 'message' => 'SMS provider is disabled.', 'data' => null];
        }

        if ($c['provider'] === 'blessedtexts') {
            return self::sendBulkViaBlessedTexts($messages, $c);
        }

        return ['success' => false, 'message' => 'Provider not implemented.', 'data' => null];
    }

    /**
     * Query remaining SMS balance.
     *
     * Successful checks are cached on disk for a few minutes so pages that
     * display the balance never wait on the provider. Pass $force = true for
     * an explicit "check balance" action.
     *
     * @return array ['success'=>bool, 'balance'=>string|null, 'message'=>string]
     */
    public static function getBalance(?array $cfg = null, int $timeout = 10, bool $force = false): array
    {
        $c = $cfg ?? self::cfg();

        if (empty($c['api_key'])) {
            return ['success' => false, 'balance' => null, 'message' => 'No API key configured.'];
        }

        $fingerprint = sha1((string)$c['api_key']);
        $cache       = self::readBalanceCache();
        if (!$force && is_array($cache) && ($cache['fp'] ?? '') === $fingerprint) {
            $age = time() - (int)($cache['at'] ?? 0);
            if (!empty($cache['ok']) && $age < 300) {
                return ['success' => true, 'balance' => (string)$cache['balance'], 'message' => 'Cached', 'at' => (int)$cache['at']];
            }
            if (empty($cache['ok']) && $age < 60) {
                return ['success' => false, 'balance' => null, 'message' => (string)($cache['message'] ?? 'Balance unavailable.')];
            }
        }

        $response = self::curlPost(self::BLESSEDTEXTS_BALANCE, [
            'api_key' => $c['api_key'],
        ], $timeout);

        if (!$response['ok']) {
            self::writeBalanceCache(['fp' => $fingerprint, 'at' => time(), 'ok' => false, 'balance' => null, 'message' => $response['error']]);
            return ['success' => false, 'balance' => null, 'message' => $response['error']];
        }

        $data = $response['data'];
        if (isset($data['status_code']) && $data['status_code'] === '1000') {
            $balance = (string)($data['balance'] ?? '0');
            self::writeBalanceCache(['fp' => $fingerprint, 'at' => time(), 'ok' => true, 'balance' => $balance, 'message' => 'OK']);
            return ['success' => true, 'balance' => $balance, 'message' => 'OK'];
        }

        $message = self::describeStatusCode($data['status_code'] ?? '');
        self::writeBalanceCache(['fp' => $fingerprint, 'at' => time(), 'ok' => false, 'balance' => null, 'message' => $message]);
        return [
            'success' => false,
            'balance' => null,
            'message' => $message,
        ];
    }

    /**
     * Last saved balance without touching the network. Dashboards use this so
     * a slow SMS provider can never delay first paint.
     */
    public static function cachedBalance(?array $cfg = null, int $maxAge = 0): array
    {
        $c = $cfg ?? self::cfg();
        if (empty($c['api_key'])) {
            return ['success' => false, 'balance' => null, 'message' => 'No API key configured.'];
        }

        $cache = self::readBalanceCache();
        if (!is_array($cache) || ($cache['fp'] ?? '') !== sha1((string)$c['api_key'])) {
            return ['success' => false, 'balance' => null, 'message' => 'Not checked yet — open Messages to refresh.'];
        }
        if ($maxAge > 0 && time() - (int)($cache['at'] ?? 0) > $maxAge) {
            return ['success' => false, 'balance' => null, 'message' => 'Not checked recently — open Messages to refresh.'];
        }
        if (empty($cache['ok'])) {
            return ['success' => false, 'balance' => null, 'message' => (string)($cache['message'] ?? 'Balance unavailable.')];
        }
        return ['success' => true, 'balance' => (string)$cache['balance'], 'message' => 'Cached', 'at' => (int)$cache['at']];
    }

    private static function balanceCacheFile(): string
    {
        $root = getenv('TECHSHIFT_DATA_ROOT');
        $base = is_string($root) && trim($root) !== ''
            ? rtrim($root, "\\/")
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data';
        return $base . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'sms_balance.json';
    }

    private static function readBalanceCache(): ?array
    {
        $raw = @file_get_contents(self::balanceCacheFile());
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function writeBalanceCache(array $entry): void
    {
        $file = self::balanceCacheFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode($entry, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // ── BlessedTexts internals ────────────────────────────────────────────

    private static function sendViaBlessedTexts($phone, string $message, array $c, int $timeout = 15): array
    {
        if (empty($c['api_key'])) {
            return ['success' => false, 'message' => 'BlessedTexts API key is not configured.', 'data' => null];
        }
        if (empty($c['sender_id'])) {
            return ['success' => false, 'message' => 'BlessedTexts Sender ID is not configured.', 'data' => null];
        }

        // Normalise phone: array → comma-separated string
        $phoneStr = is_array($phone) ? implode(',', $phone) : (string)$phone;

        $payload = [
            'api_key'   => $c['api_key'],
            'sender_id' => $c['sender_id'],
            'message'   => $message,
            'phone'     => $phoneStr,
        ];

        $response = self::curlPost(self::BLESSEDTEXTS_SEND, $payload, $timeout);

        if (!$response['ok']) {
            return ['success' => false, 'message' => $response['error'], 'data' => null];
        }

        $data = $response['data'];

        // Response is an array of result objects
        if (is_array($data)) {
            $allOk  = true;
            $errors = [];
            foreach ($data as $item) {
                if (isset($item['status_code']) && $item['status_code'] !== '1000') {
                    $allOk    = false;
                    $errors[] = self::describeStatusCode($item['status_code']);
                }
            }
            if ($allOk) {
                return ['success' => true, 'message' => 'SMS sent successfully.', 'data' => $data];
            }
            return ['success' => false, 'message' => implode('; ', array_unique($errors)), 'data' => $data];
        }

        return ['success' => false, 'message' => 'Unexpected API response.', 'data' => $data];
    }

    private static function sendBulkViaBlessedTexts(array $messages, array $c): array
    {
        if (empty($c['api_key'])) {
            return ['success' => false, 'message' => 'BlessedTexts API key is not configured.', 'data' => null];
        }

        $payload = [
            'api_key'   => $c['api_key'],
            'sender_id' => $c['sender_id'],
            'messages'  => $messages,
        ];

        $response = self::curlPost(self::BLESSEDTEXTS_SEND, $payload);

        if (!$response['ok']) {
            return ['success' => false, 'message' => $response['error'], 'data' => null];
        }

        $data = $response['data'];
        return ['success' => true, 'message' => 'Bulk SMS dispatched.', 'data' => $data];
    }

    // ── cURL helper ──────────────────────────────────────────────────────

    private static function curlPost(string $url, array $payload, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return self::streamPost($url, $payload, $timeout);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'error' => 'cURL error: ' . $error, 'data' => null];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return ['ok' => false, 'error' => 'Invalid JSON response (HTTP ' . $code . ').', 'data' => $raw];
        }

        return ['ok' => true, 'error' => null, 'data' => $decoded];
    }

    /**
     * Fallback for hosts where the cURL extension is unavailable.
     */
    private static function streamPost(string $url, array $payload, int $timeout = 15): array
    {
        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'error' => 'No HTTP transport available (cURL and allow_url_fopen are disabled).', 'data' => null];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => json_encode($payload),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw  = @file_get_contents($url, false, $ctx);
        $err  = error_get_last()['message'] ?? '';
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }

        if ($raw === false) {
            return ['ok' => false, 'error' => 'HTTP request failed' . ($err ? ': ' . $err : ''), 'data' => null];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return ['ok' => false, 'error' => 'Invalid JSON response (HTTP ' . $code . ').', 'data' => $raw];
        }

        return ['ok' => true, 'error' => null, 'data' => $decoded];
    }

    // ── Status code map ──────────────────────────────────────────────────

    private static function describeStatusCode(string $code): string
    {
        $map = [
            '1000' => 'Success',
            '1001' => 'Missing API Key',
            '1002' => 'Invalid API Key',
            '1003' => 'Missing Sender ID',
            '1004' => 'Invalid Sender ID',
            '1005' => 'Missing Message',
            '1006' => 'Missing Phone number',
            '1007' => 'Invalid phone number format',
            '1008' => 'Invalid phone number',
            '1009' => 'Insufficient SMS credits',
        ];
        return $map[$code] ?? ('Unknown error (code ' . $code . ')');
    }

    /**
     * Reset cached config — useful after settings are saved in the same request.
     */
    public static function resetCache(): void
    {
        self::$cfg = null;
        @unlink(self::balanceCacheFile());
    }
}
