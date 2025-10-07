<?php
// /lib/db.php
require_once __DIR__ . '/../config/config.php';

/**
 * Devuelve una única instancia de PDO conectada a la base de datos.
 *
 * @return PDO
 */
function getPDO(): PDO
{
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
