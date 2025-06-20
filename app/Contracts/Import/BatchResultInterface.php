<?php

namespace App\Contracts\Import;

/**
 * Interface for batch processing results.
 */
interface BatchResultInterface
{
    /**
     * Get the total number of rows processed.
     */
    public function getTotalProcessed(): int;

    /**
     * Get the number of successful rows.
     */
    public function getSuccessCount(): int;

    /**
     * Get the number of failed rows.
     */
    public function getFailedCount(): int;

    /**
     * Get the number of skipped rows.
     */
    public function getSkippedCount(): int;

    /**
     * Get detailed results for each row.
     *
     * @return ProcessResultInterface[]
     */
    public function getResults(): array;

    /**
     * Get all failed results.
     *
     * @return ProcessResultInterface[]
     */
    public function getFailedResults(): array;

    public function getSkippedResults(): array;

    public function getSuccessResults(): array;

    /**
     * Check if the batch was completely successful.
     */
    public function isCompleteSuccess(): bool;
}
