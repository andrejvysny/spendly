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

    public function getTotalProcessed(): int
    {
        return count($this->results);
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getFailedResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return ! $result->isSuccess() && ! $result->isSkipped();
        });
    }

    public function getSuccessResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return $result->isSuccess();
        });
    }

    public function getSkippedResults(): array
    {
        return array_filter($this->results, function (ProcessResultInterface $result) {
            return $result->isSkipped();
        });
    }

    public function isCompleteSuccess(): bool
    {
        return $this->failedCount === 0 && $this->getTotalProcessed() > 0;
    }

    public function current(): mixed
    {
        if ($this->currentIndex < count($this->results)) {
            return $this->results[$this->currentIndex];
        }

        return null;
    }

    public function next(): void
    {
        $this->currentIndex++;
    }

    public function key(): int
    {
        return $this->currentIndex;
    }

    public function valid(): bool
    {
        return $this->currentIndex < count($this->results) && isset($this->items[$this->currentIndex]);
    }

    public function rewind(): void
    {
        $this->currentIndex = 0;
    }
}
