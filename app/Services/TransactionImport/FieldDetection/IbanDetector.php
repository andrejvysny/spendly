<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

class IbanDetector
{
    /**
     * Check if the value looks like an IBAN (2 letters + 2 digits + up to 30 alphanumeric).
     */
    public function looksLikeIban(string $value): bool
    {
        $value = str_replace(' ', '', strtoupper(trim($value)));
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $value) === 1;
    }

    /**
     * Validate IBAN using Mod-97 checksum (ISO 13616).
     */
    public function isValid(string $value): bool
    {
        $value = str_replace(' ', '', strtoupper(trim($value)));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $value)) {
            return false;
        }

        $rearranged = substr($value, 4) . substr($value, 0, 4);
        $numeric = '';
        for ($i = 0, $len = strlen($rearranged); $i < $len; $i++) {
            $c = $rearranged[$i];
            if (ctype_alpha($c)) {
                $numeric .= (string) (ord($c) - ord('A') + 10);
            } else {
                $numeric .= $c;
            }
        }

        $remainder = 0;
        $len = strlen($numeric);
        for ($i = 0; $i < $len; $i++) {
            $remainder = ($remainder * 10 + (int) $numeric[$i]) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Country-specific length check (optional). ISO 13616 specifies length per country.
     */
    public function hasValidLength(string $value): bool
    {
        $value = str_replace(' ', '', $value);
        $len = strlen($value);
        if ($len < 15 || $len > 34) {
            return false;
        }
        $country = substr($value, 0, 2);
        $lengths = [
            'SK' => 24, 'CZ' => 24, 'DE' => 22, 'AT' => 20, 'GB' => 22,
            'FR' => 27, 'ES' => 24, 'IT' => 27, 'NL' => 18, 'PL' => 28,
            'LT' => 20, 'LV' => 21, 'EE' => 20, 'HU' => 28, 'RO' => 24,
        ];

        return ! isset($lengths[$country]) || $len === $lengths[$country];
    }
}
