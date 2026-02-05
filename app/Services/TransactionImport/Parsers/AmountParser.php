<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\Parsers;

class AmountParser
{
    public const string FORMAT_EU = 'eu';

    public const string FORMAT_US = 'us';

    public const string FORMAT_SIMPLE = 'simple';

    /**
     * Parse a string amount with optional format hint. Handles EU (1.234,56), US (1,234.56),
     * and parentheses for negative (100.00) -> -100.00.
     */
    public function parse(string $value, ?string $hintFormat = null): AmountParseResult
    {
        $value = $this->normalize($value);
        if ($value === '') {
            return AmountParseResult::success(0.0, self::FORMAT_SIMPLE, 0.0, ['Empty value']);
        }

        $isNegative = $this->isParenthesesNegative($value);
        if ($isNegative) {
            $value = trim($value, ' ()');
        }

        $format = $hintFormat ?? $this->detectFormat($value);
        $amount = $this->parseWithFormat($value, $format);

        if ($isNegative) {
            $amount = -abs($amount);
        }

        $warnings = $this->validateAmount($amount);
        $confidence = $this->calculateConfidence($value, $format);

        return new AmountParseResult($amount, $format, $confidence, $warnings);
    }

    /**
     * Detect amount format from a single value (last separator wins: last comma = decimal in EU, last dot = decimal in US).
     */
    public function detectFormat(string $value): string
    {
        $value = $this->normalize($value);
        $commaPos = strrpos($value, ',');
        $dotPos = strrpos($value, '.');

        if ($commaPos !== false && $dotPos !== false) {
            return $commaPos > $dotPos ? self::FORMAT_EU : self::FORMAT_US;
        }
        if ($commaPos !== false) {
            return self::FORMAT_EU;
        }
        if ($dotPos !== false) {
            return self::FORMAT_US;
        }

        return self::FORMAT_SIMPLE;
    }

    /**
     * Detect format from multiple sample values (majority wins).
     *
     * @param  array<string>  $values
     */
    public function detectFormatFromSamples(array $values): string
    {
        $formats = [];
        foreach ($values as $v) {
            $v = $this->normalize((string) $v);
            if ($v !== '') {
                $formats[] = $this->detectFormat($v);
            }
        }
        if ($formats === []) {
            return self::FORMAT_SIMPLE;
        }
        $counts = array_count_values($formats);
        arsort($counts, SORT_NUMERIC);

        return (string) array_key_first($counts);
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = str_replace(["\xc2\xa0", "\xa0"], ' ', $value);

        return trim($value);
    }

    private function isParenthesesNegative(string $value): bool
    {
        $trimmed = trim($value);
        return str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')');
    }

    private function parseWithFormat(string $value, string $format): float
    {
        $value = str_replace([' ', "\xc2\xa0"], '', $value);
        if ($format === self::FORMAT_EU) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($format === self::FORMAT_US) {
            $value = str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }
        return (float) $value;
    }

    /** @return list<string> */
    private function validateAmount(float $amount): array
    {
        $warnings = [];
        if (abs($amount) > 10_000_000) {
            $warnings[] = 'Unusually large amount';
        }
        if (abs($amount) < 0.01 && $amount != 0) {
            $warnings[] = 'Near-zero amount';
        }
        return $warnings;
    }

    private function calculateConfidence(string $value, string $format): float
    {
        if ($value === '') {
            return 0.0;
        }
        $parsed = $this->parseWithFormat($value, $format);
        if (is_numeric($value) && (string) (float) $value === $value) {
            return 1.0;
        }
        if ($format !== self::FORMAT_SIMPLE) {
            return 0.9;
        }
        return 0.8;
    }
}
