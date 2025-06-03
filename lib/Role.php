<?php
// /lib/Role.php
require_once __DIR__ . '/db.php';

class Role
{
    public static function all(): array
    {
        return getPDO()->query("SELECT * FROM roles ORDER BY name")->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = getPDO()->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, string $description = ''): int
    {
        $stmt = getPDO()->prepare("
            INSERT INTO roles (name, description)
            VALUES (:name, :desc)
        ");
        $stmt->execute([
            ':name' => $name,
            ':desc' => $description,
        ]);
        return (int)getPDO()->lastInsertId();
    }

    public static function update(int $id, string $name, string $description = ''): bool
    {
        return getPDO()->prepare("
            UPDATE roles
               SET name = ?, description = ?
             WHERE id = ?
        ")->execute([$name, $description, $id]);
    }

    public static function delete(int $id): bool
    {
        return getPDO()->prepare("DELETE FROM roles WHERE id = ?")
                        ->execute([$id]);
    }
}
