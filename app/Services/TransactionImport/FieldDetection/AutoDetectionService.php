<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

use App\Services\TransactionImport\Parsers\AmountParser;
use App\Services\TransactionImport\Parsers\DateParser;

class AutoDetectionService
{
    public function __construct(
        private readonly HeaderAnalyzer $headerAnalyzer,
        private readonly DataProfiler $dataProfiler,
        private readonly PatternMatcher $patternMatcher,
        private readonly ConfidenceAggregator $confidenceAggregator,
        private readonly AmountParser $amountParser,
        private readonly DateParser $dateParser,
    ) {}

    /**
     * Detect column-to-field mappings and date/amount formats from headers and sample rows.
     *
     * @param  array<int, string>  $headers  Header for each column index
     * @param  array<int, array<int, string|null>>  $sampleRows  Rows as arrays of cell values
     * @return array{mappings: array<int, array{field: string|null, confidence: float, signals: array}>, detected_date_format: string|null, detected_amount_format: string|null, overall_confidence: float}
     */
    public function detectMappings(array $headers, array $sampleRows): array
    {
        $columnCount = count($headers);
        $columnValues = [];
        for ($c = 0; $c < $columnCount; $c++) {
            $columnValues[$c] = array_map(fn ($row) => $row[$c] ?? null, $sampleRows);
        }

        $mappings = [];
        $dateColumnIndex = null;
        $amountColumnIndex = null;

        for ($colIndex = 0; $colIndex < $columnCount; $colIndex++) {
            $header = $headers[$colIndex] ?? '';
            $values = $columnValues[$colIndex] ?? [];
            $mc = $this->confidenceAggregator->calculateMappingConfidence($header, $values);
            $mappings[$colIndex] = [
                'field' => $mc->suggestedField,
                'confidence' => $mc->confidence,
                'signals' => $mc->signals,
            ];
            if ($mc->suggestedField === 'booked_date') {
                $dateColumnIndex = $colIndex;
            }
            if ($mc->suggestedField === 'amount') {
                $amountColumnIndex = $colIndex;
            }
        }

        $detectedDateFormat = null;
        if ($dateColumnIndex !== null && isset($columnValues[$dateColumnIndex])) {
            $dateValues = array_map(fn ($v) => (string) $v, array_filter($columnValues[$dateColumnIndex]));
            $detectedDateFormat = $this->dateParser->detectFormatFromSamples($dateValues);
        }

        $detectedAmountFormat = null;
        if ($amountColumnIndex !== null && isset($columnValues[$amountColumnIndex])) {
            $amountValues = array_map(fn ($v) => (string) $v, array_filter($columnValues[$amountColumnIndex]));
            $detectedAmountFormat = $this->amountParser->detectFormatFromSamples($amountValues);
        }

        $overallConfidence = $this->calculateOverallConfidence($mappings);

        return [
            'mappings' => $mappings,
            'detected_date_format' => $detectedDateFormat,
            'detected_amount_format' => $detectedAmountFormat,
            'overall_confidence' => $overallConfidence,
        ];
    }

    /** @param array<int, array{field: string|null, confidence: float}> $mappings */
    private function calculateOverallConfidence(array $mappings): float
    {
        $required = ['booked_date', 'amount'];
        $sum = 0.0;
        $count = 0;
        foreach ($mappings as $m) {
            if ($m['field'] !== null) {
                $sum += $m['confidence'];
                $count++;
            }
        }
        if ($count === 0) {
            return 0.0;
        }
        return round($sum / max($count, 1), 4);
    }
}
