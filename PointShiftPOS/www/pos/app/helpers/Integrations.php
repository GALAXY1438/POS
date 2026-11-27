<?php
/**
 * Integrations — outbound webhooks for third-party systems (QuickBooks, Xero,
 * custom middleware, etc.) plus settings access for the integrations page.
 */
class Integrations
{
    private static ?array $cache = null;

    public static function settings(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key LIKE 'integration_%'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[str_replace('integration_', '', $r['setting_key'])] = (string)$r['setting_value'];
            }
        } catch (Throwable $e) {
        }
        return self::$cache = $out;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    public static function webhookEnabled(): bool
    {
        $s = self::settings();
        return !empty($s['webhook_url']) && filter_var($s['webhook_url'], FILTER_VALIDATE_URL);
    }

    /**
     * POST an event to the configured webhook URL. Silent on failure.
     */
    public static function fire(string $event, array $payload): void
    {
        $events = array_values(array_filter(array_map('trim', explode(',', (string)(self::settings()['webhook_events'] ?? '')))));
        if ($events && !in_array($event, $events, true)) {
            return;
        }
        self::post($event, $payload);
    }

    /**
     * Send an unconditional test event (ignores the event filter).
     *
     * @return array{ok:bool,detail:string}
     */
    public static function fireTest(): array
    {
        return self::post('integration.test', ['message' => 'Test event from ' . SITE_NAME]);
    }

    /** @return array{ok:bool,detail:string} */
    private static function post(string $event, array $payload): array
    {
        $s = self::settings();
        $url = trim((string)($s['webhook_url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'detail' => 'No webhook URL configured.'];
        }

        $body = json_encode([
            'event'   => $event,
            'app'     => SITE_NAME,
            'sent_at' => date('c'),
            'data'    => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json', 'X-TechShift-Event: ' . $event];
        $secret  = (string)($s['webhook_secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-TechShift-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'cURL is not available on this PHP build.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'detail' => 'Connection failed: ' . $err];
        }
        return ['ok' => $code >= 200 && $code < 300, 'detail' => 'Endpoint answered HTTP ' . $code . '.'];
    }
}
