<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

use App\Services\TransactionImport\Validators\CurrencyValidator;

class DataProfiler
{
    public function profileColumn(array $sampleValues): ColumnProfile
    {
        $normalized = array_map(fn ($v) => $v === null || $v === '' ? null : trim((string) $v), $sampleValues);
        $total = count($normalized);
        $nullCount = count(array_filter($normalized, fn ($v) => $v === null || $v === ''));
        $nullRatio = $total > 0 ? $nullCount / $total : 0.0;

        $nonEmpty = array_values(array_filter($normalized, fn ($v) => $v !== null && $v !== ''));
        $uniqueRatio = count($nonEmpty) > 0 ? count(array_unique($nonEmpty)) / count($nonEmpty) : 0.0;
        $lengths = array_map('strlen', $nonEmpty);
        $avgLength = $lengths !== [] ? array_sum($lengths) / count($lengths) : 0.0;
        $charClasses = $this->analyzeCharClasses($nonEmpty);
        $typeScores = $this->scoreTypes($nonEmpty);

        return new ColumnProfile($nullRatio, $uniqueRatio, $avgLength, $charClasses, $typeScores);
    }

    /** @param  array<string>  $values */
    private function analyzeCharClasses(array $values): array
    {
        $digits = 0;
        $dots = 0;
        $commas = 0;
        $letters = 0;
        $total = 0;
        foreach ($values as $v) {
            $len = strlen($v);
            for ($i = 0; $i < $len; $i++) {
                $c = $v[$i];
                $total++;
                if (ctype_digit($c)) {
                    $digits++;
                } elseif (ctype_alpha($c)) {
                    $letters++;
                } elseif ($c === '.') {
                    $dots++;
                } elseif ($c === ',') {
                    $commas++;
                }
            }
        }
        return [
            'digits' => $total > 0 ? $digits / $total : 0,
            'letters' => $total > 0 ? $letters / $total : 0,
            'dots' => $total > 0 ? $dots / $total : 0,
            'commas' => $total > 0 ? $commas / $total : 0,
        ];
    }

    /** @param  array<string>  $values
     * @return array<string, float>
     */
    private function scoreTypes(array $values): array
    {
        $amountScore = 0.0;
        $dateScore = 0.0;
        $ibanScore = 0.0;
        $currencyScore = 0.0;
        $ibanDetector = new IbanDetector;
        $currencyValidator = new CurrencyValidator;

        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if (preg_match('/^-?[\d\s,.]+\s*$/', $v) && preg_match('/[,.]/', $v)) {
                $amountScore += 1.0;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) || preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$#', $v)) {
                $dateScore += 1.0;
            }
            if ($ibanDetector->looksLikeIban($v)) {
                $ibanScore += 1.0;
            }
            if (strlen($v) === 3 && $currencyValidator->isValid($v)) {
                $currencyScore += 1.0;
            }
        }
        $count = count($values) > 0 ? count($values) : 1;
        return [
            'amount' => $amountScore / $count,
            'date' => $dateScore / $count,
            'iban' => $ibanScore / $count,
            'currency' => $currencyScore / $count,
            'text' => 1.0 - max($amountScore, $dateScore, $ibanScore, $currencyScore) / $count,
        ];
    }
}
