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
     */
    public function isSuccess(): bool;

    /**
     * Check if the row was skipped (neither success nor failure).
     */
    public function isSkipped(): bool;

    /**
     * Get the processing message.
     */
    public function getMessage(): string;

    /**
     * Get the processed data.
     */
    public function getData(): array|object|null;

    /**
     * Get any errors that occurred during processing.
     */
    public function getErrors(): array;
}
