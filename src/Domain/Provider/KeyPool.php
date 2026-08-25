<?php
declare(strict_types=1);

namespace App\Domain\Provider;

use App\Db\Repository\UpstreamKeyRepository;
use App\Domain\Cache\FileCache;
use App\Support\Config;

final class KeyPool
{
    public function __construct(
        private UpstreamKeyRepository $keys,
        private FileCache $cache,
        private Config $config,
    ) {}

    /**
     * 选择一把健康上游密钥。
     *
     * @param int[] $onlyIds 非空时仅在这些密钥中挑选（按模型名过滤出支持该模型的密钥）
     * @return array<string, mixed>|null
     */
    public function pick(int $providerId, ?int $preferredId = null, array $onlyIds = []): ?array
    {
        $candidates = $this->keys->byProvider($providerId);
        if ($onlyIds !== []) {
            $allowed = array_flip(array_map('intval', $onlyIds));
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $k): bool => isset($allowed[(int)$k['id']])
            ));
        }
        $healthy = array_values(array_filter($candidates, fn ($k) => !$this->isDisabled((int)$k['id'])));
        if ($healthy === []) {
            return null;
        }
        // 轮询：取 last_used_at 最旧者
        usort($healthy, fn ($a, $b) => ((int)($a['last_used_at'] ?? 0)) <=> ((int)($b['last_used_at'] ?? 0)));
        return $healthy[0];
    }

    public function markFailure(int $id): void
    {
        $this->keys->markFail($id);
        $key = "kp:fail:{$id}";
        $cur = (int)$this->cache->get($key);
        $cur++;
        $limit = (int)$this->config->get('keypool_max_consecutive_failures', 5);
        if ($cur >= $limit) {
            $this->cache->set($key, $cur, (int)$this->config->get('keypool_disabled_seconds', 300));
            $this->keys->disable($id);
        } else {
            $this->cache->set($key, $cur, 300);
        }
    }

    public function markSuccess(int $id): void
    {
        $this->keys->markSuccess($id);
        $this->cache->delete("kp:fail:{$id}");
        $this->keys->resetFailures($id);
    }

    public function isDisabled(int $id): bool
    {
        return $this->cache->get("kp:fail:{$id}") !== null;
    }
}
