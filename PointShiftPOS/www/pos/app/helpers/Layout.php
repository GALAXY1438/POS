<?php
/**
 * Layout — view-rendering utilities.
 */
class Layout
{
    /**
     * Format a monetary amount using the store's configured currency symbol.
     */
    public static function formatCurrency(float|string|int|null $amount): string
    {
        static $symbol = null;
        if ($symbol === null) {
            try {
                $db     = Database::getInstance()->getConnection();
                $stmt   = $db->query("SELECT setting_value FROM store_settings WHERE setting_key='currency_symbol' LIMIT 1");
                $row    = $stmt ? $stmt->fetch() : false;
                $symbol = ($row && $row['setting_value'] !== '') ? $row['setting_value'] : '₱';
            } catch (Exception) {
                $symbol = '₱';
            }
            if (strtoupper(trim($symbol)) === 'KSH') $symbol = 'KSh';
        }
        return $symbol . number_format((float)($amount ?? 0), 2);
    }

    /**
     * Return a human-friendly relative time string (e.g. "5min ago").
     */
    public static function getTimeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 3600)  return floor($diff / 60)   . 'min ago';
        if ($diff < 86400) return floor($diff / 3600)  . 'h ago';
        return date('M j, Y', strtotime($datetime));
    }

    /**
     * Build initials from first + last name stored in session.
     */
    public static function getInitials(): string
    {
        $first = $_SESSION['first_name'] ?? ($_SESSION['username'] ?? 'U');
        $last  = $_SESSION['last_name']  ?? '';
        return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    /**
     * Return the display name for the current session user.
     */
    public static function getDisplayName(): string
    {
        $first = $_SESSION['first_name'] ?? ($_SESSION['username'] ?? 'User');
        $last  = $_SESSION['last_name']  ?? '';
        return htmlspecialchars(trim("$first $last"), ENT_QUOTES, 'UTF-8');
    }
}
