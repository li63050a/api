<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\ModelMapRepository;
use App\Db\Schema;
use App\Support\Config;
use Tests\Framework;

Framework::test('ModelMap: 同一模型名可挂多把密钥，按密钥独立读写/启停', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $maps = new ModelMapRepository($db);
    $id1 = $maps->create(['alias' => 'gpt-4o', 'provider' => 'openai', 'key_id' => 1, 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1, 'created_at' => time()]);
    $id2 = $maps->create(['alias' => 'gpt-4o', 'provider' => 'openai', 'key_id' => 2, 'upstream_model' => 'gpt-4o', 'client_format' => 'openai', 'enabled' => 1, 'created_at' => time()]);
    Framework::assertTrue($id1 !== $id2, '同一别名下两把密钥生成两行');

    // 按 别名+密钥 定位到各自行
    Framework::assertSame(1, (int)$maps->findByAliasAndKeyId('gpt-4o', 1)['key_id']);
    Framework::assertSame(2, (int)$maps->findByAliasAndKeyId('gpt-4o', 2)['key_id']);
    Framework::assertTrue($maps->findByAliasAndKeyId('gpt-4o', 999) === null, '不存在的密钥组合返回 null');

    // 按密钥过滤
    Framework::assertSame(1, count($maps->allByKeyId(1)));
    Framework::assertSame(1, count($maps->allByKeyId(2)));
    Framework::assertSame(2, count($maps->allEnabled()));
    Framework::assertSame(2, count($maps->findAllEnabledByAlias('gpt-4o')));

    // 只停用其中一把密钥下的行，不影响另一把
    $maps->update($id1, ['enabled' => 0]);
    Framework::assertSame(0, (int)$maps->findByAliasAndKeyId('gpt-4o', 1)['enabled']);
    Framework::assertSame(1, (int)$maps->findByAliasAndKeyId('gpt-4o', 2)['enabled']);
    Framework::assertSame(1, count($maps->findAllEnabledByAlias('gpt-4o')));
});
