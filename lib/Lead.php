<?php
// /lib/Lead.php
require_once __DIR__ . '/db.php';

class Lead
{
    /**
     * Recupera todos los leads junto con su fuente e interés.
     * Nota: El sistema NO usa status_id en la tabla leads.
     */
    public static function all(): array
    {
        return getPDO()->query("
            SELECT 
                l.*, 
                so.name  AS source,
                ii.name  AS interest
            FROM leads l
            LEFT JOIN lead_sources       so ON l.source_id              = so.id
            LEFT JOIN insurance_interests ii ON l.insurance_interest_id = ii.id
            ORDER BY l.created_at DESC
        ")->fetchAll();
    }

    /**
     * Busca un lead por su ID.
     */
    public static function find(int $id): ?array
    {
        $stmt = getPDO()->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crea un nuevo lead con los datos proporcionados.
     * Devuelve el ID recién insertado.
     */
    public static function create(array $data): int
    {
        // Lista de columnas reales en la tabla `leads` (sin `status_id`)
        $fields = [
            'external_id',
            'prefix',
            'first_name',
            'mi',
            'last_name',
            'phone',
            'email',
            'address_line',
            'suite_apt',
            'city',
            'state',
            'zip5',
            'zip4',
            'delivery_point_bar_code',
            'carrier_route',
            'fips_county_code',
            'county_name',
            'age',
            'insurance_interest_id',
            'source_id',
            'do_not_call',
            'taken_by',
            'taken_at',
            'income',
            'language',
            'notes',
            'uploaded_by'
        ];

        $placeholders = array_map(fn($f) => ":$f", $fields);
        $sql = "INSERT INTO leads (" . implode(',', $fields) . ")
                VALUES (" . implode(',', $placeholders) . ")";

        $stmt = getPDO()->prepare($sql);
        foreach ($fields as $f) {
            $stmt->bindValue(":$f", $data[$f] ?? null);
        }
        $stmt->execute();

        return (int)getPDO()->lastInsertId();
    }

    /**
     * Actualiza un lead existente con los datos proporcionados.
     */
    public static function update(int $id, array $data): bool
    {
        $sets   = [];
        $params = [':id' => $id];
        foreach ($data as $key => $value) {
            $sets[]          = "$key = :$key";
            $params[":$key"] = $value;
        }
        $sql = "UPDATE leads SET " . implode(', ', $sets) . " WHERE id = :id";
        return getPDO()->prepare($sql)->execute($params);
    }

    /**
     * Elimina un lead por su ID.
     */
    public static function delete(int $id): bool
    {
        return getPDO()->prepare("DELETE FROM leads WHERE id = ?")
                        ->execute([$id]);
    }

    /**
     * Inserta o actualiza un lead según su external_id.
     * Si existe external_id, actualiza; si no, inserta.
     */
    public static function upsertByExternalId(array $data): int
    {
        $pdo    = getPDO();
        $exists = $pdo->prepare("SELECT id FROM leads WHERE external_id = ?");
        $exists->execute([$data['external_id']]);
        if ($row = $exists->fetch()) {
            static::update((int)$row['id'], $data);
            return (int)$row['id'];
        }
        return static::create($data);
    }
}