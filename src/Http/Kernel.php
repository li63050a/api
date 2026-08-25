<?php
declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Domain\Logger\RequestLogger;
use App\Support\Exception\HttpException;
use App\Support\Exception\InternalException;

final class Kernel
{
    public function __construct(
        private Container $container,
        private Router $router,
        private RequestLogger $logger,
    ) {}

    public function handle(Request $request): Response
    {
        $start = microtime(true);
        try {
            $route = $this->router->match($request->path());
            if ($route === null) {
                throw new HttpException('Not Found', 404, 'not_found');
            }
            foreach ($route['middleware'] as $name) {
                /** @var MiddlewareInterface $mw */
                $mw = $this->container->get('middleware:' . $name);
                $mw->process($request);
            }
            /** @var callable $handler */
            $handler = $this->container->get('handler:' . $route['handler']);
            $result = $handler($request);
            if ($result instanceof Response) {
                return $result;
            }
            if ($result === null) {
                // 流式：handler 已直出
                return new Response(200, ['Content-Type' => 'text/event-stream'], '');
            }
            throw new InternalException('handler returned unsupported value');
        } catch (HttpException $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            $this->logger->record([
                'status' => 0,
                'error' => $e->getMessage(),
                'ip' => $request->clientIp(),
            ]);
            return Response::error(new HttpException('Internal Server Error', 500, 'internal_error'));
        }
    }
}
