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

Framework::test('AdminController: modelmap.save 校验 key_id（必填且与 provider 匹配）', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config(['admin_default_password' => 'admin666'])))->install();
    $session = [];
    $auth = new AdminAuth(new AdminUserRepository($db), $session);
    $auth->login('admin666', 'admin666');
    $controller = new AdminController($auth, $db, new Config([]));
    // 解除首登 must_change
    $controller->dispatch(new Request('POST', '/', ['X-Requested-With' => 'fetch'], [], json_encode(['action' => 'profile.save', 'username' => 'boss', 'password' => 'verysecret123']), ''));
    // 建供应商 + 一把密钥
    $controller->dispatch(new Request('POST', '/', ['X-Requested-With' => 'fetch'], [], json_encode(['action' => 'providers.save', 'name' => 'openai', 'base_url' => 'https://api.openai.com', 'client_format' => 'openai']), ''));
    $list = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'providers.list'], null, ''));
    $items = json_decode($list->body(), true)['data']['items'];
    $pid = (int)$items[0]['id'];
    $controller->dispatch(new Request('POST', '/', ['X-Requested-With' => 'fetch'], [], json_encode(['action' => 'upstream.key.save', 'provider_id' => $pid, 'key_value' => 'sk-test', 'status' => 1, 'weight' => 1]), ''));
    $list2 = $controller->dispatch(new Request('GET', '/', ['X-Requested-With' => 'fetch'], ['action' => 'providers.list'], null, ''));
    $keys = json_decode($list2->body(), true)['data']['items'][0]['upstream_keys'];
    $keyId = (int)$keys[0]['id'];

    $save = function (array $b) use ($controller) {
        return json_decode($controller->dispatch(new Request('POST', '/', ['X-Requested-With' => 'fetch'], [], json_encode(array_merge(['action' => 'modelmap.save'], $b)), ''))->body(), true);
    };

    // 缺 key_id → 422
    $bad = $save(['alias' => 'gpt-4o', 'provider' => 'openai']);
    Framework::assertSame(false, $bad['ok']);
    Framework::assertSame('invalid_request', $bad['error']['type']);
    // key_id 与 provider 不匹配 → 422
    $bad2 = $save(['alias' => 'gpt-4o', 'provider' => 'anthropic', 'key_id' => $keyId]);
    Framework::assertSame(false, $bad2['ok']);
    // 正确保存（模型挂到具体密钥）
    $ok = $save(['alias' => 'gpt-4o', 'provider' => 'openai', 'key_id' => $keyId, 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1]);
    Framework::assertSame(true, $ok['ok']);
    // 同一模型名挂到同一密钥下重复 → 唯一约束冲突
    $dup = $save(['alias' => 'gpt-4o', 'provider' => 'openai', 'key_id' => $keyId, 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1]);
    Framework::assertSame(false, $dup['ok'], '同密钥下重复模型名被拒');
});
