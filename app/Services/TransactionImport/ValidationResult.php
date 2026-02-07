<?php

namespace App\Services\TransactionImport;

/**
 * Validation result value object.
 */
readonly class ValidationResult
{
    public function __construct(
        private bool $valid,
        private array $errors = []
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
