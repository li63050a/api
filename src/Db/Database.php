<?php
declare(strict_types=1);

namespace App\Db;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $pass = '', array $options = [])
    {
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->pdo = new PDO($dsn, $user, $pass, $defaults + $options);
    }

    public function pdo(): PDO { return $this->pdo; }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return int 受影响行数 */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return (string)$this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): bool
    {
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("prepare failed: {$sql}");
        }
        $stmt->execute($params);
        return $stmt;
    }
}
