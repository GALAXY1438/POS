<?php
/**
 * AuthController — input validation layer for authentication.
 */
class AuthController
{
    private User $user;

    public function __construct()
    {
        $this->user = new User();
    }

    public function login(string $username, string $password): array
    {
        if (empty(trim($username)) || empty(trim($password))) {
            return ['success' => false, 'message' => 'Please fill in all fields.'];
        }

        $result = $this->user->login($username, $password);

        if (is_array($result) && isset($result['verified']) && !$result['verified']) {
            AuditLog::log('auth.login_unverified', "Sign-in blocked for '{$username}': email not verified.", [], [
                'user_id'  => $result['user_id'] ?? null,
                'username' => $username,
            ]);
            return [
                'success'            => false,
                'needs_verification' => true,
                'email'              => $result['email'] ?? '',
                'user_id'            => $result['user_id'] ?? null,
                'message'            => 'Please verify your email address before logging in.',
            ];
        }

        if ($result === true) {
            AuditLog::log('auth.login', "Signed in as '{$username}'.");
            return ['success' => true, 'message' => 'Login successful.'];
        }

        AuditLog::log('auth.login_failed', "Wrong username or password for '{$username}'.", [], [
            'username' => $username,
        ]);
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    public function register(array $data): array
    {
        $required = ['username', 'email', 'password', 'confirm_password', 'first_name', 'last_name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => 'Please fill in all fields.'];
            }
        }

        if ($data['password'] !== $data['confirm_password']) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }

        if (strlen($data['password']) < 6) {
            return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        // Prevent self-registration into privileged roles
        $allowed = [ROLE_STAFF, ROLE_CASHIER];
        if (!in_array($data['role'] ?? ROLE_STAFF, $allowed, true)) {
            $data['role'] = ROLE_STAFF;
        }

        if ($this->user->register($data)) {
            return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
        }

        return ['success' => false, 'message' => 'Username or email already exists.'];
    }

    public function logout(): void
    {
        User::logout();
    }
}
