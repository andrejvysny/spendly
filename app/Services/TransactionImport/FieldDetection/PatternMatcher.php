<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

class PatternMatcher
{
    /**
     * Detect which field type the value pattern suggests (amount, date, iban, currency, text).
     */
    public function matchFieldType(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($this->looksLikeAmount($value)) {
            return 'amount';
        }
        if ($this->looksLikeDate($value)) {
            return 'date';
        }
        if ($this->looksLikeIban($value)) {
            return 'iban';
        }
        if ($this->looksLikeCurrency($value)) {
            return 'currency';
        }
        return 'text';
    }

    /**
     * Score how much a column of values matches a field type (0-1).
     *
     * @param  array<string>  $values
     */
    public function scoreColumnForField(array $values, string $field): float
    {
        $matchCount = 0;
        $total = 0;
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $total++;
            $detected = $this->matchFieldType($v);
            if ($detected === $field) {
                $matchCount++;
            }
        }
        return $total > 0 ? $matchCount / $total : 0.0;
    }

    public function looksLikeAmount(string $value): bool
    {
        $value = str_replace(' ', '', $value);
        return (bool) preg_match('/^-?[\d.,]+$/', $value) && preg_match('/[,.]/', $value);
    }

    public function looksLikeDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return true;
        }
        return (bool) preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$#', $value);
    }

    public function looksLikeIban(string $value): bool
    {
        return (new IbanDetector)->looksLikeIban($value);
    }

    public function looksLikeCurrency(string $value): bool
    {
        return strlen($value) === 3 && (new \App\Services\TransactionImport\Validators\CurrencyValidator)->isValid($value);
    }
}
