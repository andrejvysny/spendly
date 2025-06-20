<?php

namespace App\Contracts\Import;

/**
 * Generic interface for processing individual rows of data.
 * This interface allows different modules to process data without direct dependencies.
 */
interface RowProcessorInterface
{
    /**
 * Processes a single row of data using the provided configuration.
 *
 * @param array $row The data row to be processed.
 * @param array $configuration Optional configuration parameters that influence processing.
 * @return ProcessResultInterface The result of processing the row.
 */
    public function processRow(array $row, array $configuration = []): ProcessResultInterface;

    /**
 * Determines whether the processor can handle the specified configuration.
 *
 * @param array $configuration The configuration to validate.
 * @return bool True if the processor supports the configuration; otherwise, false.
 */
    public function canProcess(array $configuration): bool;
}
