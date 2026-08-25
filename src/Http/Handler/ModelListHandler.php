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
        $rows = $this->maps->allEnabled();
        $data = array_map(static fn (array $m) => [
            'id' => $m['alias'],
            'object' => 'model',
            'created' => (int)($m['created_at'] ?? time()),
            'owned_by' => $m['provider'],
        ], $rows);
        return Response::json(['object' => 'list', 'data' => $data]);
    }
}
