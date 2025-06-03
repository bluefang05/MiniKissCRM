<?php
// /lib/Interaction.php
require_once __DIR__ . '/db.php';

class Interaction
{
    public static function create(array $data): int
    {
        $fields = ['lead_id','user_id','disposition_id','notes','duration_seconds'];
        $place = array_map(fn($f)=>":$f", $fields);
        $sql = "INSERT INTO interactions (".implode(',',$fields).")
                VALUES (".implode(',',$place).")";
        $stmt = getPDO()->prepare($sql);
        foreach ($fields as $f) {
            $stmt->bindValue(":$f", $data[$f] ?? null);
        }
        $stmt->execute();
        return (int)getPDO()->lastInsertId();
    }

    public static function forUser(int $userId): array
    {
        $stmt = getPDO()->prepare("
            SELECT i.*, d.name AS disposition
            FROM interactions i
            JOIN dispositions d ON i.disposition_id = d.id
            WHERE i.user_id = ?
            ORDER BY i.interaction_time DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function forLead(int $leadId): array
    {
        $stmt = getPDO()->prepare("
            SELECT i.*, d.name AS disposition, u.name AS agent
            FROM interactions i
            JOIN dispositions d ON i.disposition_id = d.id
            JOIN users u ON i.user_id = u.id
            WHERE i.lead_id = ?
            ORDER BY i.interaction_time DESC
        ");
        $stmt->execute([$leadId]);
        return $stmt->fetchAll();
    }
}
