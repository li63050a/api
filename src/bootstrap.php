<?php
declare(strict_types=1);

namespace App;

use App\Db\Database;
use App\Db\Repository\AdminAuditRepository;
use App\Db\Repository\AdminUserRepository;
use App\Db\Repository\ApiKeyRepository;
use App\Db\Repository\BillingRepository;
use App\Db\Repository\ModelMapRepository;
use App\Db\Repository\ProviderRepository;
use App\Db\Repository\RequestLogRepository;
use App\Db\Repository\SpeedTestRepository;
use App\Db\Repository\UpstreamKeyRepository;
use App\Db\Repository\UserRepository;
use App\Db\Schema;
use App\Domain\Auth\AdminAuth;
use App\Domain\Auth\ApiKeyAuth;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\QuotaService;
use App\Domain\Cache\FileCache;
use App\Domain\Crypto\CryptoService;
use App\Domain\Logger\RequestLogger;
use App\Domain\Provider\KeyPool;
use App\Domain\Provider\ProviderFactory;
use App\Domain\RateLimit\FileRateLimiter;
use App\Domain\SpeedTest\SpeedTestService;
use App\Domain\Sync\ModelSync;
use App\Http\Handler\ChatHandler;
use App\Http\Handler\EmbedHandler;
use App\Http\Handler\ModelListHandler;
use App\Http\Kernel;
use App\Http\Middleware\Auth;
use App\Http\Middleware\ClientFormat;
use App\Http\Middleware\ModelAlias;
use App\Http\Middleware\RateLimit;
use App\Http\Router;
use App\Support\Config;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class Bootstrap
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $configData = require dirname(__DIR__) . '/config.php';
        $config = new Config($configData);
        $container = new Container();
        $container->set(Config::class, $config);

        // 数据层：Database + Schema.install
        $db = new Database('sqlite:' . (string)$config->get('db_path'));
        (new Schema($db, $config))->install();
        $container->set(Database::class, $db);

        // Repositories
        $container->set(UserRepository::class, new UserRepository($db));
        $container->set(ApiKeyRepository::class, new ApiKeyRepository($db));
        $container->set(ProviderRepository::class, new ProviderRepository($db));
        $container->set(ModelMapRepository::class, new ModelMapRepository($db));
        $container->set(UpstreamKeyRepository::class, new UpstreamKeyRepository($db));
        $container->set(BillingRepository::class, new BillingRepository($db));
        $container->set(RequestLogRepository::class, new RequestLogRepository($db));
        $container->set(AdminUserRepository::class, new AdminUserRepository($db));
        $container->set(AdminAuditRepository::class, new AdminAuditRepository($db));
        $container->set(SpeedTestRepository::class, new SpeedTestRepository($db));

        // 基础设施
        $cryptoKey = (string)$config->get('crypto_key', '0123456789abcdef0123456789abcdef');
        $container->set(CryptoService::class, new CryptoService($cryptoKey));
        $cacheDir = (string)$config->get('cache_dir');
        $container->set(FileCache::class, new FileCache($cacheDir));
        $container->set(FileRateLimiter::class, new FileRateLimiter($cacheDir));
        $container->set(RequestLogger::class, new RequestLogger($container->get(RequestLogRepository::class)));

        // 认证
        $container->set(ApiKeyAuth::class, new ApiKeyAuth(
            $container->get(ApiKeyRepository::class),
            $container->get(UserRepository::class),
        ));
        $session = [];
        $container->set(AdminAuth::class, new AdminAuth($container->get(AdminUserRepository::class), $session));

        // 计费 / 配额 / 熔断 / 工厂 / 同步 / 测速
        $container->set(BillingService::class, new BillingService(
            $container->get(BillingRepository::class),
            $container->get(UserRepository::class),
        ));
        $container->set(QuotaService::class, new QuotaService($container->get(BillingRepository::class)));
        $container->set(KeyPool::class, new KeyPool(
            $container->get(UpstreamKeyRepository::class),
            $container->get(FileCache::class),
            $config,
        ));
        $container->set(ProviderFactory::class, new ProviderFactory(
            $config,
            $container->get(CryptoService::class),
            $container->get(KeyPool::class),
        ));
        $container->set(ModelSync::class, new ModelSync(
            $db,
            $container->get(ProviderRepository::class),
            $container->get(UpstreamKeyRepository::class),
            $container->get(ModelMapRepository::class),
            $container->get(CryptoService::class),
            $config,
        ));
        $container->set(SpeedTestService::class, new SpeedTestService(
            $db,
            $container->get(ProviderRepository::class),
            $container->get(UpstreamKeyRepository::class),
            $container->get(ModelMapRepository::class),
            $container->get(SpeedTestRepository::class),
            $container->get(CryptoService::class),
            $config,
        ));

        self::$container = $container;
        return $container;
    }

    public static function kernel(): Kernel
    {
        $c = self::container();

        // 中间件
        $c->set('middleware:client_format', new ClientFormat());
        $c->set('middleware:auth', new Auth($c->get(ApiKeyAuth::class)));
        $c->set('middleware:rate_limit', new RateLimit($c->get(FileRateLimiter::class), $c->get(Config::class)));
        $c->set('middleware:model_alias', new ModelAlias($c->get(ModelMapRepository::class)));

        // Handler（均可直接 __invoke）
        $relayArgs = [
            $c->get(ProviderFactory::class),
            $c->get(BillingService::class),
            $c->get(QuotaService::class),
            $c->get(RequestLogger::class),
        ];
        $c->set('handler:chat', new ChatHandler(...$relayArgs));
        $c->set('handler:embed', new EmbedHandler(...$relayArgs));
        $c->set('handler:models', new ModelListHandler($c->get(ModelMapRepository::class)));

        // 路由
        $router = new Router();
        $router->add('/v1/chat/completions', 'chat', ['client_format', 'auth', 'rate_limit', 'model_alias']);
        $router->add('/v1/embeddings', 'embed', ['client_format', 'auth', 'rate_limit', 'model_alias']);
        $router->add('/v1/models', 'models', ['auth']);

        return new Kernel($c, $router, $c->get(RequestLogger::class));
    }

    /** 测试用：清空并重建容器 */
    public static function reset(): void
    {
        self::$container = null;
    }
}