<?php
/**
 * TechShift POS — Global Bootstrap
 *
 * Loaded by every page. Defines constants, starts the session,
 * registers the autoloader, and exposes helper shims.
 */

// ── Timezone ──────────────────────────────────────────────────────────────────
// Will be set dynamically from store_settings after Database class loads
// Default fallback
date_default_timezone_set('Africa/Nairobi');

// ── Database (PostgreSQL) ─────────────────────────────────────────────────────
define('DB_HOST', getenv('POS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('POS_DB_PORT') ?: 5432));
define('DB_NAME', getenv('POS_DB_NAME') ?: 'pos');
define('DB_USER', getenv('POS_DB_USER') ?: 'admin');
define('DB_PASS', getenv('POS_DB_PASS') ?: 'admin');

// ── Application ───────────────────────────────────────────────────────────────
if (getenv('POS_BASE_URL')) {
    define('SITE_URL', rtrim(getenv('POS_BASE_URL'), '/'));
} elseif (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('SITE_URL', $scheme . '://' . $_SERVER['HTTP_HOST'] . '/pos');
} else {
    define('SITE_URL', 'http://localhost/pos');
}
define('SITE_NAME', 'TechShift POS');
require_once __DIR__ . '/version.php';

// ── Support ───────────────────────────────────────────────────────────────────
// Inbox for assistant / support tickets. Override with POS_SUPPORT_EMAIL when
// the final support address is confirmed.
define('SUPPORT_EMAIL', getenv('POS_SUPPORT_EMAIL') ?: 'dantech1438@gmail.com');

// ── Roles ─────────────────────────────────────────────────────────────────────
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_ADMIN',       'admin');
define('ROLE_STAFF',       'staff');
define('ROLE_CASHIER',     'cashier');

// ── PostgreSQL compatibility layer ───────────────────────────────────────────
require_once __DIR__ . '/classes/SqlTranslator.php';
require_once __DIR__ . '/classes/PgCompat.php';

// ── Autoloader ────────────────────────────────────────────────────────────────
// Searches: app/core/, app/controllers/, app/helpers/
// Falls back to legacy paths: classes/, controllers/, helpers/
spl_autoload_register(function (string $className): void {
    $search = [
        __DIR__ . '/app/core/',
        __DIR__ . '/app/controllers/',
        __DIR__ . '/app/helpers/',
        // legacy — keep until all references are migrated
        __DIR__ . '/classes/',
        __DIR__ . '/controllers/',
        __DIR__ . '/helpers/',
    ];
    foreach ($search as $dir) {
        $file = $dir . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Two-factor gate ──────────────────────────────────────────────────────────
// A password-verified user with 2FA enabled stays on verify_2fa.php until the
// one-time code is confirmed.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['tfa'])) {
    $tfaPage = basename($_SERVER['PHP_SELF'] ?? '');
    if (!in_array($tfaPage, ['verify_2fa.php', 'login.php', 'login_secure.php', 'logout.php', 'license.php', 'terms.php', 'accept_terms.php'], true)) {
        header('Location: ' . SITE_URL . '/verify_2fa.php');
        exit();
    }
}

// ── Licence gate ──────────────────────────────────────────────────────────────
// The app is unusable until a valid key for this machine is stored.
// license.php and terms.php must stay reachable so the customer can activate.
if (PHP_SAPI !== 'cli' && !defined('TSPOS_SKIP_LICENCE')) {
    $selfPage = basename($_SERVER['PHP_SELF'] ?? '');
    if (!in_array($selfPage, ['license.php', 'terms.php', 'logout.php', 'licensing.php'], true) && !License::isActive()) {
        header('Location: ' . SITE_URL . '/license.php');
        exit();
    }
}

// Backfill store_id into session for existing logged-in users who logged in before multi-store
if (!empty($_SESSION['user_id']) && !isset($_SESSION['store_id'])) {
    try {
        $tmpDb  = Database::getInstance()->getConnection();
        $tmpSt  = $tmpDb->prepare("SELECT store_id FROM users WHERE id=? LIMIT 1");
        $tmpSt->execute([$_SESSION['user_id']]);
        $tmpRow = $tmpSt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['store_id'] = (int)($tmpRow['store_id'] ?? 1);
    } catch (Throwable $e) {
        $_SESSION['store_id'] = 1;
    }
}

// ── Legacy mysqli-compatible connection (backed by PDO/PostgreSQL) ───────────
$conn = new MysqliCompat(Database::getInstance()->getConnection());

// ── Terms acceptance gate (signed-in users) ──────────────────────────────────
// Recorded per installation when the admin accepts the agreement at first login.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['user_id'])) {
    $selfPage = basename($_SERVER['PHP_SELF'] ?? '');
    $eulaSkip = ['accept_terms.php', 'terms.php', 'logout.php', 'license.php', 'verify_2fa.php'];
    if (!in_array($selfPage, $eulaSkip, true)) {
        if (($_SESSION['eula_ok'] ?? '') !== Eula::VERSION) {
            if (Eula::isAccepted()) {
                $_SESSION['eula_ok'] = Eula::VERSION;
            } else {
                header('Location: ' . SITE_URL . '/accept_terms.php');
                exit();
            }
        }
    }
}

// ── Load timezone from store settings ─────────────────────────────────────────
try {
    $tzQuery = $conn->query("SELECT setting_value FROM store_settings WHERE setting_key='timezone' LIMIT 1");
    if ($tzQuery && $tzRow = $tzQuery->fetch_assoc()) {
        $timezone = $tzRow['setting_value'];
        if (!empty($timezone) && in_array($timezone, timezone_identifiers_list())) {
            date_default_timezone_set($timezone);
            Database::getInstance()->getConnection()
                ->exec("SET TIME ZONE " . Database::getInstance()->getConnection()->quote($timezone));
        }
    }
} catch (Exception $e) {
    // Use default timezone if query fails
}

// ── Helper shims (backward-compat wrappers around User static methods) ───────
function isLoggedIn(): bool          { return User::isLoggedIn(); }
function isAdmin(): bool             { return User::isAdmin(); }
function isSuperAdmin(): bool        { return User::isSuperAdmin(); }
function requireLogin(): void        { User::requireLogin(); }
function requireAdmin(): void        { User::requireAdmin(); }
function requireSuperAdmin(): void   { User::requireSuperAdmin(); }

function redirect(string $url): never {
    header("Location: $url");
    exit();
}

function formatCurrency($amount): string {
    return Layout::formatCurrency($amount ?? 0);
}

function paymentMethodLabel($method): string {
    $m = strtolower(trim((string)$method));
    return ['mpesa' => 'M-Pesa', 'stripe' => 'Stripe', 'card' => 'Card', 'cash' => 'Cash'][$m] ?? ucfirst($m);
}

/**
 * Fetch the store's currency symbol from store_settings.
 * Cached per-request via a static variable.
 */
function getCurrencySymbol(): string {
    static $sym = null;
    if ($sym === null) {
        try {
            $db  = Database::getInstance()->getConnection();
            $r   = $db->query("SELECT setting_value FROM store_settings WHERE setting_key='currency_symbol' LIMIT 1");
            $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : false;
            $sym = ($row && $row['setting_value'] !== '') ? $row['setting_value'] : '₱';
        } catch (Exception $e) {
            $sym = '₱';
        }
        if (strtoupper(trim($sym)) === 'KSH') $sym = 'KSh';
    }
    return $sym;
}
