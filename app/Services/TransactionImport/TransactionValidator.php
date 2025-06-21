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
     * @param  array  $data  Transaction data to validate
     * @param  array  $configuration  Additional configuration
     */
    public function validate(array $data, array $configuration = []): ValidationResult\ValidationResult
    {
        $errors = [];

        // Validate required fields
        $this->validateRequiredFields($data, $errors);

        // Validate data types and formats
        $this->validateDataTypes($data, $errors);

        // Validate business rules
        $this->validateBusinessRules($data, $configuration, $errors);

        return new ValidationResult\ValidationResult(empty($errors), $errors);
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
            if (! isset($data[$field]) || $data[$field] === '') {
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
     * Validate business rules.
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
     * Check if a date string is valid.
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
     * Check if a currency code is valid.
     */
    private function isValidCurrency(string $currency): bool
    {
        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY'];
        if (in_array(strtoupper($currency), $validCurrencies, true)) {
            return true;
        }

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
        // Basic format check
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
            return false;
        }

        // Implement mod-97 checksum validation
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            $numeric .= is_numeric($char) ? $char : (ord($char) - ord('A') + 10);
        }

        return \bcmod($numeric, '97') === '1';
    }
}
