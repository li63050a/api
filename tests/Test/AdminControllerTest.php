<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Admin\AdminController;
use App\Db\Database;
use App\Db\Repository\AdminUserRepository;
use App\Db\Schema;
use App\Domain\Auth\AdminAuth;
use App\Http\Request;
use App\Support\Config;
use Tests\Framework;

Framework::test('AdminController: unauthenticated action rejected', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $controller = new AdminController(
        new AdminAuth(new AdminUserRepository($db), $session),
        $db,
        new Config([]),
    );
    $resp = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'dashboard'], null, ''));
    $data = json_decode($resp->body(), true);
    Framework::assertSame(false, $data['ok']);
    Framework::assertSame('unauthorized', $data['error']['type']);
});

Framework::test('AdminController: must_change blocks dashboard', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $auth = new AdminAuth(new AdminUserRepository($db), $session);
    $auth->login('admin666', 'admin666');
    $controller = new AdminController($auth, $db, new Config([]));
    $req = new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'dashboard'], null, '');
    $resp = $controller->dispatch($req);
    $data = json_decode($resp->body(), true);
    Framework::assertSame(false, $data['ok']);
    Framework::assertSame('must_change', $data['error']['type']);
});

Framework::test('AdminController: change credentials then dashboard allowed', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $auth = new AdminAuth(new AdminUserRepository($db), $session);
    $auth->login('admin666', 'admin666');
    $controller = new AdminController($auth, $db, new Config([]));
    $save = $controller->dispatch(new Request(
        'POST',
        '/',
        ['X-Requested-With' => 'fetch'],
        [],
        json_encode(['action' => 'profile.save', 'username' => 'boss', 'password' => 'verysecret123']),
        ''
    ));
    Framework::assertSame(true, json_decode($save->body(), true)['ok']);
    $dash = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'dashboard'], null, ''));
    Framework::assertSame(true, json_decode($dash->body(), true)['ok']);
});
