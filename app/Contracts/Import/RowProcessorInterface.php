<?php

namespace App\Contracts\Import;

/**
 * Generic interface for processing individual rows of data.
 * This interface allows different modules to process data without direct dependencies.
 */
interface RowProcessorInterface
{
    /**
     * Process a single row of data.
     *
     * @param  array  $row  The row data to process
     * @param  array  $configuration  Additional configuration for processing
     */
    public function processRow(array $row, array $configuration = []): ProcessResultInterface;

    /**
     * Validate if the processor can handle the given configuration.
     */
    public function canProcess(array $configuration): bool;
}
