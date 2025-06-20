<?php

namespace App\Services\TransactionImport;

/**
 * Validates transaction data before persistence.
 */
class TransactionValidator
{
    /**
     * Validate transaction data.
     *
     * @param array $data Transaction data to validate
     * @param array $configuration Additional configuration
     * @return ValidationResult
     */
    public function validate(array $data, array $configuration = []): ValidationResult
    {
        $errors = [];

        // Validate required fields
        $this->validateRequiredFields($data, $errors);

        // Validate data types and formats
        $this->validateDataTypes($data, $errors);

        // Validate business rules
        $this->validateBusinessRules($data, $configuration, $errors);

        return new ValidationResult(empty($errors), $errors);
    }

    /**
     * Validate required fields are present.
     */
    private function validateRequiredFields(array $data, array &$errors): void
    {
        $requiredFields = [
            'booked_date' => 'Booked date',
            'amount' => 'Amount',
            'partner' => 'Partner',
            'description' => 'Description',
            'account_id' => 'Account ID',
            'currency' => 'Currency',
        ];

        foreach ($requiredFields as $field => $label) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $errors[] = "{$label} is required";
            }
        }
    }

    /**
     * Validate data types and formats.
     */
    private function validateDataTypes(array $data, array &$errors): void
    {
        // Validate amount is numeric
        if (isset($data['amount']) && !is_numeric($data['amount'])) {
            $errors[] = "Amount must be a number";
        }

        // Validate dates
        if (isset($data['booked_date']) && !$this->isValidDate($data['booked_date'])) {
            $errors[] = "Invalid booked date format";
        }

        if (isset($data['processed_date']) && !$this->isValidDate($data['processed_date'])) {
            $errors[] = "Invalid processed date format";
        }

        // Validate currency code
        if (isset($data['currency']) && !$this->isValidCurrency($data['currency'])) {
            $errors[] = "Invalid currency code";
        }

        // Validate IBANs if present
        if (!empty($data['source_iban']) && !$this->isValidIban($data['source_iban'])) {
            $errors[] = "Invalid source IBAN format";
        }

        if (!empty($data['target_iban']) && !$this->isValidIban($data['target_iban'])) {
            $errors[] = "Invalid target IBAN format";
        }
    }

    /**
     * Validate business rules.
     */
    private function validateBusinessRules(array $data, array $configuration, array &$errors): void
    {
        // Validate amount is not zero
        if (isset($data['amount']) && $data['amount'] == 0) {
            $errors[] = "Amount cannot be zero";
        }

        // Validate account exists (in non-preview mode)
        if (!($configuration['preview_mode'] ?? false)) {
            if (empty($data['account_id'])) {
                $errors[] = "Account ID is required for import";
            }
        }

        // Validate description length
        if (isset($data['description']) && strlen($data['description']) > 1000) {
            $errors[] = "Description is too long (max 1000 characters)";
        }

        // Validate partner length
        if (isset($data['partner']) && strlen($data['partner']) > 255) {
            $errors[] = "Partner name is too long (max 255 characters)";
        }
    }

    /**
     * Check if a date string is valid.
     */
    private function isValidDate($date): bool
    {
        if (!is_string($date)) {
            return false;
        }

        try {
            $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            return $parsed && $parsed->format('Y-m-d H:i:s') === $date;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a currency code is valid.
     */
    private function isValidCurrency(string $currency): bool
    {
        // Basic validation - should be 3 uppercase letters
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    /**
     * Basic IBAN validation.
     */
    private function isValidIban(string $iban): bool
    {
        // Remove spaces and convert to uppercase
        $iban = strtoupper(str_replace(' ', '', $iban));

        // Basic format check (2 letters followed by 2 digits and up to 30 alphanumeric characters)
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban) === 1;
    }
}

/**
 * Validation result value object.
 */
class ValidationResult
{
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = []
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
