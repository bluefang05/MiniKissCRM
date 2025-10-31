<?php
// /lib/User.php
require_once __DIR__ . '/db.php';

class User
{
    public static function all(): array
    {
        return getPDO()
            ->query("SELECT * FROM users ORDER BY name")
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = getPDO()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = getPDO()->prepare("
            INSERT INTO users (name, email, password_hash, status)
            VALUES (:name, :email, :pass, :status)
        ");
        $stmt->execute([
            ':name'   => $data['name'],
            ':email'  => $data['email'],
            ':pass'   => password_hash($data['password'], PASSWORD_DEFAULT),
            ':status' => $data['status'] ?? 'active',
        ]);
        return (int) getPDO()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[]       = 'name = :name';
            $params[':name']  = $data['name'];
        }
        if (isset($data['email'])) {
            $fields[]        = 'email = :email';
            $params[':email'] = $data['email'];
        }
        if (isset($data['password'])) {
            $fields[]       = 'password_hash = :pass';
            $params[':pass'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['status'])) {
            $fields[]         = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        return getPDO()->prepare($sql)->execute($params);
    }

    public static function delete(int $id): bool
    {
        $pdo = getPDO();

        // 1) Liberar leads asignados
        $pdo->prepare("UPDATE leads SET taken_by = NULL WHERE taken_by = ?")
            ->execute([$id]);

        // 2) Borrar dependencias para no violar FKs
        $pdo->prepare("DELETE FROM audit_logs WHERE user_id = ?")
            ->execute([$id]);
        $pdo->prepare("DELETE FROM interactions WHERE user_id = ?")
            ->execute([$id]);
        $pdo->prepare("DELETE FROM lead_locks WHERE user_id = ?")
            ->execute([$id]);
        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")
            ->execute([$id]);

        // 3) Finalmente, borrar el usuario
        return $pdo->prepare("DELETE FROM users WHERE id = ?")
                   ->execute([$id]);
    }
}
