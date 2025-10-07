<?php
// /lib/Auth.php
require_once __DIR__ . '/db.php';
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

class Auth
{
    public static function login(string $email, string $password): bool
    {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'name'     => $user['name'],
                'email'    => $user['email'],
            ];

            // Fetch user roles and store in session
            $roles = self::getUserRoles($user['id']);
            $_SESSION['user']['roles'] = $roles;

            return true;
        }

        return false;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function logout(): void
    {
        session_unset();
        session_destroy();
    }

    // Get current user's roles from session or database
    public static function getRoles(): array
    {
        if (!isset($_SESSION['user']['roles'])) {
            $userId = self::user()['id'] ?? null;
            if ($userId) {
                $_SESSION['user']['roles'] = self::getUserRoles($userId);
            } else {
                return [];
            }
        }

        return $_SESSION['user']['roles'];
    }

    // Fetch roles for a given user ID from the database
    private static function getUserRoles(int $userId): array
    {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT r.name 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return $roles ?: [];
    }

    // Check if user has a specific role
    public static function hasRole(string $role): bool
    {
        $roles = self::getRoles();
        return in_array($role, $roles);
    }

    // Check if user is admin or owner
    public static function isAdminOrOwner(): bool
    {
        $roles = self::getRoles();
        return in_array('admin', $roles) || in_array('owner', $roles);
    }
}