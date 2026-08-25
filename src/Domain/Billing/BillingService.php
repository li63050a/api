<?php
declare(strict_types=1);

namespace App\Domain\Billing;

use App\Db\Repository\BillingRepository;
use App\Db\Repository\UserRepository;

final class BillingService
{
    public function __construct(
        private BillingRepository $billing,
        private UserRepository $users,
    ) {}

    public function record(array $user, array $key, string $provider, string $model, int $prompt, int $completion): void
    {
        $total = $prompt + $completion;
        $this->billing->insert([
            'user_id' => (int)$user['id'],
            'api_key_id' => (int)$key['id'],
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'cost' => 0.0,
            'status' => 1,
            'created_at' => time(),
        ]);
        // 累计用户用量（billing 表已有，users 表仅保留余额/配额，不在此累加 tokens）
    }
}
