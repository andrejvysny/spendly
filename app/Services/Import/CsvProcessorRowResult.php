<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Contracts\Import\ProcessResultInterface instead
 */
interface CsvProcessorRowResult
{
    public function getData(): array|object;
    public function getStatus(): CsvProcessorRowStatus;
    public function getMessage(): string;
}
