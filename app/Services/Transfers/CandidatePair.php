<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;

/**
 * A scored debit/credit candidate produced by the tier gates. Immutable
 * evidence carrier; the matcher decides which candidates get assigned.
 */
final readonly class CandidatePair
{
    public const string METHOD_IBAN_BIDIRECTIONAL = 'iban_bidirectional';

    public const string METHOD_IBAN_ONE_SIDED = 'iban_one_sided';

    public const string METHOD_HEURISTIC = 'heuristic';

    public const string METHOD_CROSS_CURRENCY = 'cross_currency';

    public const string METHOD_SINGLE_LEG = 'single_leg';

    public const string METHOD_MANUAL = 'manual';

    /**
     * @param  list<string>  $signals
     */
    public function __construct(
        public Transaction $debit,
        public Transaction $credit,
        public int $tier,
        public string $method,
        public float $score,
        public int $dateGapDays,
        public float $amountDiff,
        public array $signals = [],
    ) {}
}
