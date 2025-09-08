<?php

namespace App\Contracts\Import;

/**
 * Interface for batch processing operations.
 * Allows processing of multiple items in batches for better performance.
 */
interface BatchProcessorInterface
{
    /**
     * Process multiple rows in a batch.
     *
     * @param  array  $rows  Array of rows to process
     * @param  array  $configuration  Processing configuration
     */
    public function processBatch(array $rows, array $configuration = []): BatchResultInterface;

    /**
     * Get the optimal batch size for this processor.
     */
    public function getBatchSize(): int;

    /**
     * Set the batch size.
     */
    public function setBatchSize(int $size): self;
}
