<?php
/**
 * Per-user two-factor authentication (email or SMS one-time codes).
 *
 * The challenge lives in the session and is checked by config.php, which
 * keeps the user on verify_2fa.php until the code is confirmed.
 */
class TwoFactor
{
    const TTL       = 300;
    const MAX_TRIES = 5;
    const RESEND_COOLDOWN = 30;

    public static function settingsFor(int $userId): array
    {
        $row = [
            'twofa_enabled' => 0,
            'twofa_method'  => 'email',
            'twofa_phone'   => '',
        ];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('SELECT twofa_enabled, twofa_method, twofa_phone FROM user_security WHERE user_id = ? LIMIT 1');
            $st->execute([$userId]);
            $found = $st->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $row = array_merge($row, $found);
            }
        } catch (Throwable $e) {
            // Table may not exist yet on older installations.
        }
        return $row;
    }

    public static function isEnabled(int $userId): bool
    {
        return (int)self::settingsFor($userId)['twofa_enabled'] === 1;
    }

    /**
     * Create a challenge for the freshly logged-in user and send the code.
     *
     * @return array{started:bool,message:string}
     */
    public static function startForCurrentUser(): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['started' => false, 'message' => ''];
        }

        $cfg = self::settingsFor($userId);
        if ((int)$cfg['twofa_enabled'] !== 1) {
            return ['started' => false, 'message' => ''];
        }

        $email = '';
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $email = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
        }

        $method = $cfg['twofa_method'] === 'sms' ? 'sms' : 'email';
        $phone  = trim((string)$cfg['twofa_phone']);
        if ($method === 'sms' && $phone === '' && $email !== '') {
            $method = 'email';
        }
        if ($method === 'email' && $email === '' && $phone !== '') {
            $method = 'sms';
        }
        if (($method === 'sms' && $phone === '') || ($method === 'email' && $email === '')) {
            error_log('TwoFactor: no destination for user ' . $userId);
            return ['started' => false, 'message' => ''];
        }

        $result = self::issueCode($userId, $method, $phone, $email);
        return ['started' => true, 'message' => $result['message']];
    }

    public static function resend(): array
    {
        $tfa = $_SESSION['tfa'] ?? null;
        if (!is_array($tfa)) {
            return ['success' => false, 'message' => 'No verification is pending.'];
        }
        if (!empty($tfa['sent_at']) && (time() - (int)$tfa['sent_at']) < self::RESEND_COOLDOWN) {
            $wait = self::RESEND_COOLDOWN - (time() - (int)$tfa['sent_at']);
            return ['success' => false, 'message' => 'Please wait ' . $wait . 's before requesting another code.'];
        }

        $code = self::newCode();
        $_SESSION['tfa']['hash']    = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['tfa']['exp']     = time() + self::TTL;
        $_SESSION['tfa']['tries']   = 0;
        $_SESSION['tfa']['sent_at'] = time();

        $sent = self::deliver($tfa['channel'] ?? 'email', $tfa['dest'] ?? '', $code);
        if (!$sent['success']) {
            return ['success' => false, 'message' => $sent['message']];
        }
        return ['success' => true, 'message' => 'A new code was sent. It expires in 5 minutes.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public static function verify(string $code): array
    {
        $tfa = $_SESSION['tfa'] ?? null;
        if (!is_array($tfa)) {
            return ['success' => false, 'message' => 'Your verification session expired. Please sign in again.'];
        }
        if ((int)($tfa['exp'] ?? 0) < time()) {
            self::clear();
            return ['success' => false, 'message' => 'The code expired. Please sign in again.'];
        }
        if ((int)($tfa['tries'] ?? 0) >= self::MAX_TRIES) {
            self::clear();
            return ['success' => false, 'message' => 'Too many failed attempts. Please sign in again.'];
        }

        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return ['success' => false, 'message' => 'Enter the 6-digit code.'];
        }
        if (!password_verify($code, (string)($tfa['hash'] ?? ''))) {
            $_SESSION['tfa']['tries'] = (int)($tfa['tries'] ?? 0) + 1;
            $left = self::MAX_TRIES - $_SESSION['tfa']['tries'];
            return ['success' => false, 'message' => 'Incorrect code. ' . max(0, $left) . ' attempt(s) left.'];
        }

        self::clear();
        return ['success' => true, 'message' => 'Verified.'];
    }

    public static function clear(): void
    {
        unset($_SESSION['tfa'], $_SESSION['tfa_notice'], $_SESSION['tfa_sent_at']);
    }

    public static function pending(): bool
    {
        return is_array($_SESSION['tfa'] ?? null);
    }

    private static function issueCode(int $userId, string $method, string $phone, string $email): array
    {
        $code   = self::newCode();
        $dest   = $method === 'sms' ? $phone : $email;
        $masked = self::maskDestination($dest, $method);

        $_SESSION['tfa'] = [
            'uid'     => $userId,
            'hash'    => password_hash($code, PASSWORD_DEFAULT),
            'exp'     => time() + self::TTL,
            'tries'   => 0,
            'channel' => $method,
            'dest'    => $dest,
            'masked'  => $masked,
            'sent_at' => time(),
        ];

        $sent = self::deliver($method, $dest, $code);
        return [
            'success' => $sent['success'],
            'message' => $sent['success']
                ? 'A 6-digit code was sent to ' . $masked . '.'
                : $sent['message'],
        ];
    }

    private static function newCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function maskDestination(string $dest, string $method): string
    {
        if ($dest === '') {
            return 'your account';
        }
        if ($method === 'sms') {
            $tail = substr($dest, -3);
            return str_repeat('*', max(0, strlen($dest) - 3)) . $tail;
        }
        $at = strpos($dest, '@');
        if ($at === false) {
            return $dest;
        }
        $name = substr($dest, 0, $at);
        $domain = substr($dest, $at);
        return substr($name, 0, 1) . str_repeat('*', max(1, strlen($name) - 1)) . $domain;
    }

    /** @return array{success:bool,message:string} */
    private static function deliver(string $method, string $dest, string $code): array
    {
        if ($method === 'sms') {
            try {
                $res = Sms::send($dest, SITE_NAME . ' verification code: ' . $code . ' (valid 5 minutes). Do not share it.');
                return ['success' => !empty($res['success']), 'message' => $res['success'] ? 'SMS sent.' : (string)($res['message'] ?? 'SMS could not be sent.')];
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'SMS could not be sent.'];
            }
        }

        try {
            require_once __DIR__ . '/../../classes/SimpleMailer.php';
            $db = Database::getInstance()->getConnection();
            $res = $db->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption')"
            );
            $cfg = [];
            if ($res) {
                foreach ($res->fetchAll() as $row) {
                    $cfg[$row['setting_key']] = $row['setting_value'];
                }
            }
            if (empty($cfg['smtp_host']) || empty($cfg['smtp_user'])) {
                return ['success' => false, 'message' => 'Email is not configured. Ask an administrator to set up SMTP.'];
            }

            $mailer = new SimpleMailer([
                'host'       => $cfg['smtp_host'],
                'port'       => $cfg['smtp_port'] ?? 587,
                'username'   => $cfg['smtp_user'],
                'password'   => $cfg['smtp_pass'] ?? '',
                'from'       => $cfg['smtp_from'] ?? $cfg['smtp_user'],
                'from_name'  => $cfg['smtp_from_name'] ?? SITE_NAME,
                'encryption' => $cfg['smtp_encryption'] ?? 'tls',
            ]);

            $body = "Your " . SITE_NAME . " verification code is:\n\n    " . $code . "\n\n"
                  . "It expires in 5 minutes. If you did not try to sign in, change your password.";
            $send = $mailer->send($dest, SITE_NAME . ' verification code', $body);
            return [
                'success' => !empty($send['success']),
                'message' => !empty($send['success']) ? 'Email sent.' : (string)($send['message'] ?? 'Email could not be sent.'),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Email could not be sent.'];
        }
    }
}
