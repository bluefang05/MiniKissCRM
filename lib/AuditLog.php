<?php
// /lib/AuditLog.php
require_once __DIR__ . '/db.php';

class AuditLog
{
    /**
     * Registra una acción en audit_logs.
     */
    public static function log(int $userId, string $action, string $description = ''): void
    {
        $stmt = getPDO()->prepare("
            INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent)
            VALUES (:uid, :action, :desc, :ip, :ua)
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':action' => $action,
            ':desc'   => $description,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }
}
