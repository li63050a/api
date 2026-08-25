<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use Tests\Framework;

Framework::test('Database: open sqlite in-memory and CRUD', function (): void {
    $db = new Database('sqlite::memory:');
    $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    $db->execute('INSERT INTO t (name) VALUES (?)', ['alice']);
    Framework::assertSame(1, $db->value('SELECT COUNT(*) FROM t'));
    Framework::assertSame('alice', $db->value('SELECT name FROM t WHERE id = ?', [1]));
    $row = $db->fetchOne('SELECT * FROM t WHERE id = ?', [1]);
    Framework::assertSame('alice', $row['name']);
    $all = $db->fetchAll('SELECT * FROM t');
    Framework::assertSame(1, count($all));
    Framework::assertSame(1, $db->execute('DELETE FROM t WHERE id = ?', [1]));
});

Framework::test('Database: transaction rollback', function (): void {
    $db = new Database('sqlite::memory:');
    $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $ok = $db->transaction(function () use ($db): void {
        $db->execute('INSERT INTO t (v) VALUES (?)', ['x']);
        throw new RuntimeException('abort');
    });
    Framework::assertSame(false, $ok);
    Framework::assertSame(0, $db->value('SELECT COUNT(*) FROM t'));
});
