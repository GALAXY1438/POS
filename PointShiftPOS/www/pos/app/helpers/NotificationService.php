<?php
/**
 * NotificationService — in-app alert generation and delivery.
 *
 * Stock alerts are kept in sync with the products table: one notification per
 * product issue (stock / expiry). Resolved issues disappear automatically.
 * Optional SMS alerts go to the number configured under Settings → SMS.
 */
class NotificationService
{
    private const STOCK_TYPES = ['out_of_stock', 'low_stock', 'expired', 'expiring'];

    private const SEVERITY_RANK = ['info' => 0, 'warning' => 1, 'danger' => 2];

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /**
     * Align the notifications table with current product stock/expiry state.
     *
     * @return array{created:int,updated:int,removed:int}
     */
    public static function sync(int $storeId = 0, bool $sendSms = false): array
    {
        $db = self::db();

        $filter = $storeId > 0 ? ' AND p.store_id = ?' : '';
        $params = $storeId > 0 ? [$storeId] : [];

        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.stock_quantity, p.low_stock_threshold,
                    p.expiry, p.unit, p.store_id
             FROM products p
             WHERE p.status = 'active'
               AND (p.stock_quantity <= p.low_stock_threshold
                    OR (p.expiry IS NOT NULL AND p.expiry <= CURRENT_DATE + 30))" . $filter
        );
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alerts = self::buildAlerts($products);
        $existing = self::existingStockAlerts($storeId);
        $smsEnabled = self::alertPhone() !== '';

        $created = 0;
        $updated = 0;

        $insert = $db->prepare(
            "INSERT INTO notifications
                (store_id, type, severity, title, message, link, dedup_key, product_id,
                 is_read, sms_status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,0,?,NOW(),NOW())"
        );
        $update = $db->prepare(
            "UPDATE notifications
             SET store_id=?, type=?, severity=?, title=?, message=?, link=?, product_id=?,
                 updated_at=NOW()
             WHERE id=?"
        );
        $escalate = $db->prepare(
            "UPDATE notifications
             SET store_id=?, type=?, severity=?, title=?, message=?, link=?, product_id=?,
                 is_read=0, sms_status=?, created_at=NOW(), updated_at=NOW()
             WHERE id=?"
        );

        foreach ($alerts as $key => $a) {
            if (isset($existing[$key])) {
                $old = $existing[$key];
                $changed = $old['title'] !== $a['title']
                    || $old['message'] !== $a['message']
                    || $old['type'] !== $a['type']
                    || $old['severity'] !== $a['severity']
                    || $old['link'] !== $a['link'];

                if (!$changed) {
                    continue;
                }

                $isEscalation = self::SEVERITY_RANK[$a['severity']] > self::SEVERITY_RANK[$old['severity']]
                    || $old['type'] !== $a['type'];

                $smsStatus = $old['sms_status'];
                if ($isEscalation && $smsEnabled) {
                    $smsStatus = 'pending';
                }
                if ($isEscalation) {
                    $escalate->execute([
                        $a['store_id'], $a['type'], $a['severity'], $a['title'],
                        $a['message'], $a['link'], $a['product_id'], $smsStatus, $old['id'],
                    ]);
                } else {
                    $update->execute([
                        $a['store_id'], $a['type'], $a['severity'], $a['title'],
                        $a['message'], $a['link'], $a['product_id'], $old['id'],
                    ]);
                }
                $updated++;
                continue;
            }

            $insert->execute([
                $a['store_id'], $a['type'], $a['severity'], $a['title'], $a['message'],
                $a['link'], $key, $a['product_id'], $smsEnabled ? 'pending' : 'none',
            ]);
            $created++;
        }

        // Remove alerts whose condition no longer applies.
        $stale = array_diff(array_keys($existing), array_keys($alerts));
        $removed = 0;
        if ($stale) {
            $placeholders = implode(',', array_fill(0, count($stale), '?'));
            $sql = "DELETE FROM notifications
                    WHERE dedup_key IN ($placeholders)
                      AND type IN ('out_of_stock','low_stock','expired','expiring')";
            $delParams = array_values($stale);
            if ($storeId > 0) {
                $sql .= ' AND (store_id = ? OR store_id IS NULL)';
                $delParams[] = $storeId;
            }
            $del = $db->prepare($sql);
            $del->execute($delParams);
            $removed = $del->rowCount();
        }

