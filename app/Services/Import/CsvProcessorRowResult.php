<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Contracts\Import\ProcessResultInterface instead
 */
interface CsvProcessorRowResult
{
    /**
 * Retrieves the data associated with the processed CSV row.
 *
 * @return array|object The data extracted or transformed from the CSV row.
 */
public function getData(): array|object;

    /**
 * Retrieves the status of the processed CSV row.
 *
 * @return CsvProcessorRowStatus The status indicating the outcome of the row processing.
 */
public function getStatus(): CsvProcessorRowStatus;

    /**
 * Returns a message describing the result of processing the CSV row.
 *
 * @return string The message related to the processing result.
 */
public function getMessage(): string;
}
