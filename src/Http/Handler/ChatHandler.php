<?php
declare(strict_types=1);

namespace App\Http\Handler;

final class ChatHandler extends AbstractRelayHandler
{
    protected function endpoint(): string
    {
        return '/v1/chat/completions';
    }
}
