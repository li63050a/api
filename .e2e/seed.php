<?php
// 本地种子：provider + 上游密钥(加密) + model_map + user + api key
require '/workspace/src/bootstrap.php';

use App\Bootstrap;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Db\Repository\UserRepository;
use App\Domain\Crypto\CryptoService;

$c = Bootstrap::container();
$crypto = $c->get(CryptoService::class);
$userRepo = $c->get(UserRepository::class);
$keyRepo = $c->get(ApiKeyRepository::class);
$provRepo = $c->get(ProviderRepository::class);
$ukRepo = $c->get(UpstreamKeyRepository::class);
$mmRepo = $c->get(ModelMapRepository::class);

$token = 'sk-test-local-0001';
$uid = $userRepo->create(['username' => 'tester']);
$keyRepo->create([
    'user_id' => $uid,
    'key_prefix' => substr($token, 0, 8),
    'key_hash' => password_hash($token, PASSWORD_BCRYPT),
    'key_sha256' => hash('sha256', $token),
    'name' => 'test key',
    'status' => 1,
]);
$pid = $provRepo->create([
    'name' => 'MockUp',
    'base_url' => 'http://127.0.0.1:8898/upstream',
    'client_format' => 'openai',
    'status' => 1,
]);
$ukRepo->insert([
    'provider_id' => $pid,
    'key_value' => 'enc:' . $crypto->encrypt('sk-upstream-mock'),
    'status' => 1,
]);
$mmRepo->create([
    'alias' => 'mock-chat',
    'provider' => 'MockUp',
    'upstream_model' => 'mock-chat',
    'client_format' => 'openai',
    'enabled' => 1,
]);
// 无密钥 provider：用于验证信息化 503
$pid2 = $provRepo->create([
    'name' => 'EmptyProv',
    'base_url' => 'http://127.0.0.1:8898/upstream',
    'client_format' => 'openai',
    'status' => 1,
]);
$mmRepo->create([
    'alias' => 'nokey-model',
    'provider' => 'EmptyProv',
    'upstream_model' => 'nokey-model',
    'client_format' => 'openai',
    'enabled' => 1,
]);
echo "seeded: user={$uid} provider1={$pid} provider2={$pid2}\n";
