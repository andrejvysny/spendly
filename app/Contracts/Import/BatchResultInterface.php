<?php

namespace App\Contracts\Import;

/**
 * Interface for batch processing results.
 */
interface BatchResultInterface
{
    /**
 * Returns the total number of rows processed in the batch.
 *
 * @return int The total count of processed rows.
 */
    public function getTotalProcessed(): int;

    /**
 * Returns the number of rows that were successfully processed in the batch.
 *
 * @return int The count of successful rows.
 */
    public function getSuccessCount(): int;

    /**
 * Returns the number of rows that failed during batch processing.
 *
 * @return int The count of failed rows.
 */
    public function getFailedCount(): int;

    /**
 * Returns the number of rows that were skipped during batch processing.
 *
 * @return int The count of skipped rows.
 */
    public function getSkippedCount(): int;

    /**
 * Returns detailed results for all processed rows in the batch.
 *
 * @return ProcessResultInterface[] An array of result objects for each processed row.
 */
    public function getResults(): array;

    /**
 * Returns an array of results corresponding to rows that failed during batch processing.
 *
 * @return ProcessResultInterface[] List of failed row results.
 */
    public function getFailedResults(): array;

    /**
 * Retrieves the results for all skipped rows in the batch.
 *
 * @return ProcessResultInterface[] An array of results corresponding to skipped rows.
 */
public function getSkippedResults(): array;

    /**
 * Retrieves an array of results for rows that were processed successfully.
 *
 * @return ProcessResultInterface[] List of successful row processing results.
 */
public function getSuccessResults(): array;

    /**
 * Determines whether the entire batch was processed successfully without any failures.
 *
 * @return bool True if all rows were processed successfully; false otherwise.
 */
    public function isCompleteSuccess(): bool;
}
