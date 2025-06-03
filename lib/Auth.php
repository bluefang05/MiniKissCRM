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
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'name'     => $user['name'],
                'email'    => $user['email'],
            ];
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
}
