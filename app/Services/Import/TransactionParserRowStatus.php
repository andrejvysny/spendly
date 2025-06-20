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

    /**
     * Determines if the current status is SUCCESS.
     *
     * @return bool True if the status is SUCCESS, false otherwise.
     */
    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    /**
     * Determines if the current status is FAILURE.
     *
     * @return bool True if the status is FAILURE, false otherwise.
     */
    public function isFailure(): bool
    {
        return $this === self::FAILURE;
    }

    /**
     * Determines if the current status is ERROR.
     *
     * @return bool True if the status is ERROR, false otherwise.
     */
    public function isError(): bool
    {
        return $this === self::ERROR;
    }

    /**
     * Determines if the current status is SKIPPED.
     *
     * @return bool True if the status is SKIPPED, false otherwise.
     */
    public function isSkipped(): bool
    {
        return $this === self::SKIPPED;
    }

    /**
     * Determines if the current status is INVALID_FORMAT.
     *
     * @return bool True if the status is INVALID_FORMAT, false otherwise.
     */
    public function isInvalidFormat(): bool
    {
        return $this === self::INVALID_FORMAT;
    }

    /**
     * Determines if the current status is DUPLICATE.
     *
     * @return bool True if the status is DUPLICATE, false otherwise.
     */
    public function isDuplicate(): bool
    {
        return $this === self::DUPLICATE;
    }
}
