<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\UserRepository;
use App\Support\Exception\HttpException;

final class ApiKeyAuth
{
    public function __construct(
        private ApiKeyRepository $keys,
        private UserRepository $users,
    ) {}

    /** @return array{user: array, key: array} */
    public function authenticate(string $token): array
    {
        $sha = hash('sha256', $token);
        $key = $this->keys->findByTokenSha($sha);
        if ($key === null) {
            // 兼容旧库：无 key_sha256 的 key 按 key_prefix 缩小候选集再 bcrypt 校验
            $key = $this->legacyAuthenticate($token);
        }
        if ($key === null || !password_verify($token, $key['key_hash'])) {
            throw new HttpException('Invalid API key', 401, 'invalid_api_key');
        }
        if ((int)$key['status'] !== 1) {
            throw new HttpException('API key disabled', 403, 'invalid_api_key');
        }
        $expiresAt = $key['expires_at'] ?? null;
        if ($expiresAt !== null && (int)$expiresAt > 0 && (int)$expiresAt < time()) {
            throw new HttpException('API key expired', 403, 'invalid_api_key');
        }
        $user = $this->users->find((int)$key['user_id']);
        if ($user === null || (int)$user['status'] !== 1) {
            throw new HttpException('User disabled', 403, 'invalid_api_key');
        }
        $this->keys->touchUsed((int)$key['id']);
        return ['user' => $user, 'key' => $key];
    }

    private function legacyAuthenticate(string $token): ?array
    {
        $prefix = substr($token, 0, 8);
        foreach ($this->keys->findCandidatesByPrefix($prefix) as $candidate) {
            if (password_verify($token, $candidate['key_hash'])) {
                return $candidate;
            }
        }
        return null;
    }

    /** 返回可用于计费定位的 key（含解密后的原文供上游透传场景使用；当前实现 key 原文不落库，故直接返回 row） */
    public function decryptTokenKey(array $key): string
    {
        return (string)$key['key_prefix'];
    }
}
