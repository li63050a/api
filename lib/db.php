<?php
/**
 * DB 辅助函数
 */
function db_insert(\PDO $db, string $table, array $data): int
{
    $cols = array_keys($data);
    $ph = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    $stmt = $db->prepare($sql);
    foreach ($data as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();
    return (int) $db->lastInsertId();
}

function db_fetch(\PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_fetchall(\PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_update(\PDO $db, string $table, array $set, array $where): int
{
    $setStr = implode(',', array_map(fn($c) => "$c = :s_$c", array_keys($set)));
    $whereStr = implode(' AND ', array_map(fn($c) => "$c = :w_$c", array_keys($where)));
    $sql = "UPDATE $table SET $setStr WHERE $whereStr";
    $stmt = $db->prepare($sql);
    foreach ($set as $k => $v) {
        $stmt->bindValue(':s_' . $k, $v);
    }
    foreach ($where as $k => $v) {
        $stmt->bindValue(':w_' . $k, $v);
    }
    $stmt->execute();
    return $stmt->rowCount();
}
