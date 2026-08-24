<?php
/**
 * 极简路由器：按 path 前缀匹配 handler + middleware（不依赖 URL 重写）
 */
class AppRouter
{
    private array $routes;

    public function __construct(array $routes)
    {
        usort($routes, fn($a, $b) => strlen($b['prefix']) <=> strlen($a['prefix']));
        $this->routes = $routes;
    }

    public function dispatch(AppRequest $req): void
    {
        foreach ($this->routes as $r) {
            if (strpos($req->path, $r['prefix']) === 0) {
                foreach ($r['middleware'] ?? [] as $mwClass) {
                    /** @var MiddlewareInterface $mw */
                    $mw = new $mwClass();
                    $ret = $mw->handle($req);
                    if ($ret instanceof AppResponse) {
                        return;
                    }
                }
                /** @var HandlerInterface $h */
                $h = new $r['handler']();
                $h->handle($req);
                return;
            }
        }
        AppResponse::error('Not Found: ' . $req->path, 404, 'not_found');
    }
}
