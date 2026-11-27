<?php
/**
 * ShiftManager — cash-drawer shift sessions for cashier/staff.
 * A shift is opened with an opening float, sales are tagged with its id,
 * and it is closed against a counted cash amount.
 */
class ShiftManager
{
    public static function current(?int $userId = null, ?int $storeId = null): array|false
    {
        $userId  = $userId  ?? (int)($_SESSION['user_id'] ?? 0);
        $storeId = $storeId ?? (int)($_SESSION['store_id'] ?? 1);
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT * FROM register_shifts
                 WHERE user_id = ? AND store_id = ? AND status = 'open'
                 ORDER BY opened_at DESC LIMIT 1"
            );
            $st->execute([$userId, $storeId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function open(float $openingFloat, string $notes = ''): array
    {
        $userId  = (int)($_SESSION['user_id'] ?? 0);
        $storeId = (int)($_SESSION['store_id'] ?? 1);

        $existing = self::current($userId, $storeId);
        if ($existing) {
            return ['success' => false, 'message' => 'You already have an open shift.', 'shift' => $existing];
        }

        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "INSERT INTO register_shifts (user_id, store_id, opening_float, status, notes, opened_at)
                 VALUES (?, ?, ?, 'open', ?, CURRENT_TIMESTAMP)"
            );
            $st->execute([$userId, $storeId, $openingFloat, $notes !== '' ? $notes : null]);
            $id = (int)$db->lastInsertId();
            $shift = self::find($id);
            AuditLog::log('shift.opened', 'Shift opened with float ' . number_format($openingFloat, 2, '.', '') . '.', [
                'shift_id' => $id,
                'opening_float' => $openingFloat,
            ]);
            return ['success' => true, 'message' => 'Shift opened.', 'shift' => $shift];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Shift could not be opened: ' . $e->getMessage()];
        }
    }

    public static function close(int $shiftId, float $closingCount, string $notes = ''): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $shift = self::find($shiftId);
            if (!$shift) {
                return ['success' => false, 'message' => 'Shift not found.'];
            }
            if ($shift['status'] !== 'open') {
                return ['success' => false, 'message' => 'This shift is already closed.'];
            }
            $own = (int)$shift['user_id'] === (int)($_SESSION['user_id'] ?? 0);
            if (!$own && !User::isAdmin()) {
                return ['success' => false, 'message' => 'You can only close your own shift.'];
            }

            $t = self::computeTotals($db, $shiftId);
            $expected = (float)$shift['opening_float'] + $t['cash'];

            $st = $db->prepare(
                "UPDATE register_shifts SET
                    closed_at = CURRENT_TIMESTAMP,
                    closing_count = ?, expected_cash = ?,
                    cash_sales = ?, mpesa_sales = ?, card_sales = ?, other_sales = ?,
                    total_sales = ?, orders_count = ?,
                    status = 'closed', notes = ?
                 WHERE id = ?"
            );
            $st->execute([
                $closingCount, $expected,
                $t['cash'], $t['mpesa'], $t['card'], $t['other'],
                $t['total'], $t['orders'],
                $notes !== '' ? $notes : $shift['notes'],
                $shiftId,
            ]);

            $summary = self::find($shiftId);
            $diff = round($closingCount - $expected, 2);
            AuditLog::log('shift.closed', 'Shift closed. Expected cash ' . number_format($expected, 2, '.', '')
                . ', counted ' . number_format($closingCount, 2, '.', '') . ', difference ' . number_format($diff, 2, '.', '') . '.', [
                'shift_id' => $shiftId,
                'expected_cash' => $expected,
                'closing_count' => $closingCount,
                'difference' => $diff,
                'total_sales' => $t['total'],
            ]);

            try {
                Integrations::fire('shift.closed', [
                    'shift_id' => $shiftId,
                    'opened_at' => $shift['opened_at'],
                    'closed_at' => $summary['closed_at'] ?? null,
                    'expected_cash' => $expected,
                    'closing_count' => $closingCount,
                    'difference' => $diff,
                    'total_sales' => $t['total'],
                    'orders' => $t['orders'],
                ]);
            } catch (Throwable $e) {
            }

            return ['success' => true, 'message' => 'Shift closed.', 'summary' => $summary, 'difference' => $diff];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Shift could not be closed: ' . $e->getMessage()];
        }
    }

    public static function find(int $shiftId): array|false
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare('SELECT * FROM register_shifts WHERE id = ? LIMIT 1');
            $st->execute([$shiftId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Live totals (not yet persisted) for an open shift. */
    public static function totals(int $shiftId): array
    {
        $db = Database::getInstance()->getConnection();
        return self::computeTotals($db, $shiftId);
    }

    private static function computeTotals(PDO $db, int $shiftId): array
    {
        $out = ['cash' => 0.0, 'mpesa' => 0.0, 'card' => 0.0, 'other' => 0.0, 'total' => 0.0, 'orders' => 0];
        try {
            $st = $db->prepare(
                "SELECT payment_method, COUNT(*) n, COALESCE(SUM(total_amount), 0) s
                 FROM orders
                 WHERE register_shift_id = ? AND status = 'completed'
                 GROUP BY payment_method"
            );
            $st->execute([$shiftId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $method = strtolower((string)$r['payment_method']);
                $sum = (float)$r['s'];
                $n   = (int)$r['n'];
                if ($method === 'cash') { $out['cash'] += $sum; }
                elseif ($method === 'mpesa') { $out['mpesa'] += $sum; }
                elseif ($method === 'stripe' || $method === 'card') { $out['card'] += $sum; }
                else { $out['other'] += $sum; }
                $out['total']  += $sum;
                $out['orders'] += $n;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
