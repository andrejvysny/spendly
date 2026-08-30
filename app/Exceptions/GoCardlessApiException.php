<?php

declare(strict_types=1);

namespace App\Exceptions;

final class GoCardlessApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $context,
        public readonly int $status,
        public readonly string $correlationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Failed to {$context} (HTTP {$status}, ref {$correlationId})", $status, $previous);
    }
}
