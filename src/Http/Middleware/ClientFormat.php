<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Support\Exception\HttpException;

final class ClientFormat implements MiddlewareInterface
{
    public function process(Request $request): void
    {
        $format = strtolower((string)($request->header('X-Client-Format') ?? 'openai'));
        if (!in_array($format, ['openai', 'anthropic', 'gemini'], true)) {
            throw new HttpException("Unsupported X-Client-Format: {$format}", 400, 'invalid_request_error');
        }
        $request->setAttribute('client_format', $format);
    }
}
