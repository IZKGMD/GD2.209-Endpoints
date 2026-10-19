<?php
declare(strict_types=1);

namespace MuchoCore\V71;

use PDO;

final class SchemaInspector
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
