<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Contracts\Import\RowProcessorInterface instead
 */
interface CsvProcessorRowHandler
{

    public function __invoke(array $row): CsvProcessorRowResult;

}
