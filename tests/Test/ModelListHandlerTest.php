<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\ModelMapRepository;
use App\Db\Schema;
use App\Http\Handler\ModelListHandler;
use App\Http\Request;
use App\Support\Config;
use Tests\Framework;

Framework::test('ModelListHandler: returns enabled aliases as openai models list', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $maps = new ModelMapRepository($db);
    $maps->create(['alias' => 'gpt-4o', 'provider' => 'openai', 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1, 'created_at' => time()]);
    $maps->create(['alias' => 'sonnet', 'provider' => 'anthropic', 'upstream_model' => 'claude-3-5-sonnet', 'client_format' => 'anthropic', 'enabled' => 0, 'created_at' => time()]);
    $h = new ModelListHandler($maps);
    $req = new Request('GET', '/v1/models', [], [], null, '');
    $resp = $h($req);
    $data = json_decode($resp->body(), true);
    Framework::assertSame('list', $data['object']);
    Framework::assertSame(1, count($data['data']));
    Framework::assertSame('gpt-4o', $data['data'][0]['id']);
});
