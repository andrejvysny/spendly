<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\DismissedRecurringSuggestion;
use App\Models\RecurringDetectionSetting;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RecurringDetectionService
{
    private const int LOOKBACK_MONTHS = 12;

    private const int MIN_INTERVAL_DAYS_WEEKLY = 5;

    private const int MAX_INTERVAL_DAYS_WEEKLY = 10;

    private const int MIN_INTERVAL_DAYS_MONTHLY = 25;

    private const int MAX_INTERVAL_DAYS_MONTHLY = 35;

    private const int MIN_INTERVAL_DAYS_QUARTERLY = 85;

    private const int MAX_INTERVAL_DAYS_QUARTERLY = 95;

    private const int MIN_INTERVAL_DAYS_YEARLY = 350;

    private const int MAX_INTERVAL_DAYS_YEARLY = 380;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * Run recurring detection for a user. Optionally limit to one account (e.g. after import).
     */
    public function runForUser(int $userId, ?int $accountId = null): int
    {
        $settings = RecurringDetectionSetting::forUser($userId);
        $from = Carbon::now()->subMonths(self::LOOKBACK_MONTHS);
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

        if ($transactions->count() < $settings->min_occurrences) {
            return 0;
        }

        $groups = $this->groupTransactions($transactions, $settings, $accountId);
        $created = 0;

        foreach ($groups as $payeeKey => $txs) {
            $txs = $txs->sortBy('booked_date')->values();
            if ($txs->count() < $settings->min_occurrences) {
                continue;
            }

            $result = $this->inferRecurringSeries($txs, $settings);
            if ($result === null) {
                continue;
            }

            $fingerprint = $this->buildFingerprint($userId, $accountId, $payeeKey, $result['interval'], $result['amount_min'], $result['amount_max']);

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

            $firstTx = $txs->first();
            $lastTx = $txs->last();
            if ($firstTx === null || $lastTx === null) {
                continue;
            }
            $name = $this->deriveName($firstTx);
            $firstDate = $firstTx->booked_date->toDateString();
            $lastDate = $lastTx->booked_date->toDateString();
            $scope = $accountId !== null ? RecurringGroup::SCOPE_PER_ACCOUNT : RecurringGroup::SCOPE_PER_USER;

            $group = RecurringGroup::updateOrCreate(
                [
                    'user_id' => $userId,
                    'dismissal_fingerprint' => $fingerprint,
                    'status' => RecurringGroup::STATUS_SUGGESTED,
                ],
                [
                    'name' => $name,
                    'interval' => $result['interval'],
                    'interval_days' => $result['interval_days'],
                    'amount_min' => $result['amount_min'],
                    'amount_max' => $result['amount_max'],
                    'scope' => $scope,
                    'account_id' => $accountId,
                    'merchant_id' => $firstTx->merchant_id,
                    'normalized_description' => $this->normalizeDescription((string) ($firstTx->description ?? '')),
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                    'detection_config_snapshot' => [
                        'transaction_ids' => $txs->pluck('id')->all(),
                        'scope' => $settings->scope,
                        'group_by' => $settings->group_by,
                        'amount_variance_type' => $settings->amount_variance_type,
                        'amount_variance_value' => (float) $settings->amount_variance_value,
                        'min_occurrences' => $settings->min_occurrences,
                    ],
                ]
            );

            if ($group->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Group transactions by payee key (and account when per-account).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, Collection<int, Transaction>>
     */
    private function groupTransactions(Collection $transactions, RecurringDetectionSetting $settings, ?int $accountId): array
    {
        $groups = [];

        foreach ($transactions as $tx) {
            /** @var Transaction $tx */
            $payeeKey = $this->getPayeeKey($tx, $settings->group_by);
            if ($payeeKey === '') {
                continue;
            }

            $scopeKey = ($accountId !== null ? 'a'.$accountId : 'all').'|'.$payeeKey;

            if (! isset($groups[$scopeKey])) {
                $groups[$scopeKey] = collect();
            }
            $groups[$scopeKey]->push($tx);
        }

        return $groups;
    }

    private function getPayeeKey(Transaction $tx, string $groupBy): string
    {
        if ($groupBy === RecurringDetectionSetting::GROUP_BY_MERCHANT_ONLY) {
            if ($tx->merchant_id !== null) {
                return 'm'.$tx->merchant_id;
            }

            return 'd:'.$this->normalizeDescriptionForPayee((string) ($tx->description ?? ''));
        }

        if ($tx->merchant_id !== null) {
            return 'm'.$tx->merchant_id;
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
            $s = preg_replace('/\s*'.preg_quote($word, '/').'\s*/iu', ' ', $s);
        }
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s === '' ? $this->normalizeDescription($description) : $s;
    }

    private function normalizeDescription(string $description): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($description));

        return strtolower($s ?? '');
    }

    /**
     * Infer if the sorted list of transactions forms a recurring series. Returns null if not.
     *
     * @param  Collection<int, Transaction>  $txs
     * @return array{interval: string, interval_days: int|null, amount_min: float, amount_max: float}|null
     */
    private function inferRecurringSeries(Collection $txs, RecurringDetectionSetting $settings): ?array
    {
        $dates = $txs->map(fn ($t) => $t->booked_date->startOfDay())->values()->all();
        $amounts = $txs->map(fn ($t) => (float) $t->amount)->values()->all();

        $deltas = [];
        for ($i = 1; $i < count($dates); $i++) {
            $deltas[] = (int) abs($dates[$i]->diffInDays($dates[$i - 1]));
        }

        if (count($deltas) === 0) {
            return null;
        }

        $medianDelta = $this->median($deltas);
        $interval = $this->classifyInterval($medianDelta);
        if ($interval === null) {
            return null;
        }

        [$minDays, $maxDays] = $this->intervalVariance($interval);
        foreach ($deltas as $d) {
            if ($d < $minDays || $d > $maxDays) {
                return null;
            }
        }

        $medianAmount = $this->median($amounts);
        $amountMin = $medianAmount;
        $amountMax = $medianAmount;

        if ($settings->amount_variance_type === RecurringDetectionSetting::AMOUNT_VARIANCE_PERCENT) {
            $pct = (float) $settings->amount_variance_value / 100;
            $low = $medianAmount * (1 - $pct);
            $high = $medianAmount * (1 + $pct);
            $amountMin = min($low, $high);
            $amountMax = max($low, $high);
        } else {
            $fixed = (float) $settings->amount_variance_value;
            $amountMin = $medianAmount - $fixed;
            $amountMax = $medianAmount + $fixed;
        }

        $amountMinR = round($amountMin, 2);
        $amountMaxR = round($amountMax, 2);
        foreach ($amounts as $a) {
            $ar = round((float) $a, 2);
            if ($ar < $amountMinR || $ar > $amountMaxR) {
                return null;
            }
        }

        $intervalDays = match ($interval) {
            RecurringGroup::INTERVAL_WEEKLY => 7,
            RecurringGroup::INTERVAL_MONTHLY => 30,
            RecurringGroup::INTERVAL_QUARTERLY => 90,
            RecurringGroup::INTERVAL_YEARLY => 365,
            default => null,
        };

        return [
            'interval' => $interval,
            'interval_days' => $intervalDays,
            'amount_min' => round($amountMin, 2),
            'amount_max' => round($amountMax, 2),
        ];
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

    private function classifyInterval(float|int $medianDays): ?string
    {
        $days = (int) round($medianDays);
        if ($days >= self::MIN_INTERVAL_DAYS_WEEKLY && $days <= self::MAX_INTERVAL_DAYS_WEEKLY) {
            return RecurringGroup::INTERVAL_WEEKLY;
        }
        if ($days >= self::MIN_INTERVAL_DAYS_MONTHLY && $days <= self::MAX_INTERVAL_DAYS_MONTHLY) {
            return RecurringGroup::INTERVAL_MONTHLY;
        }
        if ($days >= self::MIN_INTERVAL_DAYS_QUARTERLY && $days <= self::MAX_INTERVAL_DAYS_QUARTERLY) {
            return RecurringGroup::INTERVAL_QUARTERLY;
        }
        if ($days >= self::MIN_INTERVAL_DAYS_YEARLY && $days <= self::MAX_INTERVAL_DAYS_YEARLY) {
            return RecurringGroup::INTERVAL_YEARLY;
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function intervalVariance(string $interval): array
    {
        return match ($interval) {
            RecurringGroup::INTERVAL_WEEKLY => [self::MIN_INTERVAL_DAYS_WEEKLY, self::MAX_INTERVAL_DAYS_WEEKLY],
            RecurringGroup::INTERVAL_MONTHLY => [self::MIN_INTERVAL_DAYS_MONTHLY, self::MAX_INTERVAL_DAYS_MONTHLY],
            RecurringGroup::INTERVAL_QUARTERLY => [self::MIN_INTERVAL_DAYS_QUARTERLY, self::MAX_INTERVAL_DAYS_QUARTERLY],
            RecurringGroup::INTERVAL_YEARLY => [self::MIN_INTERVAL_DAYS_YEARLY, self::MAX_INTERVAL_DAYS_YEARLY],
            default => [0, 999],
        };
    }

    private function buildFingerprint(int $userId, ?int $accountId, string $payeeKey, string $interval, float $amountMin, float $amountMax): string
    {
        $payload = implode('|', [
            (string) $userId,
            $accountId !== null ? (string) $accountId : 'all',
            $payeeKey,
            $interval,
            number_format($amountMin, 2, '.', ''),
            number_format($amountMax, 2, '.', ''),
        ]);

        return hash('sha256', $payload);
    }

    private function isDismissed(int $userId, string $fingerprint): bool
    {
        return DismissedRecurringSuggestion::where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    private function deriveName(Transaction $tx): string
    {
        if ($tx->merchant_id !== null && $tx->relationLoaded('merchant') && $tx->merchant !== null) {
            /** @var \App\Models\Merchant $merchant */
            $merchant = $tx->merchant;

            return $merchant->name;
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
        $transactionIds = $snapshot['transaction_ids'] ?? [];

        $group->update(['status' => RecurringGroup::STATUS_CONFIRMED]);

        if (! empty($transactionIds)) {
            Transaction::whereIn('id', $transactionIds)
                ->update(['recurring_group_id' => $group->id]);

            if ($addRecurringTag) {
                $this->attachRecurringTagToTransactionIds($group->getUserId(), $transactionIds);
            }
        }
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
     * Remove recurring group link from transactions and optionally remove Recurring tag.
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
    }
}
