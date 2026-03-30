<?php
/**
 * Authentication Class - Session-based admin authentication
 */

declare(strict_types=1);

class Auth
{
    private Database $db;
    private const SESSION_USER_KEY = 'user_id';
    private const SESSION_IP_KEY = 'user_ip';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->startSession();
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->db->prepare("SELECT id, password, is_active FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $this->logFailedLogin($username);
            return false;
        }

        if (!$user['is_active']) {
            return false;
        }

        // Update last login
        $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Set session
        $_SESSION[self::SESSION_USER_KEY] = $user['id'];
        $_SESSION[self::SESSION_IP_KEY] = $this->getClientIP();
        $_SESSION['login_time'] = time();

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        if (!isset($_SESSION[self::SESSION_USER_KEY])) {
            return false;
        }

        // Check session timeout
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }

        // Check IP consistency (optional security measure)
        if (isset($_SESSION[self::SESSION_IP_KEY]) && $_SESSION[self::SESSION_IP_KEY] !== $this->getClientIP()) {
            $this->logout();
            return false;
        }

        return true;
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, username, email, created_at, last_login FROM users WHERE id = ?");
        $stmt->execute([$_SESSION[self::SESSION_USER_KEY]]);
        return $stmt->fetch() ?: null;
    }

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            $this->redirectToLogin();
        }
    }

    public function changePassword(int $userId, string $newPassword): bool
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $userId]);
    }

    private function logFailedLogin(string $username): void
    {
        error_log("Failed login attempt for username: $username from IP: " . $this->getClientIP());
    }

    private function getClientIP(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    private function redirectToLogin(): void
    {
        header('Location: /login');
        exit;
    }
}
