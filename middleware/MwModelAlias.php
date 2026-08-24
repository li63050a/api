<?php
/**
 * 模型别名解析中间件：从请求体 model 字段查 model_map。
 */
class MwModelAlias
{
    public function handle(AppRequest $req)
    {
        $model = $req->input('model');
        if (!is_string($model) || $model === '') {
            return AppResponse::error('model not found: (empty)', 404);
        }

        $row = db_fetch(
            db(),
            "SELECT * FROM model_map WHERE alias = ? AND status = 1 LIMIT 1",
            [$model]
        );
        if ($row === null) {
            return AppResponse::error('model not found: ' . $model, 404);
        }

        $req->setAttribute('model_map', $row);
        $req->setAttribute('provider_id', $row['provider_id']);
        return null;
    }
}
