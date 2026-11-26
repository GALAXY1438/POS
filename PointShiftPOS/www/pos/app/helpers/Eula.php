<?php
/**
 * End User Licence Agreement: content, rendering and acceptance tracking.
 */
class Eula
{
    public const VERSION = '1.0';

    private static ?bool $acceptedCache = null;

    public static function text(): string
    {
        $file = __DIR__ . '/../legal/eula.txt';
        $text = @file_get_contents($file);
        return $text === false ? 'The licence agreement is missing from this installation.' : $text;
    }

    public static function html(): string
    {
        $lines = preg_split('/\r\n|\n|\r/', self::text());
        $html  = '';
        $inList = false;

        $line = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };

        foreach ($lines as $index => $raw) {
            $t = trim($raw);

            if ($index === 0) {
                $html .= '<h1 class="eula-title">' . $line($t) . '</h1>';
                continue;
            }
            if ($index === 1 && stripos($t, 'Version') === 0) {
                $html .= '<p class="eula-meta">' . $line($t) . '</p>';
                continue;
            }
            if ($index === 2 && stripos($t, 'Effective date') === 0) {
                $html .= '<p class="eula-meta">' . $line($t) . '</p>';
                continue;
            }

            if ($t === '') {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                continue;
            }

            if (preg_match('/^\d+\.\s+[A-Z0-9][A-Z0-9 &\'",.()\-]+$/', $t)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h2>' . $line($t) . '</h2>';
                continue;
            }

            if (preg_match('/^\([a-f]\)\s+/', $t)) {
                if (!$inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>' . $line($t) . '</li>';
                continue;
            }

            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>' . $line($t) . '</p>';
        }

        if ($inList) { $html .= '</ul>'; }
        return $html;
    }

    public static function isAccepted(): bool
    {
        if (self::$acceptedCache !== null) {
            return self::$acceptedCache;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT setting_key, setting_value FROM store_settings
                              WHERE setting_key IN ('eula_version','eula_accepted_at')");
            $row = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $row[$r['setting_key']] = (string)$r['setting_value'];
            }
            return self::$acceptedCache = (($row['eula_version'] ?? '') === self::VERSION
                && ($row['eula_accepted_at'] ?? '') !== '');
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function accept(int $userId, string $userName): void
    {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO store_settings (setting_key, setting_value, setting_type)
                              VALUES (?, ?, 'text')
                              ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
        $values = [
            'eula_version'           => self::VERSION,
            'eula_accepted_at'       => date('c'),
            'eula_accepted_by'       => (string)$userId,
            'eula_accepted_by_name'  => $userName,
        ];
        $db->beginTransaction();
        try {
            foreach ($values as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::$acceptedCache = true;
    }

    public static function acceptedAt(): string
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT setting_value FROM store_settings WHERE setting_key='eula_accepted_at' LIMIT 1");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            return $row ? (string)$row['setting_value'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}