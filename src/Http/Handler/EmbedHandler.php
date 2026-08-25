<?php
declare(strict_types=1);

namespace App\Http\Handler;

final class EmbedHandler extends AbstractRelayHandler
{
    protected function endpoint(): string
    {
        return '/v1/embeddings';
    }

    protected function endpointType(): string
    {
        return 'embeddings';
    }
}
