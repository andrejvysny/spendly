<?php

namespace App\Services\TransactionImport;

class TransactionDto
{
    /**
     * Initializes a new TransactionDto with transaction data and its validation result.
     *
     * @param array $data The transaction data.
     * @param ValidationResult $validationResult The validation result associated with the transaction.
     */
    public function __construct(
        private array $data,
        private readonly ValidationResult $validationResult,
    ) {}

    /**
     * Returns the transaction data as an array.
     *
     * @return array The transaction data.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Retrieves a value from the transaction data by key, returning a default value if the key does not exist.
     *
     * @param string $key The key to look up in the transaction data.
     * @param mixed $default The value to return if the key is not found.
     * @return mixed The value associated with the key, or the default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Sets a value for the specified key in the transaction data.
     *
     * @param string $key The key to set in the transaction data array.
     * @param mixed $value The value to assign to the specified key.
     * @return TransactionDto The current instance for method chaining.
     */
    public function set(string $key, mixed $value): TransactionDto
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Returns the validation result associated with the transaction data.
     *
     * @return ValidationResult The validation result object.
     */
    public function getValidationResult(): ValidationResult
    {
        return $this->validationResult;
    }

    /**
     * Determines whether the transaction data is valid.
     *
     * @return bool True if the transaction data passes validation; otherwise, false.
     */
    public function isValid(): bool
    {
        return $this->validationResult->isValid();
    }
}
