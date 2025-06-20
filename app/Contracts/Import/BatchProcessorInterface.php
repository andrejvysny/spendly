<?php

namespace App\Contracts\Import;

/**
 * Interface for batch processing operations.
 * Allows processing of multiple items in batches for better performance.
 */
interface BatchProcessorInterface
{
    /**
 * Processes an array of rows in a single batch operation.
 *
 * @param array $rows The rows to be processed in the batch.
 * @param array $configuration Optional configuration settings for batch processing.
 * @return BatchResultInterface The result of the batch processing operation.
 */
    public function processBatch(array $rows, array $configuration = []): BatchResultInterface;

    /**
 * Returns the current optimal batch size for processing operations.
 *
 * @return int The number of items to process in each batch.
 */
    public function getBatchSize(): int;

    /**
 * Sets the batch size for processing operations.
 *
 * @param int $size The number of items to process in each batch.
 * @return self Returns the instance for method chaining.
 */
    public function setBatchSize(int $size): self;
}
