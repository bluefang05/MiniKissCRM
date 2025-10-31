<?php
// /lib/LeadLock.php
require_once __DIR__ . '/db.php';

class LeadLock
{
    public static function acquire(int $leadId, int $userId, int $ttlMinutes = 15): bool
    {
        $pdo = getPDO();
        // Limpia expirados
        $pdo->prepare("DELETE FROM lead_locks WHERE expires_at < NOW()")->execute();

        // Comprueba existencia
        $stmt = $pdo->prepare("SELECT user_id FROM lead_locks WHERE lead_id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch();

        if ($row && (int)$row['user_id'] !== $userId) {
            return false;
        }

        $expiresAt = (new DateTime())->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');
        if ($row) {
            $sql = "UPDATE lead_locks SET user_id = :uid, locked_at = NOW(), expires_at = :exp WHERE lead_id = :lid";
        } else {
            $sql = "INSERT INTO lead_locks (lead_id, user_id, expires_at) VALUES (:lid, :uid, :exp)";
        }
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':lid' => $leadId,
            ':uid' => $userId,
            ':exp' => $expiresAt
        ]);
    }

    public static function check(int $leadId): ?array
    {
        $pdo = getPDO();
        $pdo->prepare("DELETE FROM lead_locks WHERE expires_at < NOW()")->execute();
        $stmt = $pdo->prepare("SELECT user_id, locked_at, expires_at FROM lead_locks WHERE lead_id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        return $stmt->fetch() ?: null;
    }

    public static function release(int $leadId, int $userId): void
    {
        getPDO()
            ->prepare("DELETE FROM lead_locks WHERE lead_id = ? AND user_id = ?")
            ->execute([$leadId, $userId]);
    }
}
