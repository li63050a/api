<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Db\Repository\ApiKeyRepository;
use App\Support\Exception\HttpException;

final class ApiKeyAuth
{
    public function __construct(private ApiKeyRepository $keys) {}

    /** @return array{key: array} */
    public function authenticate(string $token, string $ip = ''): array
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
        $this->assertIpAllowed((string)($key['ip_whitelist'] ?? ''), $ip);
        $this->keys->touchUsed((int)$key['id']);
        return ['key' => $key];
    }

    private function assertIpAllowed(string $whitelist, string $ip): void
    {
        $entries = array_filter(array_map('trim', explode(',', $whitelist)), static fn (string $e) => $e !== '');
        if ($entries === [] || $ip === '') {
            return; // 空白名单或空 IP 视为放行
        }
        foreach ($entries as $entry) {
            if ($this->ipMatches($entry, $ip)) {
                return;
            }
        }
        throw new HttpException('IP not allowed', 403, 'ip_not_allowed');
    }

    /** 精确匹配或 IPv4 CIDR 匹配；IPv6 走精确匹配 */
    private static function ipMatches(string $entry, string $ip): bool
    {
        if (str_contains($entry, '/')) {
            [$net, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            $ipLong = ip2long($ip);
            $netLong = ip2long($net);
            if ($ipLong === false || $netLong === false || $mask === null || !ctype_digit((string)$mask) || (int)$mask < 0 || (int)$mask > 32) {
                return false;
            }
            $maskBits = (int)$mask;
            $maskLong = $maskBits === 0 ? 0 : (0xFFFFFFFF << (32 - $maskBits)) & 0xFFFFFFFF;
            return ((int)$ipLong & $maskLong) === ((int)$netLong & $maskLong);
        }
        return $entry === $ip;
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
