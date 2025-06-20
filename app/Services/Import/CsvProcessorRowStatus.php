<?php

namespace App\Services\Import;

/**
 * @deprecated Status tracking is now handled internally by ProcessResultInterface implementations
 */
interface CsvProcessorRowStatus
{
    /**
 * Determines whether the CSV row was processed successfully.
 *
 * @return bool True if the row processing was successful; otherwise, false.
 */
public function isSuccess(): bool;

    /**
 * Determines whether the row processing failed.
 *
 * @return bool True if the row processing resulted in failure; otherwise, false.
 */
public function isFailure(): bool;

    /**
 * Determines whether the row processing resulted in an error.
 *
 * @return bool True if an error occurred during processing; otherwise, false.
 */
public function isError(): bool;

    /**
 * Determines whether the CSV row was skipped during processing.
 *
 * @return bool True if the row was skipped; otherwise, false.
 */
public function isSkipped(): bool;

    /**
 * Determines whether the row has an invalid format.
 *
 * @return bool True if the row format is invalid, false otherwise.
 */
public function isInvalidFormat(): bool;
}