        if ($sendSms) {
            self::dispatchPending($storeId, 3);
        }

        return ['created' => $created, 'updated' => $updated, 'removed' => $removed];
    }

    private static function buildAlerts(array $products): array
    {
        $today = strtotime(date('Y-m-d'));
        $alerts = [];

        foreach ($products as $p) {
            $qty  = (int)($p['stock_quantity'] ?? 0);
            $min  = (int)($p['low_stock_threshold'] ?? 0);
            $unit = trim((string)($p['unit'] ?? '')) ?: 'units';
            $sid  = (int)($p['store_id'] ?? 0) ?: null;
            $pid  = (int)$p['id'];
            $name = (string)$p['name'];

            if ($qty <= 0) {
                $alerts['stock:product:' . $pid] = [
                    'type'       => 'out_of_stock',
                    'severity'   => 'danger',
                    'title'      => 'Out of stock: ' . $name,
                    'message'    => 'No units left. Restock this product immediately.',
                    'link'       => '/pos/admin/inventory.php?stock_filter=out_of_stock',
                    'product_id' => $pid,
                    'store_id'   => $sid,
                ];
            } elseif ($qty <= $min) {
                $alerts['stock:product:' . $pid] = [
                    'type'       => 'low_stock',
                    'severity'   => 'warning',
                    'title'      => 'Low stock: ' . $name,
                    'message'    => $qty . ' ' . $unit . ' left (minimum ' . $min . '). Restock soon.',
                    'link'       => '/pos/admin/inventory.php?stock_filter=low_stock',
                    'product_id' => $pid,
                    'store_id'   => $sid,
                ];
            }

            if (!empty($p['expiry']) && $qty > 0) {
                $exp  = strtotime($p['expiry'] . ' 00:00:00');
                $days = (int)floor(($exp - $today) / 86400);

                if ($days < 0) {
                    $alerts['expiry:product:' . $pid] = [
                        'type'       => 'expired',
                        'severity'   => 'danger',
                        'title'      => 'Expired: ' . $name,
                        'message'    => 'Expired on ' . date('d M Y', $exp) . '; ' . $qty . ' ' . $unit . ' still in stock.',
                        'link'       => '/pos/admin/inventory.php',
                        'product_id' => $pid,
                        'store_id'   => $sid,
                    ];
                } elseif ($days <= 30) {
                    $when = $days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
                    $alerts['expiry:product:' . $pid] = [
                        'type'       => 'expiring',
                        'severity'   => $days <= 7 ? 'warning' : 'info',
                        'title'      => 'Expiring soon: ' . $name,
                        'message'    => 'Expires ' . $when . ' (' . date('d M Y', $exp) . '); ' . $qty . ' ' . $unit . ' in stock.',
                        'link'       => '/pos/admin/inventory.php',
                        'product_id' => $pid,
                        'store_id'   => $sid,
                    ];
                }
            }
        }

        return $alerts;
    }

    private static function existingStockAlerts(int $storeId): array
    {
        $db = self::db();
        $sql = "SELECT id, store_id, type, severity, title, message, link, dedup_key,
                       product_id, sms_status
                FROM notifications
                WHERE type IN ('out_of_stock','low_stock','expired','expiring')";
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = ? OR store_id IS NULL)';
            $params[] = $storeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['dedup_key']] = $r;
        }
        return $rows;
    }

    public static function unreadCount(int $storeId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE is_read = 0';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = ? OR store_id IS NULL)';
            $params[] = $storeId;
        }
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function latest(int $storeId = 0, int $limit = 8, bool $onlyUnread = false): array
    {
        $sql = 'SELECT id, store_id, type, severity, title, message, link, product_id,
                       is_read, created_at
                FROM notifications WHERE 1=1';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = ? OR store_id IS NULL)';
            $params[] = $storeId;
        }
        if ($onlyUnread) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(50, $limit));

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['is_read']  = (int)$r['is_read'];
            $r['time_ago'] = self::timeAgo($r['created_at']);
        }
        unset($r);

        return $rows;
    }

    public static function markRead(int $id): void
    {
        $stmt = self::db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function markAllRead(int $storeId = 0): void
    {
        $sql = 'UPDATE notifications SET is_read = 1 WHERE is_read = 0';
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = ? OR store_id IS NULL)';
            $params[] = $storeId;
        }
        self::db()->prepare($sql)->execute($params);
    }

    /**
     * Create a one-off system notification (test alerts, backups, etc.).
     */
    public static function createSystem(
        string $title,
        string $message,
        string $severity = 'info',
        ?string $link = null,
        ?int $storeId = null,
        string $type = 'system'
    ): int {
        $db = self::db();
        $stmt = $db->prepare(
            "INSERT INTO notifications
                (store_id, type, severity, title, message, link, dedup_key, is_read, sms_status)
             VALUES (?,?,?,?,?,?,?,0,'none')"
        );
        $stmt->execute([
            $storeId ?: null,
            $type,
            $severity,
            $title,
            $message,
            $link,
            'system:' . bin2hex(random_bytes(8)),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * The number that receives SMS alerts (Settings → SMS), or the shop phone.
     */
    public static function alertPhone(): string
    {
        $settings = self::alertSettings();
        if (($settings['sms_enable_alerts'] ?? '0') !== '1') {
            return '';
        }

        $phone = trim((string)($settings['sms_alert_phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string)($settings['store_phone'] ?? ''));
        }
        return self::normalizePhone($phone);
    }

    public static function smsAlertsEnabled(): bool
    {
        $settings = self::alertSettings();
        return ($settings['sms_enable_alerts'] ?? '0') === '1';
    }

    private static function alertSettings(): array
    {
        try {
            $stmt = self::db()->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN ('sms_enable_alerts','sms_alert_phone','store_phone')"
            );
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Send pending alert SMS messages (limited, best effort).
     */
    public static function dispatchPending(int $storeId = 0, int $limit = 2): int
    {
        $phone = self::alertPhone();
        if ($phone === '') {
            return 0;
        }

        $sql = "SELECT id, title, message FROM notifications
                WHERE sms_status = 'pending'";
        $params = [];
        if ($storeId > 0) {
            $sql .= ' AND (store_id = ? OR store_id IS NULL)';
            $params[] = $storeId;
        }
        $sql .= ' ORDER BY created_at ASC LIMIT ' . max(1, min(5, $limit));

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return 0;
        }

        $db = self::db();
        $markSent = $db->prepare("UPDATE notifications SET sms_status='sent', sms_error=NULL WHERE id=?");
        $markFail = $db->prepare("UPDATE notifications SET sms_status='failed', sms_error=? WHERE id=?");
        $sent = 0;

        foreach ($rows as $r) {
            $text = 'TechShift: ' . $r['title'] . '. ' . $r['message'];
            $text = mb_substr($text, 0, 160);

            try {
                $result = Sms::send($phone, $text, null, 8);
            } catch (Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage()];
            }

            if (!empty($result['success'])) {
                $markSent->execute([$r['id']]);
                $sent++;
            } else {
                $markFail->execute([mb_substr((string)($result['message'] ?? 'Send failed'), 0, 250), $r['id']]);
            }
        }

        return $sent;
    }

    /**
     * Normalise Kenyan numbers to 2547XXXXXXXX; leave other formats intact.
     */
    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^0-9+]/', '', $phone);
        $p = ltrim((string)$p, '+');

        if (preg_match('/^0([17]\d{8})$/', $p, $m)) {
            return '254' . $m[1];
        }
        if (preg_match('/^([17]\d{8})$/', $p, $m)) {
            return '254' . $m[1];
        }
        return $p;
    }

    private static function timeAgo(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $diff = time() - strtotime($datetime);
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int)floor($diff / 60) . ' min ago';
        }
        if ($diff < 86400) {
            return (int)floor($diff / 3600) . ' hr ago';
        }
        if ($diff < 172800) {
            return 'yesterday';
        }
        return date('d M', strtotime($datetime));
    }
}
