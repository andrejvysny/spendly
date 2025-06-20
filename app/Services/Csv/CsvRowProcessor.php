<?php

namespace App\Services\Csv;

interface CsvRowProcessor
{
    /**
 * Processes a single CSV row and returns the result.
 *
 * Implementations should handle the provided row data, optionally utilizing metadata for context or additional processing requirements.
 *
 * @param array $row The CSV row to process.
 * @param array $metadata Optional metadata associated with the row.
 * @return CsvProcessResult The result of processing the CSV row.
 */
public function __invoke(array $row, array $metadata = []): CsvProcessResult;
}
