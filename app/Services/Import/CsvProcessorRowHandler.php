<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Contracts\Import\RowProcessorInterface instead
 */
interface CsvProcessorRowHandler
{
    /**
 * Processes a single CSV row and returns the result.
 *
 * @param array $row The data for a single row from the CSV file.
 * @return CsvProcessorRowResult The result of processing the row.
 */
public function __invoke(array $row): CsvProcessorRowResult;
}
