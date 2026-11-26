<?php
/**
 * M-Pesa Helper
 *
 * Supports two providers:
 *   1. payhero  — PayHero STK Push  (https://backend.payhero.co.ke/api/v2/payments)
 *   2. daraja   — Safaricom Daraja  (direct API, consumer key / secret)
 *
 * Usage:
 *   $result = Mpesa::stkPush('0787677676', 1500, 'ORD-001');
 *   if ($result['success']) { $ref = $result['reference']; }
 */
class Mpesa
{
    // ── PayHero constants ────────────────────────────────────────────────
    const PAYHERO_BASE      = 'https://backend.payhero.co.ke/api/v2';
    const PAYHERO_PAYMENTS  = self::PAYHERO_BASE . '/payments';
    const PAYHERO_STATUS    = self::PAYHERO_BASE . '/transaction-status';
    const PAYHERO_CHANNELS  = self::PAYHERO_BASE . '/payment_channels';

    // ── Default PayHero credentials (from your account) ─────────────────
    const DEFAULT_PH_USERNAME   = 'uqp48jTzsUrAILZ3gMOs';
    const DEFAULT_PH_PASSWORD   = 'seD8LGH4P0nLiavt7eXwCURxeUq4Aypx0eqqXLGg';
    const DEFAULT_PH_ACCOUNT_ID = '7092';
    const DEFAULT_PH_AUTH       = 'Basic dXFwNDhqVHpzVXJBSUxaM2dNT3M6c2VEOExHSDRQMG5MaWF2dDdlWHdDVVJ4ZVVxNEF5cHgwZXFxWExHZw==';

    // ── Daraja constants ─────────────────────────────────────────────────
    const DARAJA_AUTH_URL   = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    const DARAJA_STK_URL    = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    // Sandbox:
    // const DARAJA_AUTH_URL = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    // const DARAJA_STK_URL  = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

    // ── Cached config ────────────────────────────────────────────────────
    private static ?array $cfg = null;

