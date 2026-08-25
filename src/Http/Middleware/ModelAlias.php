<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Db\Repository\ModelMapRepository;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class ModelAlias implements MiddlewareInterface
{
    public function __construct(private ModelMapRepository $maps) {}

    public function process(Request $request): void
    {
        $payload = $request->json();
        $model = (string)($payload['model'] ?? '');
        if ($model === '') {
            throw new HttpException('Missing model', 400, 'invalid_request_error');
        }
        $map = $this->maps->findEnabledByAlias($model);
        if ($map !== null) {
            $request->setAttribute('model_map', $map);
            return;
        }
        // 兜底：直连同名模型（openai 格式）
        $request->setAttribute('model_map', [
            'alias' => $model,
            'provider' => 'openai',
            'upstream_model' => $model,
            'client_format' => $request->attribute('client_format', 'openai'),
            'enabled' => 1,
        ]);
    }
}
