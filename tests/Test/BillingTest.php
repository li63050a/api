<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Db\Database;
use App\Db\Repository\BillingRepository;
use App\Db\Repository\UserRepository;
use App\Db\Schema;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\QuotaService;
use App\Support\Config;
use App\Support\Exception\HttpException;
use Tests\Framework;

Framework::test('BillingService: records billing per key', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $svc = new BillingService(new BillingRepository($db));
    $svc->record(['id' => 7, 'user_id' => 0], 'openai', 'gpt-4o', 10, 20);
    Framework::assertSame(1, (int)$db->value('SELECT COUNT(*) FROM billing'));
    Framework::assertSame(30, (int)$db->value('SELECT total_tokens FROM billing LIMIT 1'));
});

Framework::test('QuotaService: daily over-quota throws 429', function (): void {
    $db = new Database('sqlite::memory:');
    (new Schema($db, new Config([])))->install();
    $billing = new BillingRepository($db);
    $billing->insert(['user_id' => 0, 'api_key_id' => 5, 'provider' => 'o', 'model' => 'm', 'prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100, 'cost' => 0, 'status' => 1, 'created_at' => time()]);
    $q = new QuotaService($billing);
    Framework::assertThrows(fn () => $q->assertWithinQuota(['id' => 5, 'quota_daily' => 100], 'daily'), HttpException::class);
    $q->assertWithinQuota(['id' => 5, 'quota_daily' => 200], 'daily'); // 不抛
    Framework::assertTrue(true);
});
