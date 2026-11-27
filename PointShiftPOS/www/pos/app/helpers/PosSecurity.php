<?php
/**
 * POS approval policy.
 *
 * Cashiers (and staff on a selling terminal) can be required to obtain an
 * approval before completing a high-value sale or removing a cart line.
 * Approval is granted either by scanning the manager barcode or by a
 * verified fingerprint (WebAuthn) and is kept in the session for a short time.
 */
class PosSecurity
{
    private static ?array $cfg = null;

    public static function settings(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }

        $cfg = [
            'sale_limit'        => 0.0,
            'remove_approval'   => false,
            'approval_mode'     => 'either',
            'barcode_token'     => '',
            'barcode_created'   => '',
            'fp_cred_id'        => '',
            'fp_public_key'     => '',
            'fp_enrolled_at'    => '',
            'otp_channel'       => 'sms',
            'manager_phone'     => '',
            'manager_email'     => '',
        ];

        try {
            $db = Database::getInstance()->getConnection();
            $res = $db->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN (
                    'security_sale_limit','security_remove_approval','security_approval_mode',
                    'security_barcode_token','security_barcode_created_at',
                    'security_fp_cred_id','security_fp_pubkey','security_fp_enrolled_at',
                    'security_otp_channel','security_manager_phone','security_manager_email'
                 )"
            );
            $rows = [];
            if ($res) {
                foreach ($res->fetchAll() as $r) {
                    $rows[$r['setting_key']] = $r['setting_value'];
                }
            }

            $cfg['sale_limit']      = max(0.0, (float)($rows['security_sale_limit'] ?? 0));
            $cfg['remove_approval'] = ($rows['security_remove_approval'] ?? '0') === '1';
            $mode                   = $rows['security_approval_mode'] ?? 'either';
            $cfg['approval_mode']   = in_array($mode, ['either', 'fingerprint', 'barcode', 'otp'], true) ? $mode : 'either';
            $cfg['barcode_token']   = (string)($rows['security_barcode_token'] ?? '');
            $cfg['barcode_created'] = (string)($rows['security_barcode_created_at'] ?? '');
            $cfg['fp_cred_id']      = (string)($rows['security_fp_cred_id'] ?? '');
            $cfg['fp_public_key']   = (string)($rows['security_fp_pubkey'] ?? '');
            $cfg['fp_enrolled_at']  = (string)($rows['security_fp_enrolled_at'] ?? '');
            $cfg['otp_channel']     = ($rows['security_otp_channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
            $cfg['manager_phone']   = (string)($rows['security_manager_phone'] ?? '');
            $cfg['manager_email']   = (string)($rows['security_manager_email'] ?? '');
        } catch (Throwable $e) {
            error_log('PosSecurity settings failed: ' . $e->getMessage());
        }

        return self::$cfg = $cfg;
    }

    public static function resetCache(): void
    {
        self::$cfg = null;
    }

    public static function roleIsRestricted(): bool
    {
        $role = $_SESSION['role'] ?? '';
        return in_array($role, [ROLE_CASHIER, ROLE_STAFF], true);
    }

    public static function saleLimit(): float
    {
        return (float)self::settings()['sale_limit'];
    }

    public static function removeApprovalRequired(): bool
    {
        return (bool)self::settings()['remove_approval'];
    }

    public static function approvalMode(): string
    {
        return (string)self::settings()['approval_mode'];
    }

    public static function fingerprintEnrolled(): bool
    {
        $s = self::settings();
        return $s['fp_cred_id'] !== '' && $s['fp_public_key'] !== '';
    }

    public static function barcodeConfigured(): bool
    {
        return self::settings()['barcode_token'] !== '';
    }

    public static function otpChannel(): string
    {
        return (string)self::settings()['otp_channel'];
    }

    public static function otpConfigured(): bool
    {
        $s = self::settings();
        if ($s['otp_channel'] === 'email') {
            return filter_var((string)$s['manager_email'], FILTER_VALIDATE_EMAIL) !== false;
        }
        return preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', (string)$s['manager_phone']) === 1;
    }

    /** Raw destination used when sending the code (never expose this to the client). */
    public static function otpDestination(): string
    {
        $s = self::settings();
        return $s['otp_channel'] === 'email' ? (string)$s['manager_email'] : (string)$s['manager_phone'];
    }

    public static function maskedDestination(): string
    {
        $s = self::settings();
        if ($s['otp_channel'] === 'email') {
            $email = (string)$s['manager_email'];
            $at = strpos($email, '@');
            if ($at === false || $at < 1) {
                return $email;
            }
            return substr($email, 0, 1) . '***' . substr($email, $at);
        }
        $phone = preg_replace('/\s+/', '', (string)$s['manager_phone']);
        if (strlen($phone) <= 4) {
            return $phone;
        }
        return str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }

    public static function barcodeMatches(string $token): bool
    {
        $token = trim($token);
        $saved = (string)self::settings()['barcode_token'];
        if ($token === '' || $saved === '') {
            return false;
        }
        if (preg_match('~[?&]t=([A-Za-z0-9\-]+)~', $token, $m)) {
            $token = $m[1];
        }
        return hash_equals($saved, $token);
    }

    public static function approvalActive(string $scope = 'sale'): bool
    {
        $a = $_SESSION['pos_approval'] ?? null;
        if (!is_array($a) || empty($a['until']) || (int)$a['until'] < time()) {
            return false;
        }
        $granted = $a['scope'] ?? 'all';
        return $granted === 'all' || $granted === $scope;
    }

    public static function grantApproval(string $by, string $scope = 'all'): void
    {
        $_SESSION['pos_approval'] = [
            'until' => time() + 120,
            'by'    => $by,
            'scope' => $scope,
        ];
    }

    public static function clearApproval(): void
    {
        unset($_SESSION['pos_approval']);
    }

    /**
     * Compute the cart total from database prices and decide whether the
     * current user needs an approval for it.
     *
     * @return array{required:bool,total:float}
     */
    public static function evaluateSale(array $items, float $discountPercent = 0): array
    {
        $limit = self::saleLimit();
        if ($limit <= 0 || !self::roleIsRestricted()) {
            return ['required' => false, 'total' => 0.0];
        }

        $total = 0.0;
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("SELECT price FROM products WHERE id = ? LIMIT 1");
            foreach ($items as $item) {
                $id  = (int)($item['id'] ?? 0);
                $qty = (int)($item['qty'] ?? $item['quantity'] ?? 0);
                if ($id <= 0 || $qty <= 0) {
                    continue;
                }
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $total += (float)$row['price'] * $qty;
                }
            }
        } catch (Throwable $e) {
            error_log('PosSecurity total check failed: ' . $e->getMessage());
            return ['required' => false, 'total' => 0.0];
        }

        $total = round($total * (1 - $discountPercent / 100), 2);
        return ['required' => $total > $limit, 'total' => $total];
    }

    /** Values exposed to the POS pages. */
    public static function clientConfig(): array
    {
        $s = self::settings();
        return [
            'saleLimit'      => (float)$s['sale_limit'],
            'removeApproval' => (bool)$s['remove_approval'],
            'mode'           => (string)$s['approval_mode'],
            'fingerprint'    => self::fingerprintEnrolled(),
            'barcode'        => self::barcodeConfigured(),
            'otp'            => self::otpConfigured(),
            'otpChannel'     => self::otpChannel(),
            'otpMasked'      => self::otpConfigured() ? self::maskedDestination() : '',
            'restricted'     => self::roleIsRestricted(),
        ];
    }
}
