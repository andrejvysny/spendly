<?php

namespace App\Services\Csv;

use App\Contracts\Import\BatchResultInterface;
use App\Contracts\Import\ProcessResultInterface;

/**
 * Implementation of BatchResultInterface for CSV batch processing.
 */
class CsvBatchResult implements \Iterator, BatchResultInterface
{
    private array $results = [];

    private int $successCount = 0;

    private int $failedCount = 0;

    private int $skippedCount = 0;

    private int $currentIndex = 0;

    /**
     * Adds a processing result to the batch and updates success, failure, or skipped counters based on the result's status.
     *
     * @param ProcessResultInterface $result The processing result to add.
     */
    public function addResult(ProcessResultInterface $result): void
    {
        $this->results[] = $result;

        if ($result->isSuccess()) {
            $this->successCount++;
        } elseif ($result->isSkipped()) {
            $this->skippedCount++;
        } else {
            $this->failedCount++;
        }
    }

    /**
     * Returns the total number of processed results in the batch.
     *
     * @return int The count of all results added to the batch.
     */
    public function getTotalProcessed(): int
    {
        return count($this->results);
    }

    /**
     * Returns the number of successful results in the batch.
     *
     * @return int The count of successful processing results.
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * Returns the number of failed results in the batch.
     *
     * @return int The count of failed processing results.
     */
    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    /**
     * Returns the number of skipped results in the batch.
     *
     * @return int The count of skipped processing results.
     */
    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    /**
     * Returns all processing results in the batch.
     *
     * @return ProcessResultInterface[] Array of all processed results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Retrieves all results that are neither successful nor skipped.
     *
     * @return ProcessResultInterface[] An array of failed processing results.
     */
    public function getFailedResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return ! $result->isSuccess() && ! $result->isSkipped();
        });
    }

    /**
     * Retrieves all processing results that were successful.
     *
     * @return ProcessResultInterface[] An array of results where processing was successful.
     */
    public function getSuccessResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return $result->isSuccess();
        });
    }

    /**
     * Retrieves all results that were skipped during processing.
     *
     * @return ProcessResultInterface[] An array of skipped processing results.
     */
    public function getSkippedResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return $result->isSkipped();
        });
    }

    /**
     * Determines if the batch processing completed successfully with no failures.
     *
     * @return bool True if there are no failed results and at least one result was processed; otherwise, false.
     */
    public function isCompleteSuccess(): bool
    {
        return $this->failedCount === 0 && $this->getTotalProcessed() > 0;
    }

    /**
     * Returns the current processing result in the iteration.
     *
     * @return ProcessResultInterface|null The current result, or null if the index is out of bounds.
     */
    public function current(): mixed
    {
        if ($this->currentIndex < count($this->results)) {
            return $this->results[$this->currentIndex];
        }

        return null;
    }

    /**
     * Advances the iterator to the next result in the batch.
     */
    public function next(): void
    {
        $this->currentIndex++;
    }

    /**
     * Returns the current index position in the batch results iterator.
     *
     * @return int The current iterator index.
     */
    public function key(): int
    {
        return $this->currentIndex;
    }

    /**
     * Determines if the current iterator position is valid.
     *
     * @return bool True if the current index points to a valid result; otherwise, false.
     */
    public function valid(): bool
    {
        return $this->currentIndex < count($this->results) && isset($this->results[$this->currentIndex]);
    }

    /**
     * Resets the iterator to the first result in the batch.
     */
    public function rewind(): void
    {
        $this->currentIndex = 0;
    }
}