    private static function cfg(): array
    {
        if (self::$cfg !== null) return self::$cfg;

        try {
            global $conn;
            $res = $conn->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN (
                     'mpesa_provider',
                     'mpesa_ph_username','mpesa_ph_password','mpesa_ph_channel_id',
                     'mpesa_daraja_consumer_key','mpesa_daraja_consumer_secret',
                     'mpesa_daraja_shortcode','mpesa_daraja_passkey','mpesa_daraja_type',
                     'mpesa_callback_url'
                 )"
            );
            $rows = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) $rows[$r['setting_key']] = $r['setting_value'];
            }
            self::$cfg = $rows;
        } catch (\Throwable $e) {
            self::$cfg = [];
        }
        return self::$cfg;
    }

    public static function resetCache(): void { self::$cfg = null; }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Initiate STK Push.
     *
     * @param  string $phone      e.g. "0787677676" or "254787677676"
     * @param  float  $amount
     * @param  string $reference  Order number / external ref
     * @param  string $name       Customer name (optional)
     * @return array  ['success'=>bool, 'reference'=>string|null, 'message'=>string, 'data'=>mixed]
     */
    public static function stkPush(string $phone, float $amount, string $reference = '', string $name = ''): array
    {
        $c        = self::cfg();
        $provider = $c['mpesa_provider'] ?? 'payhero';

        return match ($provider) {
            'payhero' => self::payHeroStkPush($phone, $amount, $reference, $name, $c),
            'daraja'  => self::darajaStkPush($phone, $amount, $reference, $name, $c),
            default   => ['success' => false, 'reference' => null, 'message' => 'M-Pesa provider not configured.', 'data' => null],
        };
    }

    /**
     * Check transaction status (PayHero).
     */
    public static function checkStatus(string $reference): array
    {
        $c    = self::cfg();
        $auth = self::payHeroAuth($c);

        $res = self::curlGet(self::PAYHERO_STATUS . '?reference=' . urlencode($reference), $auth);
        if (!$res['ok']) return ['success' => false, 'status' => null, 'message' => $res['error']];

        $data = $res['data'];
        return [
            'success' => true,
            'status'  => $data['status'] ?? 'UNKNOWN',
            'message' => $data['status'] ?? 'OK',
            'data'    => $data,
        ];
    }

    /**
     * Get PayHero payment channels.
     */
    public static function getChannels(): array
    {
        $c    = self::cfg();
        $auth = self::payHeroAuth($c);
        $res  = self::curlGet(self::PAYHERO_CHANNELS . '?is_active=true', $auth);
        if (!$res['ok']) return [];
        return $res['data']['payment_channels'] ?? [];
    }

    // ── PayHero internals ────────────────────────────────────────────────

    private static function payHeroAuth(array $c): string
    {
        // If custom credentials saved, build Basic token; otherwise use default
        $user = $c['mpesa_ph_username'] ?? self::DEFAULT_PH_USERNAME;
        $pass = $c['mpesa_ph_password'] ?? self::DEFAULT_PH_PASSWORD;
        if ($user === self::DEFAULT_PH_USERNAME && $pass === self::DEFAULT_PH_PASSWORD) {
            return self::DEFAULT_PH_AUTH;
        }
        return 'Basic ' . base64_encode($user . ':' . $pass);
    }

    private static function payHeroStkPush(string $phone, float $amount, string $reference, string $name, array $c): array
    {
        $auth       = self::payHeroAuth($c);
        $channelId  = (int)($c['mpesa_ph_channel_id'] ?? 0);
        $callbackUrl = $c['mpesa_callback_url'] ?? (SITE_URL . '/mpesa_callback.php');

        if (!$channelId) {
            return ['success' => false, 'reference' => null, 'message' => 'PayHero channel ID not configured. Set it in Payment Settings.', 'data' => null];
        }

        $payload = [
            'amount'             => (int)round($amount),
            'phone_number'       => self::normalisePhone($phone),
            'channel_id'         => $channelId,
            'provider'           => 'm-pesa',
            'external_reference' => $reference ?: ('ORD-' . time()),
            'customer_name'      => $name ?: 'Customer',
            'callback_url'       => $callbackUrl,
        ];

        $res = self::curlPost(self::PAYHERO_PAYMENTS, $payload, $auth);
        if (!$res['ok']) return ['success' => false, 'reference' => null, 'message' => $res['error'], 'data' => null];

        $data = $res['data'];
        if (isset($data['success']) && $data['success']) {
            return [
                'success'   => true,
                'reference' => $data['reference'] ?? null,
                'checkout'  => $data['CheckoutRequestID'] ?? null,
                'message'   => 'STK Push sent. Ask customer to enter M-Pesa PIN.',
                'data'      => $data,
            ];
        }

        return [
            'success'   => false,
            'reference' => null,
            'message'   => $data['error_message'] ?? $data['message'] ?? 'PayHero STK Push failed.',
            'data'      => $data,
        ];
    }

    // ── Daraja internals ─────────────────────────────────────────────────

    private static function darajaStkPush(string $phone, float $amount, string $reference, string $name, array $c): array
    {
        $ck        = $c['mpesa_daraja_consumer_key']    ?? '';
        $cs        = $c['mpesa_daraja_consumer_secret'] ?? '';
        $shortcode = $c['mpesa_daraja_shortcode']       ?? '';
        $passkey   = $c['mpesa_daraja_passkey']         ?? '';
        $txnType   = $c['mpesa_daraja_type']            ?? 'CustomerPayBillOnline';
        $callback  = $c['mpesa_callback_url']           ?? (SITE_URL . '/mpesa_callback.php');

        if (!$ck || !$cs || !$shortcode || !$passkey) {
            return ['success' => false, 'reference' => null, 'message' => 'Daraja credentials not fully configured.', 'data' => null];
        }

        // 1. Get access token
        $tokenRes = self::curlGet(self::DARAJA_AUTH_URL, 'Basic ' . base64_encode("$ck:$cs"));
        if (!$tokenRes['ok'] || empty($tokenRes['data']['access_token'])) {
            return ['success' => false, 'reference' => null, 'message' => 'Could not get Daraja access token.', 'data' => null];
        }
        $token = $tokenRes['data']['access_token'];

        // 2. Build STK push
        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);
        $payload   = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $txnType,
            'Amount'            => (int)round($amount),
            'PartyA'            => self::normalisePhone($phone),
            'PartyB'            => $shortcode,
            'PhoneNumber'       => self::normalisePhone($phone),
            'CallBackURL'       => $callback,
            'AccountReference'  => $reference ?: 'POS',
            'TransactionDesc'   => 'Payment',
        ];

        $res = self::curlPost(self::DARAJA_STK_URL, $payload, 'Bearer ' . $token);
        if (!$res['ok']) return ['success' => false, 'reference' => null, 'message' => $res['error'], 'data' => null];

        $data = $res['data'];
        if (($data['ResponseCode'] ?? '') === '0') {
            return [
                'success'   => true,
                'reference' => $data['CheckoutRequestID'] ?? null,
                'checkout'  => $data['CheckoutRequestID'] ?? null,
                'message'   => 'STK Push sent. Ask customer to enter M-Pesa PIN.',
                'data'      => $data,
            ];
        }

        return [
            'success'   => false,
            'reference' => null,
            'message'   => $data['errorMessage'] ?? $data['ResponseDescription'] ?? 'Daraja STK Push failed.',
            'data'      => $data,
        ];
    }

    // ── Phone normalisation ───────────────────────────────────────────────

    public static function normalisePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))   return '254' . substr($phone, 1);
        if (str_starts_with($phone, '254')) return $phone;
        if (strlen($phone) === 9)            return '254' . $phone;
        return $phone;
    }

    // ── cURL helpers ──────────────────────────────────────────────────────

    private static function curlPost(string $url, array $payload, string $auth): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'cURL not available.', 'data' => null];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $auth],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($err) return ['ok' => false, 'error' => $err, 'data' => null];
        $d = json_decode($raw, true);
        return $d !== null ? ['ok' => true, 'error' => null, 'data' => $d] : ['ok' => false, 'error' => 'Invalid JSON', 'data' => $raw];
    }

    private static function curlGet(string $url, string $auth): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'cURL not available.', 'data' => null];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $auth],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($err) return ['ok' => false, 'error' => $err, 'data' => null];
        $d = json_decode($raw, true);
        return $d !== null ? ['ok' => true, 'error' => null, 'data' => $d] : ['ok' => false, 'error' => 'Invalid JSON', 'data' => $raw];
    }
}
