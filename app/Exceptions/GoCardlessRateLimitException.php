<?php

declare(strict_types=1);

namespace App\Exceptions;

class GoCardlessRateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'GoCardless rate limit exceeded',
        int $code = 429,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
