<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\DismissedRecurringSuggestion;
use App\Models\RecurringDetectionSetting;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based recurring-series detection (algorithm v2).
 *
 * Per payee+currency group, infers a recurring interval with a quorum of
 * gaps (tolerating skipped occurrences as k x interval and a bounded number
 * of outliers), segments amounts into price plateaus (so price increases do
 * not break a series), splits interleaved same-payee subscriptions by amount
 * clustering, and scores every series with a 0-100 confidence.
 * Tunables in config/recurring.php; per-user settings in
 * recurring_detection_settings.
 */
class RecurringDetectionService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * Run recurring detection for a user. Optionally limit to one account (e.g. after import).
     */
    public function runForUser(int $userId, ?int $accountId = null): int
    {
        $settings = RecurringDetectionSetting::forUser($userId);
        $lookbackMonths = (int) ($settings->lookback_months ?? 0);
        if ($lookbackMonths <= 0) {
            $lookbackMonths = $this->configInt('recurring.lookback_months_default', 24);
        }
        $from = Carbon::now()->subMonths($lookbackMonths);
        $to = Carbon::now();

        $created = 0;

        if ($settings->scope === RecurringDetectionSetting::SCOPE_PER_ACCOUNT) {
            /** @var array<int> $accountIds */
            $accountIds = $accountId !== null
                ? [$accountId]
                : \App\Models\Account::where('user_id', $userId)->pluck('id')->all();

            foreach ($accountIds as $aid) {
                $created += $this->runDetection($userId, $settings, $from, $to, $aid);
            }
        } else {
            $created += $this->runDetection($userId, $settings, $from, $to, null);
        }

        return $created;
    }

    /**
     * Run detection for one user with given settings and optional account scope.
     */
    private function runDetection(
        int $userId,
        RecurringDetectionSetting $settings,
        Carbon $from,
        Carbon $to,
        ?int $accountId
    ): int {
        $transactions = $this->transactionRepository->getForRecurringDetection($userId, $from, $to, $accountId);

        $created = 0;
        $keptFingerprints = [];

        foreach ($this->groupTransactions($transactions, $settings, $accountId) as $group) {
            /** @var Collection<int, Transaction> $txs */
            $txs = $group['txs']->sortBy('booked_date')->values();
            if ($txs->count() < 2) {
                continue;
            }

            foreach ($this->inferSeriesCandidates($txs, $settings) as $series) {
                $seriesInterval = $series['interval'];
                $seriesClusterOrdinal = $series['cluster_ordinal'];
                $fingerprint = $this->buildFingerprint(
                    $userId,
                    $accountId,
                    $group['payee'],
                    $group['currency'],
                    is_string($seriesInterval) ? $seriesInterval : '',
                    is_int($seriesClusterOrdinal) ? $seriesClusterOrdinal : 0
                );

                if ($this->isDismissed($userId, $fingerprint)) {
                    continue;
                }

                $existing = RecurringGroup::where('user_id', $userId)
                    ->whereIn('status', [RecurringGroup::STATUS_CONFIRMED, RecurringGroup::STATUS_DISMISSED])
                    ->where('dismissal_fingerprint', $fingerprint)
                    ->exists();

                if ($existing) {
                    continue;
                }

                if ($this->persistSuggestion($userId, $settings, $accountId, $group['currency'], $fingerprint, $series)) {
                    $created++;
                }
                $keptFingerprints[] = $fingerprint;
            }
        }

        $this->reconcileStaleSuggestions($userId, $accountId, $keptFingerprints);

        return $created;
    }

    /**
     * Upsert a suggested group for the detected series. Returns true when a new
     * row was created (row ids stay stable across re-runs).
     *
     * @param  array<string, mixed>  $series
     */
    private function persistSuggestion(
        int $userId,
        RecurringDetectionSetting $settings,
        ?int $accountId,
        string $currency,
        string $fingerprint,
        array $series
    ): bool {
        /** @var Collection<int, Transaction> $txs */
        $txs = $series['txs'];
        $firstTx = $txs->first();
        $lastTx = $txs->last();
        if ($firstTx === null || $lastTx === null) {
            return false;
        }

        $scope = $accountId !== null ? RecurringGroup::SCOPE_PER_ACCOUNT : RecurringGroup::SCOPE_PER_USER;

        $group = RecurringGroup::updateOrCreate(
            [
                'user_id' => $userId,
                'dismissal_fingerprint' => $fingerprint,
                'status' => RecurringGroup::STATUS_SUGGESTED,
            ],
            [
                'name' => $this->deriveName($firstTx),
                'interval' => $series['interval'],
                'interval_days' => $series['interval_days'],
                'amount_min' => $series['amount_min'],
                'amount_max' => $series['amount_max'],
                'amount_current' => $series['amount_current'],
                'confidence' => $series['confidence'],
                'currency' => $currency !== '' ? $currency : null,
                'scope' => $scope,
                'account_id' => $accountId,
                'counterparty_id' => $firstTx->counterparty_id,
                'normalized_description' => $this->normalizeDescription((string) ($firstTx->description ?? '')),
                'first_date' => $firstTx->booked_date->toDateString(),
                'last_date' => $lastTx->booked_date->toDateString(),
                'detection_config_snapshot' => [
                    'algorithm_version' => 2,
                    'transaction_ids' => $txs->pluck('id')->all(),
                    'amount_outlier_transaction_ids' => $series['amount_outlier_transaction_ids'],
                    'plateaus' => $series['plateaus'],
                    'missed_count' => $series['missed_count'],
                    'fitted_fraction' => $series['fitted_fraction'],
                    'cluster_ordinal' => $series['cluster_ordinal'],
                    'scope' => $settings->scope,
                    'group_by' => $settings->group_by,
                    'amount_variance_type' => $settings->amount_variance_type,
                    'amount_variance_value' => (float) $settings->amount_variance_value,
                    'min_occurrences' => $settings->min_occurrences,
                ],
            ]
        );

        return $group->wasRecentlyCreated;
    }

    /**
     * Group transactions by payee key and currency (and account when per-account).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{payee: string, currency: string, txs: Collection<int, Transaction>}>
     */
    private function groupTransactions(Collection $transactions, RecurringDetectionSetting $settings, ?int $accountId): array
    {
        $groups = [];

        foreach ($transactions as $tx) {
            /** @var Transaction $tx */
            $payeeKey = $this->getPayeeKey($tx, $settings->group_by);
            if ($payeeKey === null || $payeeKey === '') {
                continue;
            }

            $account = $tx->account;
            $accountCurrency = $account instanceof Account ? (string) $account->currency : '';
            $currency = strtoupper(trim((string) ($tx->currency ?: $accountCurrency)));
            $scopeKey = ($accountId !== null ? 'a'.$accountId : 'all').'|'.$payeeKey.'|'.$currency;

            if (! isset($groups[$scopeKey])) {
                $groups[$scopeKey] = ['payee' => $payeeKey, 'currency' => $currency, 'txs' => collect()];
            }
            $groups[$scopeKey]['txs']->push($tx);
        }

        return array_values($groups);
    }

    private function getPayeeKey(Transaction $tx, string $groupBy): ?string
    {
        if ($tx->counterparty_id !== null) {
            return 'm'.$tx->counterparty_id;
        }

        // Strict mode: transactions without a counterparty are not grouped at all.
        if ($groupBy === RecurringDetectionSetting::GROUP_BY_COUNTERPARTY_ONLY) {
            return null;
        }

        return 'd:'.$this->normalizeDescriptionForPayee((string) ($tx->description ?? ''));
    }

    /**
     * Normalize description for payee grouping only (not for display).
     * Strips common recurring/suffix words so e.g. "Netflix Subscription" and "Netflix" yield the same key.
     */
    private function normalizeDescriptionForPayee(string $description): string
    {
        $s = $this->normalizeDescription($description);

        $recurringSuffixWords = [
            'subscription', 'payment', 'monthly', 'recurring', 'direct debit', 'dd',
            'standing order', 'so', 'preauthorized', 'preauth', 'autopay', 'auto pay',
        ];
        foreach ($recurringSuffixWords as $word) {
            $s = (string) preg_replace('/\s*'.preg_quote($word, '/').'\s*/iu', ' ', $s);
        }
        $s = (string) preg_replace('/\s+/u', ' ', trim($s));

        return $s === '' ? $this->normalizeDescription($description) : $s;
    }

    private function normalizeDescription(string $description): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($description));

        return strtolower($s ?? '');
    }

    // ------------------------------------------------------------------
    // Series inference (algorithm v2)
    // ------------------------------------------------------------------

    /**
     * Infer zero or more recurring series from one payee group. A payee with a
     * clean single cadence yields one series (price plateaus keep it whole
     * through price changes); interleaved differently-priced subscriptions
     * fall through to amount clustering and can yield several.
     *
     * @param  Collection<int, Transaction>  $txs  chronologically sorted
     * @return list<array<string, mixed>>
     */
    private function inferSeriesCandidates(Collection $txs, RecurringDetectionSetting $settings): array
    {
        if ($this->isHighFrequencyPayee($txs)) {
            // Habitual merchants (groceries, cafes) are not subscriptions -
            // unless the cadence is genuinely clean weekly.
            $series = $this->inferSingleSeries($txs, $settings);
            if ($series !== null
                && $series['interval'] === RecurringGroup::INTERVAL_WEEKLY
                && $series['k1_fraction'] >= 0.9
            ) {
                return [$series + ['cluster_ordinal' => 0]];
            }

            return [];
        }

        $series = $this->inferSingleSeries($txs, $settings);
        if ($series !== null) {
            return [$series + ['cluster_ordinal' => 0]];
        }

        $clusters = $this->clusterAmounts($txs, $settings);
        if (count($clusters) < 2) {
            return [];
        }

        $result = [];
        foreach ($clusters as $ordinal => $cluster) {
            $clusterTxs = $cluster->sortBy('booked_date')->values();
            $clusterSeries = $this->inferSingleSeries($clusterTxs, $settings);
            // A genuine sub-series keeps its own cadence (mostly k=1 gaps).
            // A cluster that only fits by treating most gaps as "missed
            // occurrences" is cherry-picked noise, not a subscription.
            if ($clusterSeries !== null && $clusterSeries['k1_fraction'] >= 0.5) {
                $result[] = $clusterSeries + ['cluster_ordinal' => $ordinal];
            }
        }

        return $result;
    }

    /**
     * Interval fit + amount plateaus + confidence for one candidate series.
     *
     * @param  Collection<int, Transaction>  $txs  chronologically sorted
     * @return array<string, mixed>|null
     */
    private function inferSingleSeries(Collection $txs, RecurringDetectionSetting $settings): ?array
    {
        $dates = $txs->map(fn (Transaction $t) => $t->booked_date->copy()->startOfDay())->values()->all();
        $amounts = $txs->map(fn (Transaction $t) => (float) $t->amount)->values()->all();
        $n = count($dates);

        $fit = $this->fitInterval($dates);
        if ($fit === null) {
            return null;
        }

        $minOccurrences = $this->effectiveMinOccurrences($fit['interval'], $settings);
        if ($n < $minOccurrences) {
            return null;
        }

        $plateaus = $this->segmentAmountPlateaus($amounts, $settings);
        if ($plateaus === null) {
            return null;
        }

        $confidence = $this->computeConfidence($fit, $plateaus, $n, $minOccurrences, $dates);
        if ($confidence < $this->configInt('recurring.min_confidence', 40)) {
            return null;
        }

        $outlierIds = [];
        foreach ($plateaus['outlier_indexes'] as $index) {
            $tx = $txs->get($index);
            if ($tx !== null) {
                $outlierIds[] = (int) $tx->id;
            }
        }

        return [
            'txs' => $txs,
            'interval' => $fit['interval'],
            'interval_days' => (int) round($this->median($fit['normalized_deltas'])),
            'k1_fraction' => $fit['k1_fraction'],
            'fitted_fraction' => $fit['fitted_fraction'],
            'missed_count' => $fit['missed'],
            'amount_min' => $plateaus['amount_min'],
            'amount_max' => $plateaus['amount_max'],
            'amount_current' => $plateaus['amount_current'],
            'plateaus' => $plateaus['plateau_medians'],
            'amount_outlier_transaction_ids' => $outlierIds,
            'confidence' => $confidence,
        ];
    }

    /**
     * Fit the best interval from config over the series' consecutive day-gaps.
     * Each gap may span k consecutive expected occurrences (missed payments);
     * a quorum of gaps must fit and only a bounded number may be outliers.
     *
     * @param  array<int, Carbon>  $dates  chronologically sorted
     * @return array{interval: string, fitted_fraction: float, k1_fraction: float, missed: int, normalized_deltas: array<int, float>, outlier_gap_indexes: array<int, int>}|null
     */
    private function fitInterval(array $dates): ?array
    {
        $deltas = [];
        for ($i = 1; $i < count($dates); $i++) {
            $deltas[] = (int) abs($dates[$i]->diffInDays($dates[$i - 1]));
        }
        if ($deltas === []) {
            return null;
        }

        /** @var array<string, array{min: int, max: int, nominal: int, min_occurrences: int}> $intervals */
        $intervals = config('recurring.intervals', []);
        $best = null;

        foreach ($intervals as $interval => $cfg) {
            $fit = $this->fitIntervalCandidate($deltas, $cfg);
            if ($fit === null) {
                continue;
            }
            $fit['interval'] = $interval;
            $fit['nominal'] = $cfg['nominal'];

            if ($best === null || $this->intervalFitBeats($fit, $best)) {
                $best = $fit;
            }
        }

        if ($best === null) {
            return null;
        }
        unset($best['nominal']);

        return $best;
    }

    /**
     * @param  array{fitted_fraction: float, k1_fraction: float, missed: int, nominal: int}  $a
     * @param  array{fitted_fraction: float, k1_fraction: float, missed: int, nominal: int}  $b
     */
    private function intervalFitBeats(array $a, array $b): bool
    {
        // Prefer more fitted gaps, then more k=1 gaps (a true biweekly series
        // fits weekly only with k=2 everywhere), then fewer missed occurrences,
        // then the longer nominal interval.
        return [-$a['fitted_fraction'], -$a['k1_fraction'], $a['missed'], -$a['nominal']]
            < [-$b['fitted_fraction'], -$b['k1_fraction'], $b['missed'], -$b['nominal']];
    }

    /**
     * @param  array<int, int>  $deltas
     * @param  array{min: int, max: int, nominal: int}  $cfg
     * @return array{fitted_fraction: float, k1_fraction: float, missed: int, normalized_deltas: array<int, float>, outlier_gap_indexes: array<int, int>}|null
     */
    private function fitIntervalCandidate(array $deltas, array $cfg): ?array
    {
        $maxK = max(1, $this->configInt('recurring.max_missed_multiplier', 3));
        $fitted = 0;
        $k1 = 0;
        $missed = 0;
        $normalized = [];
        $outliers = [];

        foreach ($deltas as $index => $delta) {
            $bestK = null;
            $bestDistance = null;
            for ($k = 1; $k <= $maxK; $k++) {
                $normalizedDelta = $delta / $k;
                if ($normalizedDelta < $cfg['min'] || $normalizedDelta > $cfg['max']) {
                    continue;
                }
                $distance = abs($normalizedDelta - $cfg['nominal']);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestK = $k;
                }
            }

            if ($bestK === null) {
                $outliers[] = $index;

                continue;
            }

            $fitted++;
            $k1 += $bestK === 1 ? 1 : 0;
            $missed += $bestK - 1;
            $normalized[] = $delta / $bestK;
        }

        $n = count($deltas);
        $quorum = $this->configFloat('recurring.gap_quorum', 0.75);
        if ($fitted < 1 || $fitted / $n < $quorum) {
            return null;
        }
        if (count($outliers) > max(1, (int) floor(0.25 * $n))) {
            return null;
        }

        return [
            'fitted_fraction' => $fitted / $n,
            'k1_fraction' => $k1 / $fitted,
            'missed' => $missed,
            'normalized_deltas' => $normalized,
            'outlier_gap_indexes' => $outliers,
        ];
    }

    private function effectiveMinOccurrences(string $interval, RecurringDetectionSetting $settings): int
    {
        $configMin = $this->configInt("recurring.intervals.$interval.min_occurrences", 3);

        // Long intervals ignore the per-user floor: requiring 3+ yearly payments
        // inside the lookback would make yearly detection impossible.
        if (in_array($interval, [RecurringGroup::INTERVAL_SEMIANNUAL, RecurringGroup::INTERVAL_YEARLY], true)) {
            return $configMin;
        }

        return max((int) $settings->min_occurrences, $configMin);
    }

    /**
     * Segment chronological amounts into price plateaus. A genuine price change
     * starts a new plateau (confirmed by the next amount); refunds/double
     * charges become outliers; a revisited price level means these are
     * interleaved subscriptions, not one series -> null (caller falls through
     * to amount clustering).
     *
     * @param  array<int, float>  $amounts  chronological
     * @return array{plateau_medians: array<int, float>, outlier_indexes: array<int, int>, amount_current: float, amount_min: float, amount_max: float}|null
     */
    private function segmentAmountPlateaus(array $amounts, RecurringDetectionSetting $settings): ?array
    {
        $n = count($amounts);
        $plateaus = [[$amounts[0]]];
        $outliers = [];
        $maxStepPct = $this->configFloat('recurring.max_step_change_pct', 100.0) / 100.0;

        for ($i = 1; $i < $n; $i++) {
            $currentIndex = count($plateaus) - 1;
            $median = $this->median($plateaus[$currentIndex]);
            $tolerance = $this->amountTolerance($median, $settings);

            if (abs($amounts[$i] - $median) <= $tolerance) {
                $plateaus[$currentIndex][] = $amounts[$i];

                continue;
            }

            $sameSign = ($amounts[$i] <=> 0) === ($median <=> 0);
            $stepOk = $sameSign && abs($amounts[$i] - $median) / max(abs($median), 0.01) <= $maxStepPct;
            $nextConfirms = $i < $n - 1
                && abs($amounts[$i + 1] - $amounts[$i]) <= $this->amountTolerance($amounts[$i], $settings);

            if ($stepOk && ($nextConfirms || $i === $n - 1)) {
                $plateaus[] = [$amounts[$i]];
            } else {
                $outliers[] = $i;
            }
        }

        if (count($plateaus) > $this->configInt('recurring.max_amount_plateaus', 3)) {
            return null;
        }
        if (count($outliers) > max(1, (int) floor(0.2 * $n))) {
            return null;
        }

        $medians = array_map(fn (array $p) => $this->median($p), $plateaus);

        // Monotone levels: a price level that is left and later revisited means
        // two interleaved subscriptions, not a price change.
        for ($i = 0; $i < count($medians); $i++) {
            for ($j = $i + 2; $j < count($medians); $j++) {
                if (abs($medians[$j] - $medians[$i]) <= $this->amountTolerance($medians[$i], $settings)) {
                    return null;
                }
            }
        }

        $minMedian = min($medians);
        $maxMedian = max($medians);

        return [
            'plateau_medians' => array_map(fn (float $m) => round($m, 2), $medians),
            'outlier_indexes' => $outliers,
            'amount_current' => round((float) end($medians), 2),
            'amount_min' => round($minMedian - $this->amountTolerance($minMedian, $settings), 2),
            'amount_max' => round($maxMedian + $this->amountTolerance($maxMedian, $settings), 2),
        ];
    }

    private function amountTolerance(float $reference, RecurringDetectionSetting $settings): float
    {
        if ($settings->amount_variance_type === RecurringDetectionSetting::AMOUNT_VARIANCE_PERCENT) {
            $pct = (float) $settings->amount_variance_value / 100.0;

            return max(abs($reference) * $pct, $this->configFloat('recurring.amount_tolerance_floor', 0.50));
        }

        return (float) $settings->amount_variance_value;
    }

    /**
     * 1-D clustering of signed amounts for interleaved same-payee subscriptions.
     * Clusters ordered by ascending median amount; ordinal feeds the fingerprint.
     *
     * @param  Collection<int, Transaction>  $txs
     * @return list<Collection<int, Transaction>>
     */
    private function clusterAmounts(Collection $txs, RecurringDetectionSetting $settings): array
    {
        $sorted = $txs->sortBy(fn (Transaction $t) => (float) $t->amount)->values();

        $clusters = [];
        $current = collect();
        $previousAmount = null;

        foreach ($sorted as $tx) {
            $amount = (float) $tx->amount;
            if ($previousAmount !== null && ($amount - $previousAmount) > 2 * $this->amountTolerance($previousAmount, $settings)) {
                $clusters[] = $current;
                $current = collect();
            }
            $current->push($tx);
            $previousAmount = $amount;
        }
        if ($current->isNotEmpty()) {
            $clusters[] = $current;
        }

        // Clusters of one transaction are outliers (refunds, one-offs), not series.
        return array_values(array_filter($clusters, fn (Collection $c) => $c->count() >= 2));
    }

    /**
     * @param  Collection<int, Transaction>  $txs  chronologically sorted
     */
    private function isHighFrequencyPayee(Collection $txs): bool
    {
        $first = $txs->first();
        $last = $txs->last();
        if ($first === null || $last === null) {
            return false;
        }

        $spanDays = max(1, (int) abs($last->booked_date->diffInDays($first->booked_date)));
        $perMonth = $txs->count() / $spanDays * 30;

        return $perMonth > $this->configFloat('recurring.high_frequency_guard_per_month', 4.0);
    }

    /**
     * Confidence 0-100 from interval fit, amount stability, occurrence count,
     * recency, and day-of-month consistency (bonus only, never a gate).
     *
     * @param  array{interval: string, fitted_fraction: float, k1_fraction: float, missed: int}  $fit
     * @param  array{plateau_medians: array<int, float>, outlier_indexes: array<int, int>}  $plateaus
     * @param  array<int, Carbon>  $dates
     */
    private function computeConfidence(array $fit, array $plateaus, int $n, int $minOccurrences, array $dates): int
    {
        $intervalScore = 0.7 * $fit['fitted_fraction'] + 0.3 * $fit['k1_fraction'];

        $outlierRatio = count($plateaus['outlier_indexes']) / max(1, $n);
        $amountScore = max(0.0, 1.0 - 0.10 * (count($plateaus['plateau_medians']) - 1) - $outlierRatio);

        $occurrenceScore = min(1.0, $n / ($minOccurrences + 3));

        $nominal = $this->configInt('recurring.intervals.'.$fit['interval'].'.nominal', 30);
        $lastDate = end($dates);
        $daysSinceLast = $lastDate instanceof Carbon ? (int) abs(Carbon::now()->startOfDay()->diffInDays($lastDate)) : 0;
        $recencyScore = $daysSinceLast <= 1.25 * $nominal
            ? 1.0
            : max(0.0, (3 * $nominal - $daysSinceLast) / (1.75 * $nominal));

        $score = (int) round(100 * (
            0.35 * $intervalScore
            + 0.25 * $amountScore
            + 0.20 * $occurrenceScore
            + 0.20 * $recencyScore
        ));

        return max(0, min(100, $score + $this->domConsistencyBonus($dates, $fit['interval'])));
    }

    /**
     * +5 when >= 75% of dates share a day-of-month anchor within +-3 days
     * (days 28-31 bucketed as month-end). Monthly and longer intervals only.
     *
     * @param  array<int, Carbon>  $dates
     */
    private function domConsistencyBonus(array $dates, string $interval): int
    {
        $eligible = [
            RecurringGroup::INTERVAL_MONTHLY,
            RecurringGroup::INTERVAL_QUARTERLY,
            RecurringGroup::INTERVAL_SEMIANNUAL,
            RecurringGroup::INTERVAL_YEARLY,
        ];
        if (! in_array($interval, $eligible, true) || count($dates) < 2) {
            return 0;
        }

        $days = array_map(fn (Carbon $d) => min($d->day, 28), $dates);
        $anchor = $this->median($days);

        $within = 0;
        foreach ($days as $day) {
            $distance = abs($day - $anchor);
            $circular = min($distance, 28 - $distance);
            if ($circular <= 3) {
                $within++;
            }
        }

        return $within / count($days) >= 0.75 ? 5 : 0;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configFloat(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<int|float>  $values
     */
    private function median(array $values): float
    {
        $values = array_values($values);
        sort($values);
        $c = count($values);
        if ($c === 0) {
            return 0.0;
        }
        $mid = (int) floor($c / 2);
        if ($c % 2 === 1) {
            return (float) $values[$mid];
        }

        return (float) (($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * v2 fingerprint: amount-independent so dismissals and confirmed-group
     * dedup survive price changes. cluster ordinal disambiguates multiple
     * same-payee series (ordered by ascending cluster median amount).
     */
    private function buildFingerprint(int $userId, ?int $accountId, string $payeeKey, string $currency, string $interval, int $clusterOrdinal): string
    {
        $payload = implode('|', [
            'v2',
            (string) $userId,
            $accountId !== null ? (string) $accountId : 'all',
            $payeeKey,
            strtoupper($currency),
            $interval,
            'c'.$clusterOrdinal,
        ]);

        return hash('sha256', $payload);
    }

    private function isDismissed(int $userId, string $fingerprint): bool
    {
        return DismissedRecurringSuggestion::where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Delete SUGGESTED groups in scope whose fingerprint was not produced by
     * this run (payee stopped, settings changed). Replaces the old wipe-all:
     * still-valid suggestions keep their row ids and update in place.
     *
     * @param  array<int, string>  $keptFingerprints
     */
    private function reconcileStaleSuggestions(int $userId, ?int $accountId, array $keptFingerprints): void
    {
        $query = RecurringGroup::where('user_id', $userId)
            ->where('status', RecurringGroup::STATUS_SUGGESTED);

        if ($accountId !== null) {
            $query->where('scope', RecurringGroup::SCOPE_PER_ACCOUNT)
                ->where('account_id', $accountId);
        } else {
            $query->where('scope', RecurringGroup::SCOPE_PER_USER);
        }

        if ($keptFingerprints !== []) {
            $query->whereNotIn('dismissal_fingerprint', $keptFingerprints);
        }

        $query->delete();
    }

    private function deriveName(Transaction $tx): string
    {
        if ($tx->counterparty_id !== null && $tx->relationLoaded('counterparty') && $tx->counterparty !== null) {
            /** @var \App\Models\Counterparty $counterparty */
            $counterparty = $tx->counterparty;

            return $counterparty->name;
        }
        $desc = $tx->description ?? $tx->partner ?? 'Unknown';

        return strlen($desc) > 50 ? substr($desc, 0, 47).'...' : $desc;
    }

    /**
     * Confirm a suggested group: set status confirmed and link transactions.
     */
    public function confirmGroup(RecurringGroup $group, bool $addRecurringTag = true): void
    {
        if ($group->status !== RecurringGroup::STATUS_SUGGESTED) {
            return;
        }

        $snapshot = $group->detection_config_snapshot;
        /** @var array<int> $transactionIds */
        $transactionIds = $snapshot['transaction_ids'] ?? [];
        $userId = $group->getUserId();

        DB::transaction(function () use ($group, $transactionIds, $userId, $addRecurringTag): void {
            $group->update(['status' => RecurringGroup::STATUS_CONFIRMED]);

            if ($transactionIds === []) {
                return;
            }

            // Re-resolve eligibility instead of trusting snapshot ids blindly: only
            // link transactions that belong to this user (and, for a per-account
            // group, to that account).
            $query = Transaction::whereIn('id', $transactionIds)
                ->whereHas('account', fn ($q) => $q->where('user_id', $userId));

            if ($group->scope === RecurringGroup::SCOPE_PER_ACCOUNT && $group->account_id !== null) {
                $query->where('account_id', $group->account_id);
            }

            /** @var array<int> $eligibleIds */
            $eligibleIds = $query->pluck('id')->all();
            if ($eligibleIds === []) {
                return;
            }

            Transaction::whereIn('id', $eligibleIds)->update(['recurring_group_id' => $group->id]);

            if ($addRecurringTag) {
                $this->attachRecurringTagToTransactionIds($userId, $eligibleIds);
            }
        });
    }

    /**
     * Dismiss a suggested group: set status dismissed and store fingerprint so we don't re-suggest.
     */
    public function dismissGroup(RecurringGroup $group): void
    {
        if ($group->status !== RecurringGroup::STATUS_SUGGESTED) {
            return;
        }

        $fingerprint = $group->dismissal_fingerprint;
        $group->update(['status' => RecurringGroup::STATUS_DISMISSED]);

        if ($fingerprint !== null) {
            DismissedRecurringSuggestion::firstOrCreate(
                ['user_id' => $group->user_id, 'fingerprint' => $fingerprint]
            );
        }
    }

    /**
     * Attach the "Recurring" tag to given transactions (by id). Uses bulk attach to avoid N+1.
     *
     * @param  array<int>  $transactionIds
     */
    private function attachRecurringTagToTransactionIds(int $userId, array $transactionIds): void
    {
        $tag = \App\Models\Tag::where('user_id', $userId)->where('name', 'Recurring')->first();
        if ($tag === null || $transactionIds === []) {
            return;
        }

        $existing = $tag->transactions()->whereIn('transactions.id', $transactionIds)->pluck('transactions.id')->all();
        $toAttach = array_values(array_diff($transactionIds, $existing));
        if ($toAttach !== []) {
            $tag->transactions()->attach($toAttach);
        }
    }

    /**
     * Remove recurring group link from transactions, optionally remove Recurring tag, then delete the group.
     */
    public function unlinkGroup(RecurringGroup $group, bool $removeRecurringTag = true): void
    {
        $transactionIds = $group->transactions()->pluck('transactions.id')->all();
        $group->transactions()->update(['recurring_group_id' => null]);

        if ($removeRecurringTag && $transactionIds !== []) {
            $tag = \App\Models\Tag::where('user_id', $group->user_id)->where('name', 'Recurring')->first();
            if ($tag !== null) {
                $tag->transactions()->detach($transactionIds);
            }
        }

        $group->delete();
    }

    /**
     * Detach specific transactions from a confirmed recurring group (do not delete the group).
     *
     * @param  array<int, int|string>  $transactionIds
     */
    public function detachTransactionsFromGroup(RecurringGroup $group, array $transactionIds, bool $removeRecurringTag = true): void
    {
        if ($group->status !== RecurringGroup::STATUS_CONFIRMED) {
            return;
        }

        $transactionIds = array_map('intval', array_values(array_unique($transactionIds)));
        if ($transactionIds === []) {
            return;
        }

        $userId = $group->getUserId();
        $belongToGroup = $group->transactions()
            ->whereIn('transactions.id', $transactionIds)
            ->pluck('transactions.id')
            ->all();

        $toDetach = array_values(array_intersect($transactionIds, $belongToGroup));
        if ($toDetach === []) {
            return;
        }

        Transaction::whereIn('id', $toDetach)->update(['recurring_group_id' => null]);

        if ($removeRecurringTag) {
            $tag = \App\Models\Tag::where('user_id', $userId)->where('name', 'Recurring')->first();
            if ($tag !== null) {
                $tag->transactions()->detach($toDetach);
            }
        }
    }

    /**
     * Attach existing transactions to a confirmed recurring group (e.g. missed by detection).
     * Only attaches transactions that belong to the user, respect scope (per_account/per_user), and are unlinked or already in this group.
     *
     * @param  array<int, int|string>  $transactionIds
     * @return array{attached: array<int>, ineligible: array<int>} attached IDs and ineligible IDs (wrong account or already in another group)
     */
    public function attachTransactionsToGroup(RecurringGroup $group, array $transactionIds, bool $addRecurringTag = true): array
    {
        $result = ['attached' => [], 'ineligible' => []];

        if ($group->status !== RecurringGroup::STATUS_CONFIRMED) {
            return $result;
        }

        $transactionIds = array_map('intval', array_values(array_unique($transactionIds)));
        if ($transactionIds === []) {
            return $result;
        }

        $userId = $group->getUserId();
        $transactions = Transaction::with('account')->whereIn('id', $transactionIds)->get();

        $toAttach = [];
        $ineligible = [];

        foreach ($transactions as $tx) {
            $account = $tx->account;
            if (! $account instanceof Account || (int) $account->user_id !== $userId) {
                $ineligible[] = $tx->id;

                continue;
            }
            if ($group->scope === RecurringGroup::SCOPE_PER_ACCOUNT && $group->account_id !== null && (int) $tx->account_id !== (int) $group->account_id) {
                $ineligible[] = $tx->id;

                continue;
            }
            if ($tx->recurring_group_id !== null && (int) $tx->recurring_group_id !== (int) $group->id) {
                $ineligible[] = $tx->id;

                continue;
            }
            $toAttach[] = $tx->id;
        }

        $result['ineligible'] = $ineligible;

        if ($toAttach === []) {
            return $result;
        }

        Transaction::whereIn('id', $toAttach)->update(['recurring_group_id' => $group->id]);
        $result['attached'] = $toAttach;

        if ($addRecurringTag) {
            $this->attachRecurringTagToTransactionIds($userId, $toAttach);
        }

        return $result;
    }
}
