<?php

namespace App\Services\TransactionImport;

class TransactionDto
{

    public function __construct(
        private array                     $data,
        private readonly ValidationResult $validationResult,
    ) {}

    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): TransactionDto
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function getValidationResult(): ValidationResult
    {
        return $this->validationResult;
    }

    public function isValid(): bool
    {
        return $this->validationResult->isValid();
    }

}
