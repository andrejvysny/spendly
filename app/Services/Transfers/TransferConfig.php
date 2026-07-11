<?php

declare(strict_types=1);

namespace App\Services\Transfers;

final readonly class TransferConfig
{
    /**
     * @param  list<string>  $singleLegPatterns
     */
    public function __construct(
        public int $dateGapDays,
        public float $amountTolerance,
        public float $amountTolerancePercent,
        public float $heuristicAutoThreshold,
        public float $heuristicReviewThreshold,
        public bool $crossCurrencyEnabled,
        public float $crossCurrencyTolerancePercent,
        public bool $crossCurrencyAutoMark,
        public bool $singleLegAutoMark,
        public array $singleLegPatterns,
        public int $windowPaddingDays,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<int, string> $patterns */
        $patterns = config('transfers.single_leg.patterns', []);

        return new self(
            dateGapDays: self::intValue('transfers.date_gap_days', 3),
            amountTolerance: self::floatValue('transfers.amount_tolerance', 0.01),
            amountTolerancePercent: self::floatValue('transfers.amount_tolerance_percent', 0.0),
            heuristicAutoThreshold: self::floatValue('transfers.heuristic.auto_threshold', 0.60),
            heuristicReviewThreshold: self::floatValue('transfers.heuristic.review_threshold', 0.35),
            crossCurrencyEnabled: (bool) config('transfers.cross_currency.enabled', true),
            crossCurrencyTolerancePercent: self::floatValue('transfers.cross_currency.tolerance_percent', 3.0),
            crossCurrencyAutoMark: (bool) config('transfers.cross_currency.auto_mark', false),
            singleLegAutoMark: (bool) config('transfers.single_leg.auto_mark', true),
            singleLegPatterns: array_values($patterns),
            windowPaddingDays: self::intValue('transfers.detection_window_padding_days', 3),
        );
    }

    private static function intValue(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function floatValue(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Effective amount tolerance for a leg of the given absolute amount.
     */
    public function amountToleranceFor(float $absAmount): float
    {
        return max($this->amountTolerance, $this->amountTolerancePercent / 100.0 * abs($absAmount));
    }
}
