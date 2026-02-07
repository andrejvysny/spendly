<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

class ConfidenceAggregator
{
    private const float HEADER_EXACT_WEIGHT = 0.4;

    private const float HEADER_FUZZY_WEIGHT = 0.25;

    private const float PATTERN_DATA_WEIGHT = 0.35;

    private const float PROFILE_STATS_WEIGHT = 0.2;

    private const float AUTO_MAP_THRESHOLD = 0.75;

    public function __construct(
        private readonly HeaderAnalyzer $headerAnalyzer,
        private readonly DataProfiler $dataProfiler,
        private readonly PatternMatcher $patternMatcher,
    ) {}

    /**
     * Calculate mapping confidence for a column (header + sample values).
     *
     * @param  array<string>  $sampleValues
     * @return MappingConfidence
     */
    public function calculateMappingConfidence(string $header, array $sampleValues): MappingConfidence
    {
        $headerResult = $this->headerAnalyzer->analyze($header);
        $profile = $this->dataProfiler->profileColumn($sampleValues);

        $signals = [];
        $headerScore = 0.0;
        if ($headerResult->hasMatch()) {
            $headerScore = $headerResult->level === 'high' ? 1.0 : ($headerResult->level === 'medium' ? 0.8 : 0.6);
            $headerScore *= $headerResult->similarity;
            $signals['header'] = $headerScore;
        }

        $patternScore = 0.0;
        $suggestedFromPattern = null;
        if ($headerResult->hasMatch()) {
            $patternScore = $this->patternMatcher->scoreColumnForField($sampleValues, $headerResult->field);
            $suggestedFromPattern = $headerResult->field;
            $signals['pattern'] = $patternScore;
        } else {
            $bestType = null;
            $bestScore = 0.0;
            foreach (['amount', 'date', 'iban', 'currency'] as $type) {
                $s = $this->patternMatcher->scoreColumnForField($sampleValues, $type);
                if ($s > $bestScore) {
                    $bestScore = $s;
                    $bestType = $type;
                }
            }
            $patternScore = $bestScore;
            $suggestedFromPattern = $bestType;
            $signals['pattern'] = $patternScore;
        }

        $profileScore = 0.0;
        if ($headerResult->hasMatch() && isset($profile->typeScores[$headerResult->field])) {
            $profileScore = $profile->typeScores[$headerResult->field];
            $signals['profile'] = $profileScore;
        } elseif ($suggestedFromPattern !== null && isset($profile->typeScores[$suggestedFromPattern])) {
            $profileScore = $profile->typeScores[$suggestedFromPattern];
            $signals['profile'] = $profileScore;
        }

        $headerWeight = $headerResult->similarity >= 0.95 ? self::HEADER_EXACT_WEIGHT : self::HEADER_FUZZY_WEIGHT;
        $confidence = $headerScore * $headerWeight + $patternScore * self::PATTERN_DATA_WEIGHT + $profileScore * self::PROFILE_STATS_WEIGHT;

        if ($headerResult->hasMatch() && $patternScore >= 0.5) {
            $confidence = min(1.0, $confidence + 0.1);
        }
        $suggestedField = $headerResult->field ?? $suggestedFromPattern;

        return new MappingConfidence($suggestedField, round($confidence, 4), $signals);
    }

    public function getAutoMapThreshold(): float
    {
        return self::AUTO_MAP_THRESHOLD;
    }
}
