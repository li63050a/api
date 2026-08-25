<?php
declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 400,
        private string $type = 'invalid_request_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int { return $this->status; }
    public function type(): string { return $this->type; }
}
