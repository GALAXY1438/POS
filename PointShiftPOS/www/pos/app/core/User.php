<?php
/**
 * User — authentication, session management, and role guards.
 *
 * Roles (lowest → highest privilege):
 *   cashier → staff → admin → super_admin
 */
class User
{
    private PDO $db;

    // Instance data (populated after a successful login)
    private int    $id;
    private string $username;
    private string $email;
    private string $role;
    private string $firstName;
    private string $lastName;
    private string $status;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function login(string $username, string $password): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM users
                 WHERE (username = ? OR LOWER(email) = LOWER(?)) AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $this->hydrate($user);
                $this->writeSession();
                return true;
            }
            return false;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Sign in an existing active account without a password
     * (used by fingerprint / badge login after the credential was verified).
     */
    public function loginById(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) return false;
            $this->hydrate($user);
            $this->writeSession();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function register(array $data): bool
    {
        try {
            $check = $this->db->prepare(
                'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1'
            );
            $check->execute([$data['username'], $data['email']]);
            if ($check->fetch()) {
                return false; // duplicate
            }

            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password, first_name, last_name, role, business_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['username'],
                $data['email'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['first_name'],
                $data['last_name'],
                $data['role'] ?? ROLE_STAFF,
                $data['business_type'] ?? null,
            ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    // ── Session helpers ───────────────────────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function isSuperAdmin(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_SUPER_ADMIN;
    }

    public static function isAdmin(): bool
    {
        // super_admin also passes admin checks
        return isset($_SESSION['role'])
            && in_array($_SESSION['role'], [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
    }

    public static function isStaff(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_STAFF;
    }

    public static function isCashier(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_CASHIER;
    }

    public static function isCustomer(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'customer';
    }

    public static function currentRole(): string
    {
        return $_SESSION['role'] ?? '';
    }

    // ── Route guards ─────────────────────────────────────────────────────────

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . SITE_URL . '/login.php');
            exit();
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            self::redirectToDashboard();
        }
    }

    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            self::redirectToDashboard();
        }
    }

    /**
     * Redirect the current user to their own dashboard.
     */
    public static function redirectToDashboard(): never
    {
        $role = self::currentRole();
        $map  = [
            ROLE_SUPER_ADMIN => SITE_URL . '/superadmin/dashboard.php',
            ROLE_ADMIN       => SITE_URL . '/admin/dashboard.php',
            ROLE_STAFF       => SITE_URL . '/staff/dashboard.php',
            ROLE_CASHIER     => SITE_URL . '/cashier/pos.php',  // Cashiers start at POS
            'customer'       => SITE_URL . '/customer/dashboard.php',
        ];
        header('Location: ' . ($map[$role] ?? SITE_URL . '/login.php'));
        exit();
    }

    public static function logout(): never
    {
        if (!empty($_SESSION['user_id'])) {
            AuditLog::log('auth.logout', "Signed out '" . ($_SESSION['username'] ?? '') . "'.");
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hydrate(array $user): void
    {
        $this->id        = (int) $user['id'];
        $this->username  = $user['username'];
        $this->email     = $user['email'];
        $this->role      = $user['role'];
        $this->firstName = $user['first_name'];
        $this->lastName  = $user['last_name'];
        $this->status    = $user['status'];
    }

    private function writeSession(): void
    {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id']    = $this->id;
        $_SESSION['username']   = $this->username;
        $_SESSION['role']       = $this->role;
        $_SESSION['first_name'] = $this->firstName;
        $_SESSION['last_name']  = $this->lastName;

        // Load store_id — cashiers/staff get their assigned store; admins default to 1
        try {
            $storeCol = $this->db->prepare("SELECT store_id FROM users WHERE id=? LIMIT 1");
            $storeCol->execute([$this->id]);
            $row = $storeCol->fetch();
            $_SESSION['store_id'] = (int)($row['store_id'] ?? 1);
        } catch (\Throwable $e) {
            $_SESSION['store_id'] = 1;
        }
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getId(): int        { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function getEmail(): string  { return $this->email; }
    public function getRole(): string   { return $this->role; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string  { return $this->lastName; }
    public function getFullName(): string  { return trim($this->firstName . ' ' . $this->lastName); }
    public function getStatus(): string { return $this->status; }
}
