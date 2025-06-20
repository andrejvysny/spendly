<?php

namespace App\Services\Import;

/**
 * @deprecated Status tracking is now handled internally by ProcessResultInterface implementations
 */
enum TransactionParserRowStatus implements CsvProcessorRowStatus
{
    case SUCCESS;
    case FAILURE;
    case SKIPPED;
    case INVALID_FORMAT;
    case DUPLICATE;

    case ERROR;

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isFailure(): bool
    {
        return $this === self::FAILURE;
    }

    public function isError(): bool
    {
        return $this === self::ERROR;
    }

    public function isSkipped(): bool
    {
        return $this === self::SKIPPED;
    }

    public function isInvalidFormat(): bool
    {
        return $this === self::INVALID_FORMAT;
    }

    public function isDuplicate(): bool
    {
        return $this === self::DUPLICATE;
    }
}
