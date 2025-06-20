<?php

namespace App\Contracts\Import;

/**
 * Interface for batch processing results.
 */
interface BatchResultInterface
{
    /**
     * Get the total number of rows processed.
     *
     * @return int
     */
    public function getTotalProcessed(): int;

    /**
     * Get the number of successful rows.
     *
     * @return int
     */
    public function getSuccessCount(): int;

    /**
     * Get the number of failed rows.
     *
     * @return int
     */
    public function getFailedCount(): int;

    /**
     * Get the number of skipped rows.
     *
     * @return int
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
     *
     * @return bool
     */
    public function isCompleteSuccess(): bool;
}
