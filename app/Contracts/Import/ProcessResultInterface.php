<?php

namespace App\Contracts\Import;

/**
 * Generic interface for processing results.
 * Provides a contract for returning results from any type of processing operation.
 */
interface ProcessResultInterface
{
    /**
     * Check if the processing was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool;

    /**
     * Check if the row was skipped (neither success nor failure).
     *
     * @return bool
     */
    public function isSkipped(): bool;

    /**
     * Get the processing message.
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Get the processed data.
     *
     * @return array|object|null
     */
    public function getData(): array|object|null;

    /**
     * Get any errors that occurred during processing.
     *
     * @return array
     */
    public function getErrors(): array;

}
