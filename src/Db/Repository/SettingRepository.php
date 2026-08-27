<?php
declare(strict_types=1);

namespace App\Db\Repository;

use App\Db\Database;

final class SettingRepository
{
    public function __construct(private Database $db) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $this->db->value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $v === null || $v === '' ? $default : $v;
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, (string)$value]
        );
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT key, value FROM settings') as $row) {
            $out[(string)$row['key']] = (string)$row['value'];
        }
        return $out;
    }
}