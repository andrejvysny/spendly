<?php

namespace App\Contracts\Import;

/**
 * Generic interface for processing results.
 * Provides a contract for returning results from any type of processing operation.
 */
interface ProcessResultInterface
{
    /**
 * Determines whether the processing operation was successful.
 *
 * @return bool True if the processing succeeded; otherwise, false.
 */
    public function isSuccess(): bool;

    /**
 * Determines whether the processing operation was skipped.
 *
 * @return bool True if the operation was skipped; otherwise, false.
 */
    public function isSkipped(): bool;

    /**
 * Retrieves a message describing the result of the processing operation.
 *
 * @return string The message related to the processing outcome.
 */
    public function getMessage(): string;

    /**
 * Returns the data produced by the processing operation.
 *
 * @return array|object|null The processed data, or null if no data was produced.
 */
    public function getData(): array|object|null;

    /**
 * Returns an array of errors encountered during processing.
 *
 * @return array The list of errors, or an empty array if none occurred.
 */
    public function getErrors(): array;
}
