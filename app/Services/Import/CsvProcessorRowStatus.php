<?php

namespace App\Services\Import;

/**
 * @deprecated Status tracking is now handled internally by ProcessResultInterface implementations
 */
interface CsvProcessorRowStatus
{
    public function isSuccess(): bool;

    public function isFailure(): bool;

    public function isError(): bool;

    public function isSkipped(): bool;

    public function isInvalidFormat(): bool;
}
