<?php
declare(strict_types=1);

namespace App\Domain\Billing;

use App\Db\Repository\BillingRepository;
use App\Support\Exception\HttpException;

final class QuotaService
{
    public function __construct(private BillingRepository $billing) {}

    public function assertWithinQuota(array $user, string $period): void
    {
        $limit = (int)($period === 'daily' ? ($user['quota_daily'] ?? 0) : ($user['quota_monthly'] ?? 0));
        if ($limit <= 0) {
            return; // 0 表示不限
        }
        $now = time();
        $from = $period === 'daily'
            ? (new \DateTime('today'))->getTimestamp()
            : (new \DateTime('first day of this month'))->getTimestamp();
        $sum = $this->billing->sumTokens((int)$user['id'], $from, $now);
        if (($sum['total'] ?? 0) >= $limit) {
            throw new HttpException("Quota exceeded for {$period}", 429, 'quota_exceeded');
        }
    }
}
