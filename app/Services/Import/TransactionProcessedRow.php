<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Services\Csv\CsvProcessResult instead
 */
readonly class TransactionProcessedRow implements CsvProcessorRowResult
{
    /**
     * Initializes a new instance representing the result of processing a transaction row.
     *
     * @param TransactionParserRowStatus $status The status of the processed row.
     * @param string $message A message describing the processing result.
     * @param array|object $data Additional data associated with the processed row.
     */
    public function __construct(
        private TransactionParserRowStatus $status,
        private string $message,
        private array|object $data
    ) {}

    /**
     * Retrieves the status of the processed transaction row.
     *
     * @return TransactionParserRowStatus The status indicating the result of processing the row.
     */
    public function getStatus(): TransactionParserRowStatus
    {
        return $this->status;
    }

    /**
     * Returns the message associated with the processed transaction row.
     *
     * @return string The message describing the result of processing the row.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Returns the data associated with the processed transaction row.
     *
     * @return array|object The data payload for the row, as an array or object.
     */
    public function getData(): array|object
    {
        return $this->data;
    }
}
