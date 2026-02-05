<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

class HeaderAnalyzer
{
    private const array FIELD_SYNONYMS = [
        'booked_date' => [
            'high' => ['datum', 'date', 'booking date', 'transaction date', 'posted', 'dátum', 'dátum transakcie'],
            'medium' => ['valuta', 'value date', 'effective date'],
            'low' => ['time', 'timestamp', 'when'],
        ],
        'amount' => [
            'high' => ['amount', 'betrag', 'suma', 'částka', 'sum', 'total', 'suma transakcie'],
            'medium' => ['value', 'price', 'cost', 'wert', 'cena'],
            'low' => ['money', 'payment', 'balance'],
        ],
        'description' => [
            'high' => ['description', 'popis', 'beschreibung', 'note', 'memo', 'details'],
            'medium' => ['reference', 'info', 'transaction details'],
            'low' => ['text', 'comment'],
        ],
        'partner' => [
            'high' => ['partner', 'counterparty', 'name', 'kontrahent', 'payee', 'payer'],
            'medium' => ['beneficiary', 'creditor', 'debtor', 'merchant'],
            'low' => ['company', 'recipient'],
        ],
        'currency' => [
            'high' => ['currency', 'mena', 'währung', 'curr', 'ccy'],
            'medium' => ['currency code', 'iso currency'],
            'low' => [],
        ],
        'balance_after_transaction' => [
            'high' => ['balance', 'saldo', 'kontostand', 'zostatok', 'account balance', 'running balance'],
            'medium' => ['balance after', 'new balance', 'ending balance', 'closing balance'],
            'low' => ['available', 'remaining'],
        ],
    ];

    public function analyze(string $header): HeaderAnalysisResult
    {
        $header = trim($header);
        $headerLower = mb_strtolower($header);

        foreach (self::FIELD_SYNONYMS as $field => $groups) {
            foreach ($groups as $level => $synonyms) {
                foreach ($synonyms as $synonym) {
                    $similarity = $this->jaroWinklerSimilarity($headerLower, mb_strtolower($synonym));
                    if ($similarity >= 0.85) {
                        return new HeaderAnalysisResult($field, $similarity, $level);
                    }
                }
            }
        }

        return HeaderAnalysisResult::noMatch();
    }

    /**
     * Jaro-Winkler similarity (0-1). Prefer prefix matches.
     */
    public function jaroWinklerSimilarity(string $s1, string $s2): float
    {
        if ($s1 === $s2) {
            return 1.0;
        }
        $len1 = strlen($s1);
        $len2 = strlen($s2);
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }
        $matchDistance = (int) (max($len1, $len2) / 2) - 1;
        $matchDistance = max(0, $matchDistance);
        $s1Matches = array_fill(0, $len1, false);
        $s2Matches = array_fill(0, $len2, false);
        $matches = 0;
        $transpositions = 0;
        $i = 0;
        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $len2);
            for ($j = $start; $j < $end; $j++) {
                if ($s2Matches[$j] || $s1[$i] !== $s2[$j]) {
                    continue;
                }
                $s1Matches[$i] = true;
                $s2Matches[$j] = true;
                $matches++;
                break;
            }
        }
        if ($matches === 0) {
            return 0.0;
        }
        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if (! $s1Matches[$i]) {
                continue;
            }
            while (! $s2Matches[$k]) {
                $k++;
            }
            if ($s1[$i] !== $s2[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $jaro = ($matches / $len1 + $matches / $len2 + ($matches - $transpositions / 2) / $matches) / 3;
        $prefix = 0;
        $prefixScale = min(4, min($len1, $len2));
        for ($i = 0; $i < $prefixScale && $s1[$i] === $s2[$i]; $i++) {
            $prefix++;
        }
        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}
