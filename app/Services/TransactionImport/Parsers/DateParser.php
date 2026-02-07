<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\Parsers;

use Carbon\Carbon;

class DateParser
{
    public const string FORMAT_ISO = 'Y-m-d';

    public const string FORMAT_DMY = 'd/m/Y';

    public const string FORMAT_DMY_DOT = 'd.m.Y';

    public const string FORMAT_MDY = 'm/d/Y';

    private const string MIN_REASONABLE = '2000-01-01';

    /**
     * Parse a date string with optional format and locale hints. Prefers ISO 8601, then DMY/MDY.
     */
    public function parse(string $value, ?string $hintFormat = null, ?string $locale = null): DateParseResult
    {
        $value = trim($value);
        if ($value === '') {
            return DateParseResult::failure('', 0.0, ['Empty value']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $this->parseIso($value);
        }

        $formats = $this->getFormatsForLocale($locale);
        if ($hintFormat !== null && in_array($hintFormat, $formats, true)) {
            $formats = array_merge([$hintFormat], array_diff($formats, [$hintFormat]));
        }

        foreach ($formats as $format) {
            $date = $this->tryFormat($value, $format);
            if ($date !== null && $this->isReasonable($date)) {
                return DateParseResult::success($date, $format, 0.9);
            }
        }

        return $this->parseAmbiguous($value, $locale);
    }

    /**
     * Detect date format from samples using "impossible date" evidence (e.g. day > 12 reveals DMY).
     *
     * @param  array<string>  $values
     */
    public function detectFormatFromSamples(array $values): string
    {
        $dmyCount = 0;
        $mdyCount = 0;
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                return self::FORMAT_ISO;
            }
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $v, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                if ($a > 12) {
                    $dmyCount++;
                } elseif ($b > 12) {
                    $mdyCount++;
                }
            }
        }
        if ($dmyCount > $mdyCount) {
            return self::FORMAT_DMY;
        }
        if ($mdyCount > $dmyCount) {
            return self::FORMAT_MDY;
        }

        return self::FORMAT_DMY;
    }

    private function parseIso(string $value): DateParseResult
    {
        try {
            $date = Carbon::parse($value);
            if ($this->isReasonable($date)) {
                return DateParseResult::success($date, self::FORMAT_ISO, 1.0);
            }
            return DateParseResult::success($date, self::FORMAT_ISO, 0.9, ['Date outside reasonable range']);
        } catch (\Throwable) {
            return DateParseResult::failure(self::FORMAT_ISO, 0.0, ['Invalid ISO date']);
        }
    }

    private function tryFormat(string $value, string $format): ?Carbon
    {
        $normalized = $value;
        if ($format === self::FORMAT_DMY || $format === self::FORMAT_MDY) {
            $normalized = str_replace('.', '/', $value);
        }
        try {
            $date = Carbon::createFromFormat($format, $normalized);
            return $date ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isReasonable(Carbon $date): bool
    {
        $min = Carbon::parse(self::MIN_REASONABLE)->startOfDay();
        $max = Carbon::now()->addYear()->endOfDay();
        return $date->between($min, $max);
    }

    /** @return list<string> */
    private function getFormatsForLocale(?string $locale): array
    {
        if ($locale === null) {
            return [self::FORMAT_DMY, self::FORMAT_DMY_DOT, self::FORMAT_MDY];
        }
        $locale = strtolower($locale);
        if (str_contains($locale, 'sk') || str_contains($locale, 'cs') || str_contains($locale, 'de')) {
            return [self::FORMAT_DMY, self::FORMAT_DMY_DOT, self::FORMAT_MDY];
        }
        if (str_contains($locale, 'en_us') || str_contains($locale, 'us')) {
            return [self::FORMAT_MDY, self::FORMAT_DMY];
        }
        return [self::FORMAT_DMY, self::FORMAT_DMY_DOT, self::FORMAT_MDY];
    }

    private function parseAmbiguous(string $value, ?string $locale): DateParseResult
    {
        $formats = $this->getFormatsForLocale($locale);
        foreach ($formats as $format) {
            $date = $this->tryFormat($value, $format);
            if ($date !== null && $this->isReasonable($date)) {
                return DateParseResult::success($date, $format, 0.7, ['Ambiguous date format']);
            }
        }
        return DateParseResult::failure('', 0.0, ['Could not parse date']);
    }
}
