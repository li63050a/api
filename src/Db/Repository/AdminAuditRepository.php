<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class AdminAuditRepository
{
    public function __construct(private Database $db) {}

    public function log(int $adminId, string $action, string $detail, string $ip): void
    {
        $this->db->execute(
            'INSERT INTO admin_audit (admin_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
            [$adminId, $action, $detail, $ip, time()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM admin_audit ORDER BY id DESC LIMIT ?',
            [$limit]
        );
    }
}
