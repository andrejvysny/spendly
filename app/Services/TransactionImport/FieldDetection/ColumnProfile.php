<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

readonly class ColumnProfile
{
    /** @param  array<string, float>  $typeScores  e.g. ['amount' => 0.9, 'date' => 0.1] */
    public function __construct(
        public float $nullRatio,
        public float $uniqueRatio,
        public float $avgLength,
        public array $charClasses,
        public array $typeScores,
    ) {}
}
