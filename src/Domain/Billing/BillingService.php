<?php
declare(strict_types=1);

namespace App\Domain\Billing;

use App\Db\Repository\BillingRepository;

final class BillingService
{
    public function __construct(private BillingRepository $billing) {}

    public function record(array $key, string $provider, string $model, int $prompt, int $completion, float $promptPrice = 0.0, float $completionPrice = 0.0): void
    {
        $total = $prompt + $completion;
        $cost = ($prompt / 1000000 * $promptPrice) + ($completion / 1000000 * $completionPrice);
        $this->billing->insert([
            'user_id' => (int)($key['user_id'] ?? 0),
            'api_key_id' => (int)$key['id'],
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'cost' => round($cost, 8),
            'status' => 1,
            'created_at' => time(),
        ]);
        // 累计用户用量（billing 表已有，users 表仅保留余额/配额，不在此累加 tokens）
    }
}
