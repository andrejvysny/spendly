<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Services\Csv\CsvProcessResult instead
 */
readonly class TransactionProcessedRow implements CsvProcessorRowResult
{

    public function __construct(
        private TransactionParserRowStatus $status,
        private string                     $message,
        private array|object                      $data
    )
    {
    }

    public function getStatus(): TransactionParserRowStatus
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData(): array|object
    {
        return $this->data;
    }
}
