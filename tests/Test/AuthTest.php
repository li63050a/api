<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\AdminUserRepository;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\UserRepository;
use App\Db\Schema;
use App\Domain\Auth\AdminAuth;
use App\Domain\Auth\ApiKeyAuth;
use App\Support\Config;
use App\Support\Exception\HttpException;
use Tests\Framework;

function authDb(): Database
{
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    return $db;
}

Framework::test('ApiKeyAuth: O(1) lookup by sha256 then bcrypt verify', function (): void {
    $db = authDb();
    (new UserRepository($db))->create(['username' => 'u1', 'status' => 1, 'balance' => 10.0, 'created_at' => time(), 'updated_at' => time()]);
    $uid = (int)$db->lastInsertId();
    $raw = 'sk-test-' . bin2hex(random_bytes(16));
    $prefix = substr($raw, 0, 8);
    $keys = new ApiKeyRepository($db);
    $keys->create([
        'user_id' => $uid, 'key_prefix' => $prefix,
        'key_hash' => password_hash($raw, PASSWORD_DEFAULT),
        'key_sha256' => hash('sha256', $raw),
        'status' => 1, 'created_at' => time(),
    ]);
    $auth = new ApiKeyAuth($keys, new UserRepository($db));
    $ctx = $auth->authenticate($raw);
    Framework::assertSame($uid, (int)$ctx['user']['id']);
    Framework::assertTrue($auth->decryptTokenKey($ctx['key']) !== '');
    // 错误 token 抛 401
    Framework::assertThrows(
        fn () => $auth->authenticate('sk-bad-token'),
        HttpException::class,
        'bad token rejected'
    );
});

Framework::test('AdminAuth: login default admin forces must_change', function (): void {
    $db = authDb();
    $repo = new AdminUserRepository($db);
    $session = [];
    $auth = new AdminAuth($repo, $session);
    $admin = $auth->login('admin666', 'admin666');
    Framework::assertSame(1, (int)$admin['must_change']);
    Framework::assertSame(1, (int)$repo->find((int)$admin['id'])['must_change']);
    Framework::assertThrows(fn () => $auth->login('admin666', 'wrong'), HttpException::class);
});

Framework::test('AdminAuth: changeCredentials clears must_change', function (): void {
    $db = authDb();
    $repo = new AdminUserRepository($db);
    $session = [];
    $auth = new AdminAuth($repo, $session);
    $admin = $auth->login('admin666', 'admin666');
    $auth->changeCredentials((int)$admin['id'], 'newname', 'newpass123');
    $fresh = $repo->find((int)$admin['id']);
    Framework::assertSame('newname', $fresh['username']);
    Framework::assertSame(0, (int)$fresh['must_change']);
    Framework::assertTrue(password_verify('newpass123', $fresh['password_hash']));
});
