<?php
/**
 * AuditLog — writes one row per notable action into audit_logs.
 * Never throws: logging must not break the operation being logged.
 */
class AuditLog
{
    /**
     * @param string $action      dotted action key, e.g. sale.completed, auth.login_failed
     * @param string $description human-readable sentence
     * @param array  $meta        extra context stored as JSON
     * @param array|null $actor   ['user_id','username','role','store_id'] for actions before/without a session
     */
    public static function log(string $action, string $description = '', array $meta = [], ?array $actor = null): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $a  = $actor ?? self::currentActor();
            $category = strpos($action, '.') !== false ? substr($action, 0, strpos($action, '.')) : 'general';

            $st = $db->prepare(
                'INSERT INTO audit_logs
                    (user_id, username, role, store_id, category, action, description, ip, user_agent, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                isset($a['user_id']) && (int)$a['user_id'] > 0 ? (int)$a['user_id'] : null,
                isset($a['username']) ? mb_substr((string)$a['username'], 0, 100) : null,
                isset($a['role']) ? mb_substr((string)$a['role'], 0, 30) : null,
                isset($a['store_id']) ? (int)$a['store_id'] : null,
                $category,
                mb_substr($action, 0, 60),
                $description !== '' ? $description : null,
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog write failed: ' . $e->getMessage());
        }
    }

    public static function currentActor(): array
    {
        return [
            'user_id'  => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role'     => $_SESSION['role'] ?? null,
            'store_id' => $_SESSION['store_id'] ?? null,
        ];
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return mb_substr((string)$ip, 0, 60);
    }
}
