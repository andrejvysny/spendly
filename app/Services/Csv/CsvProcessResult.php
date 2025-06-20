<?php

namespace App\Services\Csv;

use App\Contracts\Import\ProcessResultInterface;

/**
 * Generic implementation of ProcessResultInterface for CSV processing.
 */
readonly class CsvProcessResult implements ProcessResultInterface
{
    /**
     * Initializes a new CsvProcessResult instance representing the outcome of a CSV processing operation.
     *
     * @param bool $success Indicates whether the operation was successful.
     * @param string $message A non-empty message describing the result.
     * @param array|object|null $data Optional data associated with the result.
     * @param array $errors List of errors encountered during processing.
     * @param bool $skipped Indicates if the operation was skipped.
     * @param array|null $metadata Optional additional metadata.
     * @throws \InvalidArgumentException If the message is empty.
     */
    public function __construct(
        private bool $success,
        private string $message,
        private array|object|null $data = null,
        private array $errors = [],
        private bool $skipped = false,
        private ?array $metadata = null
    ) {
        if (empty($message)) {
            throw new \InvalidArgumentException('Message cannot be empty');
        }
    }

    /**
     * Creates a CsvProcessResult instance representing a successful CSV processing operation.
     *
     * @param string $message A descriptive message about the successful operation.
     * @param array|object $data The data associated with the successful result.
     * @param array $metadata Optional additional metadata for the result.
     * @return self The CsvProcessResult instance indicating success.
     */
    public static function success(string $message, array|object $data, array $metadata = []): self
    {
        return new self(true, $message, $data, metadata: $metadata);
    }

    /**
     * Creates a CsvProcessResult instance representing a failed CSV processing operation.
     *
     * @param string $message Description of the failure.
     * @param array $data Data associated with the failed operation.
     * @param array $metadata Optional metadata related to the operation.
     * @param array $errors List of errors encountered during processing.
     * @return self
     */
    public static function failure(string $message, array $data, array $metadata = [], array $errors = []): self
    {
        return new self(false, $message, $data, $errors);
    }

    /**
     * Creates a CsvProcessResult instance representing a skipped CSV processing operation.
     *
     * @param string $message Description of why the operation was skipped.
     * @param array $data Data associated with the skipped operation.
     * @param array $metadata Additional metadata related to the operation.
     * @return self The CsvProcessResult instance indicating a skipped state.
     */
    public static function skipped(string $message, array $data, array $metadata): self
    {
        return new self(false, $message, $data, [], true, $metadata);
    }

    /**
     * Determines if the CSV processing operation was successful.
     *
     * @return bool True if the operation succeeded; otherwise, false.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Determines whether the CSV processing operation was skipped.
     *
     * @return bool True if the operation was skipped; otherwise, false.
     */
    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    /**
     * Returns the message describing the result of the CSV processing operation.
     *
     * @return string The result message.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Retrieves the data associated with the CSV processing result.
     *
     * @return array|object|null The result data, or null if none was provided.
     */
    public function getData(): array|object|null
    {
        return $this->data;
    }

    /**
     * Retrieves the list of errors associated with the CSV processing result.
     *
     * @return array The errors encountered during processing.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Retrieves the metadata associated with the CSV processing result.
     *
     * @return array|null The metadata array, or null if no metadata is set.
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
