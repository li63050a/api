<?php
declare(strict_types=1);

namespace App\Http\Handler;

use App\Db\Repository\ModelMapRepository;
use App\Http\Request;
use App\Http\Response;

final class ModelListHandler
{
    public function __construct(private ModelMapRepository $maps) {}

    public function __invoke(Request $request): Response
    {
        // 同一模型名可挂在多把密钥下，列表按 alias 去重
        $seen = [];
        $data = [];
        foreach ($this->maps->allEnabled() as $m) {
            $alias = (string)$m['alias'];
            if (isset($seen[$alias])) {
                continue;
            }
            $seen[$alias] = true;
            $data[] = [
                'id' => $alias,
                'object' => 'model',
                'created' => (int)($m['created_at'] ?? time()),
                'owned_by' => $m['provider'],
            ];
        }
        return Response::json(['object' => 'list', 'data' => $data]);
    }
}
