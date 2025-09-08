<?php

namespace App\Services\Csv;

interface CsvRowProcessor
{
    public function __invoke(array $row, array $metadata = []): CsvProcessResult;
}
