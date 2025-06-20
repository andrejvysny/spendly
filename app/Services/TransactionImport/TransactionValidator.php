<?php

namespace App\Services\TransactionImport;

/**
 * Validates transaction data before persistence.
 */
class TransactionValidator
{
    /**
     * Validates transaction data for required fields, correct data types, and business rules.
     *
     * @param array $data The transaction data to validate.
     * @param array $configuration Optional configuration affecting validation rules.
     * @return ValidationResult The result of the validation, including validity status and error messages.
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
     * Checks that all mandatory transaction fields are present and not empty.
     *
     * Adds error messages to the provided errors array for any missing required fields.
     *
     * @param array $data The transaction data to validate.
     * @param array $errors Reference to the array collecting validation error messages.
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
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $errors[] = "{$label} is required";
            }
        }
    }

    /**
     * Validates the data types and formats of transaction fields.
     *
     * Checks that the amount is numeric, dates are in accepted formats, the currency code is valid, and IBANs (if present) conform to expected patterns. Adds error messages to the provided errors array for any invalid fields.
     *
     * @param array $data The transaction data to validate.
     * @param array &$errors Reference to the array collecting validation error messages.
     */
    private function validateDataTypes(array $data, array &$errors): void
    {
        // Validate amount is numeric
        if (isset($data['amount']) && ! is_numeric($data['amount'])) {
            $errors[] = 'Amount must be a number';
        }

        // Validate dates
        if (isset($data['booked_date']) && ! $this->isValidDate($data['booked_date'])) {
            $errors[] = 'Invalid booked date format';
        }

        if (isset($data['processed_date']) && ! $this->isValidDate($data['processed_date'])) {
            $errors[] = 'Invalid processed date format';
        }

        // Validate currency code
        if (isset($data['currency']) && ! $this->isValidCurrency($data['currency'])) {
            $errors[] = 'Invalid currency code';
        }

        // Validate IBANs if present
        if (! empty($data['source_iban']) && ! $this->isValidIban($data['source_iban'])) {
            $errors[] = 'Invalid source IBAN format';
        }

        if (! empty($data['target_iban']) && ! $this->isValidIban($data['target_iban'])) {
            $errors[] = 'Invalid target IBAN format';
        }
    }

    /**
     * Applies business-specific validation rules to transaction data.
     *
     * Checks that the amount is not zero, enforces account ID presence outside preview mode, and ensures description and partner name do not exceed their maximum allowed lengths. Adds error messages to the provided errors array for any violations.
     */
    private function validateBusinessRules(array $data, array $configuration, array &$errors): void
    {
        // Validate amount is not zero
        if (isset($data['amount']) && (float) $data['amount'] === 0.0) {
            $errors[] = 'Amount cannot be zero';
        }

        // Validate account exists (in non-preview mode)
        if (! ($configuration['preview_mode'] ?? false)) {
            if (empty($data['account_id'])) {
                $errors[] = 'Account ID is required for import';
            }
        }

        // Validate description length
        if (isset($data['description']) && strlen($data['description']) > 1000) {
            $errors[] = 'Description is too long (max 1000 characters)';
        }

        // Validate partner length
        if (isset($data['partner']) && strlen($data['partner']) > 255) {
            $errors[] = 'Partner name is too long (max 255 characters)';
        }
    }

    /**
     * Determines if a date string matches any accepted date or datetime format.
     *
     * Supports multiple delimiters (hyphen, slash, dot) and various date/time orderings.
     *
     * @param mixed $date The date string to validate.
     * @return bool True if the date is valid according to accepted formats, false otherwise.
     */
    private function isValidDate($date): bool
    {
        if (! is_string($date)) {
            return false;
        }

        try {

            $formats = [
                // Hyphen delimited
                'Y-m-d',
                'Y-m-d H:i:s',
                'd-m-Y',
                'd-m-Y H:i:s',
                'm-d-Y',
                'm-d-Y H:i:s',

                // Slash delimited
                'd/m/Y',
                'm/d/Y',
                'Y/m/d',

                // Dot delimited
                'd.m.Y',
                'd.m.Y H:i:s',
                'm.d.Y',
                'm.d.Y H:i:s',
            ];

            foreach ($formats as $format) {
                $parsed = \DateTime::createFromFormat($format, $date);
                if ($parsed && $parsed->format($format) === $date) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            // If any exception occurs, the date is invalid
            return false;
        }
    }

    /**
     * Determines if the provided currency code is valid according to the ISO 4217 format.
     *
     * A valid currency code consists of exactly three uppercase letters.
     *
     * @param string $currency The currency code to validate.
     * @return bool True if the currency code is valid, false otherwise.
     */
    private function isValidCurrency(string $currency): bool
    {
        // Basic validation - should be 3 uppercase letters
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    /**
     * Checks if the provided IBAN has a valid basic format.
     *
     * Removes spaces, converts to uppercase, and verifies that the IBAN starts with two letters, followed by two digits, and up to 30 alphanumeric characters.
     *
     * @param string $iban The IBAN to validate.
     * @return bool True if the IBAN matches the expected format, false otherwise.
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
    /**
     * Creates a new ValidationResult instance representing the outcome of a validation process.
     *
     * @param bool $valid Indicates whether the validation was successful.
     * @param array $errors An array of error messages describing validation failures.
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = []
    ) {}

    /**
     * Indicates whether the validation was successful.
     *
     * @return bool True if the validation passed; false otherwise.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Returns the list of validation error messages.
     *
     * @return array The validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
