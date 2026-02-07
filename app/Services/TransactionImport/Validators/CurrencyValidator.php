<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\Validators;

class CurrencyValidator
{
    /** ISO 4217 common currency codes (subset used in app) */
    private const array VALID_CODES = [
        'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'HUF', 'SEK', 'NOK', 'DKK',
        'JPY', 'CAD', 'AUD', 'RON', 'BGN', 'HRK', 'RUB', 'TRY', 'CNY', 'INR',
    ];

    public function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        if (strlen($code) !== 3) {
            return false;
        }

        return in_array($code, self::VALID_CODES, true);
    }

    /** @return list<string> */
    public function getValidCodes(): array
    {
        return self::VALID_CODES;
    }
}
