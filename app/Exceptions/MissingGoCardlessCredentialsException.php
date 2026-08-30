<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * No usable GoCardless secret pair could be resolved for a user — neither a personal override
 * nor instance-level credentials — or the personal override is only half filled in.
 *
 * Extends InvalidArgumentException rather than RuntimeException so it keeps travelling the
 * existing path: GoCardlessService::initializeClient() re-throws InvalidArgumentException
 * untouched and wraps everything else in a generic RuntimeException, which would swallow the
 * actionable message below.
 *
 * Messages carried here are user-facing by design and never contain secret material.
 */
class MissingGoCardlessCredentialsException extends \InvalidArgumentException
{
    public const DEFAULT_MESSAGE = 'GoCardless credentials not configured. Please add your Secret ID and Secret Key in Settings > Bank Data.';

    public const PARTIAL_MESSAGE = 'Incomplete personal GoCardless credentials: both Secret ID and Secret Key are required. Complete or remove them in Settings > Bank Data.';

    public function __construct(string $message = self::DEFAULT_MESSAGE, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function partial(): self
    {
        return new self(self::PARTIAL_MESSAGE);
    }
}
