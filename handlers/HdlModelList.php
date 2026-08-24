<?php
/**
 * 模型列表处理器（OpenAI 风格）
 */
class HdlModelList implements HandlerInterface
{
    public function handle(AppRequest $req): void
    {
        $rows = db_fetchall(db(), 'SELECT * FROM model_map WHERE status=1 ORDER BY id ASC');

        $data = [];
        foreach ($rows as $m) {
            $data[] = [
                'id' => $m['alias'],
                'object' => 'model',
                'owned_by' => 'api-relay',
            ];
        }

        AppResponse::json([
            'object' => 'list',
            'data' => $data,
        ]);
    }
}
