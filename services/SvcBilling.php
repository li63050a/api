<?php
/**
 * 计费服务：按 model_map 单价记录用量并扣减余额。
 */
class SvcBilling
{
    public function record(
        int $userId,
        int $keyId,
        string $modelAlias,
        int $inputTokens,
        int $outputTokens,
        int $requestCount = 1
    ): void {
        $db = db();
        $map = db_fetch($db, "SELECT * FROM model_map WHERE alias = ? LIMIT 1", [$modelAlias]);
        $priceInput = $map['price_input'] ?? 0;
        $priceOutput = $map['price_output'] ?? 0;
        $pricePerRequest = $map['price_per_request'] ?? 0;
        $providerId = $map['provider_id'] ?? null;

        $amount = $inputTokens * (float) $priceInput / 1000
                + $outputTokens * (float) $priceOutput / 1000
                + (float) $pricePerRequest * $requestCount;

        db_insert($db, 'billing', [
            'user_id' => $userId,
            'api_key_id' => $keyId,
            'model_alias' => $modelAlias,
            'provider_id' => $providerId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'request_count' => $requestCount,
            'amount' => $amount,
            'created_at' => time(),
        ]);

        if ($amount > 0) {
            $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
               ->execute([$amount, $userId]);
        }
    }
}
