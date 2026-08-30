<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\BudgetPeriodRepositoryInterface;
use App\Contracts\Repositories\BudgetRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /** @var array<int, string> Cached user base currency keyed by user_id. */
    private array $baseCurrencyCache = [];

    public function __construct(
        private readonly BudgetRepositoryInterface $budgetRepository,
        private readonly BudgetPeriodRepositoryInterface $budgetPeriodRepository,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ExchangeRateService $exchangeRates
    ) {}

    /**
     * The user's base currency (the currency native_amount is denominated in).
     */
    private function baseCurrencyForUser(int $userId): string
    {
        if (! isset($this->baseCurrencyCache[$userId])) {
            $base = User::whereKey($userId)->value('base_currency');
            $this->baseCurrencyCache[$userId] = is_string($base) && $base !== '' ? $base : 'EUR';
        }

        return $this->baseCurrencyCache[$userId];
    }

    /**
     * Express a base-currency amount in the budget's currency.
     * No-op when the budget currency already equals the user's base currency.
     */
    private function toBudgetCurrency(float $amountInBase, Budget $budget, int $userId): float
    {
        $base = $this->baseCurrencyForUser($userId);
        if ($budget->currency === $base) {
            return round($amountInBase, 2);
        }

        return round($this->exchangeRates->convert($amountInBase, $base, $budget->currency, Carbon::now()), 2);
    }

    /**
     * Whether a budget targets a specific entity but the target id is missing
     * (e.g. the targeted category/tag/counterparty/subscription was deleted,
     * leaving a dangling nullOnDelete FK). Such budgets must NOT silently fall
     * back to "match everything".
     */
    private function isOrphanedTarget(Budget $budget): bool
    {
        return match ($budget->target_type) {
            Budget::TARGET_CATEGORY => $budget->category_id === null,
            Budget::TARGET_TAG => $budget->tag_id === null,
            Budget::TARGET_COUNTERPARTY => $budget->counterparty_id === null,
            Budget::TARGET_SUBSCRIPTION => $budget->recurring_group_id === null,
            Budget::TARGET_ACCOUNT => $budget->account_id === null,
            default => false,
        };
    }

    /**
     * Whether positive (inflow) transactions should net against spend for this budget.
     *
     * Refunds net only for entity-scoped targets, where a positive amount in the
     * same category/tag/counterparty/subscription is genuinely a refund. For
     * ACCOUNT and OVERALL budgets a positive amount is income (salary etc.), which
     * must not offset spend, so those count outflows gross.
     */
    private function budgetNetsRefunds(Budget $budget): bool
    {
        return in_array($budget->target_type, [
            Budget::TARGET_CATEGORY,
            Budget::TARGET_TAG,
            Budget::TARGET_COUNTERPARTY,
            Budget::TARGET_SUBSCRIPTION,
            Budget::TARGET_ALL_SUBSCRIPTIONS,
        ], true);
    }

    /**
     * Get budgets with progress for a given month/year.
     * Auto-creates periods if none exist for the requested timeframe.
     *
     * @return Collection<int, array{budget: Budget, period: BudgetPeriod|null, spent: float, remaining: float, percentage_used: float, is_exceeded: bool, is_orphaned: bool, pace_percentage: float, projected_total: float, days_elapsed: int, days_in_period: int}>
     */
    public function getBudgetsWithProgress(int $userId, string $periodType, int $year, ?int $month): Collection
    {
        $month = $periodType === Budget::PERIOD_MONTHLY ? ($month ?? (int) date('n')) : null;
        $budgets = $this->budgetRepository->findByUserAndPeriodType($userId, $periodType);

        if ($budgets->isEmpty()) {
            return collect();
        }

        // Compute date range for this view
        $viewStart = $this->computePeriodStart($periodType, $year, $month);
        $viewEnd = $this->computePeriodEnd($periodType, $year, $month);

        // Find existing periods for these budgets in this date range
        /** @var array<int> $budgetIds */
        $budgetIds = $budgets->pluck('id')->toArray();
        $periods = $this->budgetPeriodRepository->findForBudgetsInRange(
            $budgetIds,
            $viewStart->format('Y-m-d'),
            $viewEnd->format('Y-m-d')
        )->keyBy('budget_id');

        // Auto-create missing periods
        $this->autoCreateMissingPeriods($budgets, $periods, $viewStart, $viewEnd, $periodType, $year, $month);

        // Re-fetch periods after auto-create
        if ($periods->count() < $budgets->count()) {
            $periods = $this->budgetPeriodRepository->findForBudgetsInRange(
                $budgetIds,
                $viewStart->format('Y-m-d'),
                $viewEnd->format('Y-m-d')
            )->keyBy('budget_id');
        }

        $now = Carbon::now();

        // Pull user accounts once and reuse for every budget below to avoid
        // re-querying inside getSpentForPeriod for each budget (N+1).
        $userAccounts = $this->accountRepository->findByUser($userId);

        // Single-fetch spending for every budget in one transactions query +
        // in-memory aggregation (avoids N SUM queries per page render).
        $spentMap = $this->getSpentForBudgets($budgets, $periods, $userAccounts);

        return $budgets->map(function (Budget $budget) use ($periods, $now, $spentMap) {
            /** @var BudgetPeriod|null $period */
            $period = $periods->get($budget->id);
            $effectiveAmount = $period ? $period->getEffectiveAmount() : (float) $budget->amount;
            $isOrphaned = $this->isOrphanedTarget($budget);
            $spent = ($period && ! $isOrphaned) ? ($spentMap[$budget->id] ?? 0.0) : 0.0;
            $remaining = max(0.0, $effectiveAmount - $spent);
            $percentageUsed = $effectiveAmount > 0 ? round(($spent / $effectiveAmount) * 100, 2) : 0.0;
            $isExceeded = $spent > $effectiveAmount;

            // Pace calculation
            $daysElapsed = 0;
            $daysInPeriod = 1;
            $projectedTotal = 0.0;
            $pacePercentage = 0.0;

            if ($period !== null) {
                $periodStart = Carbon::parse($period->start_date)->startOfDay();
                $periodEnd = Carbon::parse($period->end_date)->endOfDay();
                $daysInPeriod = max(1, (int) $periodStart->diffInDays($periodEnd) + 1);

                if ($now->gte($periodStart) && $now->lte($periodEnd)) {
                    $daysElapsed = (int) $periodStart->diffInDays($now) + 1;
                } elseif ($now->gt($periodEnd)) {
                    $daysElapsed = $daysInPeriod;
                }

                if ($daysElapsed > 0) {
                    $projectedTotal = round(($spent / $daysElapsed) * $daysInPeriod, 2);
                    $expectedSpent = $effectiveAmount * ($daysElapsed / $daysInPeriod);
                    $pacePercentage = $expectedSpent > 0 ? round(($spent / $expectedSpent) * 100, 2) : 0.0;
                }
            }

            return [
                'budget' => $budget,
                'period' => $period,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
                'is_exceeded' => $isExceeded,
                'is_orphaned' => $isOrphaned,
                'pace_percentage' => $pacePercentage,
                'projected_total' => $projectedTotal,
                'days_elapsed' => $daysElapsed,
                'days_in_period' => $daysInPeriod,
            ];
        });
    }

    /**
     * @param  Collection<int, \App\Models\Account>|null  $accounts  Pre-fetched user accounts; if null, will be loaded.
     */
    public function getSpentForPeriod(Budget $budget, BudgetPeriod $period, ?Collection $accounts = null): float
    {
        // A budget whose target entity was deleted must not silently match everything.
        if ($this->isOrphanedTarget($budget)) {
            return 0.0;
        }

        $userId = $budget->getUserId();
        $accounts ??= $this->accountRepository->findByUser($userId);
        $accountIds = $accounts->pluck('id')->toArray();

        if ($accountIds === []) {
            return 0.0;
        }

        // Inclusive of the whole last day: end_date casts to midnight, so append a
        // time component, otherwise transactions booked after 00:00:00 on the last
        // day are silently dropped (and disagree with the in-memory aggregation).
        $query = Transaction::query()
            ->where('booked_date', '>=', $this->dateString($period->start_date))
            ->where('booked_date', '<=', $this->dateString($period->end_date).' 23:59:59');

        // Transfer exclusion: exclude unless an account budget opted in via include_transfers.
        $includeTransfers = $budget->target_type === Budget::TARGET_ACCOUNT && $budget->include_transfers;
        if (! $includeTransfers) {
            $query->excludingTransfers();
        }

        // Account scope
        if ($budget->target_type === Budget::TARGET_ACCOUNT && $budget->account_id !== null) {
            $query->where('account_id', $budget->account_id);
        } else {
            $query->whereIn('account_id', $accountIds);
        }

        // Target-type-specific filter
        $this->applyTargetFilter($query, $budget);

        // For gross targets (account/overall) only count outflows; scoped targets net refunds.
        if (! $this->budgetNetsRefunds($budget)) {
            $query->whereRaw('COALESCE(native_amount, amount) < 0');
        }

        // Net spend in the user's base currency (native_amount), then expressed in
        // the budget currency. COALESCE keeps single-currency rows correct when
        // native_amount has not been backfilled.
        $netSignedBase = (float) $query->sum(DB::raw('COALESCE(native_amount, amount)'));
        $spentBase = max(0.0, -$netSignedBase);

        return $this->toBudgetCurrency($spentBase, $budget, $userId);
    }

    /**
     * Compute spent amount for many budgets in a single transactions fetch.
     *
     * Loads every outgoing transaction across the spanning date range once,
     * then applies each budget's filter in PHP. Avoids one SUM query per
     * budget when rendering the budgets-with-progress view.
     *
     * @param  Collection<int, Budget>  $budgets
     * @param  Collection<int|string, BudgetPeriod>  $periodsByBudgetId  Keyed by budget_id.
     * @param  Collection<int, \App\Models\Account>  $accounts
     * @return array<int, float> budget_id => spent (2dp)
     */
    private function getSpentForBudgets(Collection $budgets, Collection $periodsByBudgetId, Collection $accounts): array
    {
        if ($budgets->isEmpty()) {
            return [];
        }

        /** @var array<int> $accountIds */
        $accountIds = $accounts->pluck('id')->all();
        if ($accountIds === []) {
            /** @var array<int, float> $empty */
            $empty = array_fill_keys($budgets->pluck('id')->all(), 0.0);

            return $empty;
        }

        // Only consider budgets that actually have a period.
        $relevant = $budgets->filter(fn (Budget $b) => $periodsByBudgetId->has($b->id));
        if ($relevant->isEmpty()) {
            return [];
        }

        // Spanning date range across all periods.
        $starts = [];
        $ends = [];
        foreach ($relevant as $budget) {
            /** @var BudgetPeriod $period */
            $period = $periodsByBudgetId->get($budget->id);
            $starts[] = $this->dateString($period->start_date);
            $ends[] = $this->dateString($period->end_date);
        }
        // @phpstan-ignore-next-line — guarded by $relevant->isEmpty() check above
        $minStart = min($starts);
        // @phpstan-ignore-next-line — same as above
        $maxEnd = max($ends);

        $needsTags = $relevant->contains(fn (Budget $b) => $b->target_type === Budget::TARGET_TAG);

        // Load all transactions in range (both signs: refunds net against spend for
        // scoped targets). Transfer/sign/target filtering happens per-budget below.
        $query = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('booked_date', '>=', $minStart)
            ->where('booked_date', '<=', $maxEnd.' 23:59:59');

        if ($needsTags) {
            $query->with('tags:id');
        }

        $allTransactions = $query->get();

        // Pre-compute descendant category id sets (one DB hit per unique parent id).
        /** @var array<int, array<int, true>> $descendantCache  parent_id => [id => true] */
        $descendantCache = [];
        foreach ($relevant as $budget) {
            if (
                $budget->target_type === Budget::TARGET_CATEGORY
                && $budget->include_subcategories
                && $budget->category_id !== null
                && ! isset($descendantCache[$budget->category_id])
            ) {
                $ids = $this->categoryRepository->getAllDescendantIds($budget->category_id);
                $ids[] = $budget->category_id;
                $descendantCache[$budget->category_id] = array_fill_keys($ids, true);
            }
        }

        $result = [];
        foreach ($budgets as $budget) {
            /** @var BudgetPeriod|null $period */
            $period = $periodsByBudgetId->get($budget->id);
            if ($period === null || $this->isOrphanedTarget($budget)) {
                $result[$budget->id] = 0.0;

                continue;
            }

            $start = $this->dateString($period->start_date);
            $end = $this->dateString($period->end_date);
            $includeTransfers = $budget->target_type === Budget::TARGET_ACCOUNT && $budget->include_transfers;
            $netsRefunds = $this->budgetNetsRefunds($budget);
            $accountScope = ($budget->target_type === Budget::TARGET_ACCOUNT && $budget->account_id !== null)
                ? (int) $budget->account_id
                : null;

            $sumSignedBase = 0.0;
            foreach ($allTransactions as $tx) {
                $txDate = $this->dateString($tx->booked_date);
                if ($txDate < $start || $txDate > $end) {
                    continue;
                }
                if (! $includeTransfers && $tx->isTransfer()) {
                    continue;
                }
                if ($accountScope !== null && (int) $tx->account_id !== $accountScope) {
                    continue;
                }

                $matches = match ($budget->target_type) {
                    Budget::TARGET_CATEGORY => $this->matchesCategoryTarget($tx, $budget, $descendantCache),
                    Budget::TARGET_TAG => $this->matchesTagTarget($tx, $budget),
                    Budget::TARGET_COUNTERPARTY => (int) $tx->counterparty_id === (int) $budget->counterparty_id && $tx->counterparty_id !== null,
                    Budget::TARGET_SUBSCRIPTION => (int) $tx->recurring_group_id === (int) $budget->recurring_group_id && $tx->recurring_group_id !== null,
                    Budget::TARGET_ACCOUNT => true,
                    Budget::TARGET_ALL_SUBSCRIPTIONS => $tx->recurring_group_id !== null,
                    Budget::TARGET_OVERALL => true,
                    default => false,
                };
                if (! $matches) {
                    continue;
                }

                // native_amount is already in the user's base currency; fall back to
                // amount only when it has not been backfilled (single-currency case).
                $valueBase = (float) ($tx->native_amount ?? $tx->amount);

                // Gross targets ignore inflows; scoped targets net refunds.
                if (! $netsRefunds && $valueBase > 0) {
                    continue;
                }

                $sumSignedBase += $valueBase;
            }

            $spentBase = max(0.0, -$sumSignedBase);
            $result[$budget->id] = $this->toBudgetCurrency($spentBase, $budget, $budget->getUserId());
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, true>>  $descendantCache
     */
    private function matchesCategoryTarget(Transaction $tx, Budget $budget, array $descendantCache): bool
    {
        // Orphaned category target (category deleted): match nothing rather than everything.
        if ($budget->category_id === null) {
            return false;
        }

        if ($budget->include_subcategories) {
            $set = $descendantCache[$budget->category_id] ?? [(int) $budget->category_id => true];

            return $tx->category_id !== null && isset($set[(int) $tx->category_id]);
        }

        return (int) $tx->category_id === (int) $budget->category_id;
    }

    private function matchesTagTarget(Transaction $tx, Budget $budget): bool
    {
        if ($budget->tag_id === null) {
            return false;
        }

        // tags relation is eager-loaded in getSpentForBudgets when any TAG budget exists.
        if ($tx->relationLoaded('tags')) {
            /** @var \App\Models\Tag $tag */
            foreach ($tx->tags as $tag) {
                if ((int) $tag->id === (int) $budget->tag_id) {
                    return true;
                }
            }

            return false;
        }

        return $tx->tags()->where('tags.id', $budget->tag_id)->exists();
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        return '';
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function applyTargetFilter(Builder $query, Budget $budget): void
    {
        match ($budget->target_type) {
            Budget::TARGET_CATEGORY => $this->applyCategoryFilter($query, $budget),
            Budget::TARGET_TAG => $query->whereHas('tags', fn (Builder $q) => $q->where('tags.id', $budget->tag_id)),
            Budget::TARGET_COUNTERPARTY => $query->where('counterparty_id', $budget->counterparty_id),
            Budget::TARGET_SUBSCRIPTION => $query->where('recurring_group_id', $budget->recurring_group_id),
            Budget::TARGET_ACCOUNT => null, // already scoped above
            Budget::TARGET_ALL_SUBSCRIPTIONS => $query->whereNotNull('recurring_group_id'),
            Budget::TARGET_OVERALL => null, // no additional filter
            default => null,
        };
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function applyCategoryFilter(Builder $query, Budget $budget): void
    {
        // Orphaned category target (category deleted): match nothing rather than everything.
        if ($budget->category_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($budget->include_subcategories) {
            $ids = $this->categoryRepository->getAllDescendantIds($budget->category_id);
            $ids[] = $budget->category_id;
            $query->whereIn('category_id', $ids);
        } else {
            $query->where('category_id', $budget->category_id);
        }
    }

    /**
     * Calculate rollover from a previous period.
     */
    public function calculateRollover(Budget $budget, BudgetPeriod $previousPeriod): float
    {
        $raw = $previousPeriod->getEffectiveAmount() - $this->getSpentForPeriod($budget, $previousPeriod);

        if ($budget->rollover_cap !== null && $raw < 0) {
            $raw = max(-((float) $budget->rollover_cap), $raw);
        }

        return round($raw, 2);
    }

    /**
     * Get budget history for trend chart.
     *
     * @return array<int, array{label: string, budgeted: float, spent: float, rollover: float}>
     */
    public function getBudgetHistory(int $userId, int $budgetId, int $months = 6): array
    {
        $budget = Budget::where('id', $budgetId)->where('user_id', $userId)->first();
        if ($budget === null) {
            return [];
        }

        $periods = BudgetPeriod::where('budget_id', $budgetId)
            ->orderBy('start_date', 'desc')
            ->limit($months)
            ->get()
            ->reverse()
            ->values();

        $result = [];
        foreach ($periods as $period) {
            $start = Carbon::parse($period->start_date);
            $label = $budget->period_type === Budget::PERIOD_YEARLY
                ? $start->format('Y')
                : $start->format('M Y');

            $result[] = [
                'label' => $label,
                'budgeted' => (float) $period->amount_budgeted,
                'spent' => $this->getSpentForPeriod($budget, $period),
                'rollover' => (float) $period->rollover_amount,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $userId, array $data): Budget
    {
        $data['user_id'] = $userId;

        $budget = $this->budgetRepository->create($data);

        // Auto-create initial period for current timeframe
        $now = Carbon::now();
        $startDate = $this->computePeriodStart(
            $budget->period_type,
            (int) $now->format('Y'),
            $budget->period_type === Budget::PERIOD_MONTHLY ? (int) $now->format('n') : null
        );
        $endDate = $this->computePeriodEnd(
            $budget->period_type,
            (int) $now->format('Y'),
            $budget->period_type === Budget::PERIOD_MONTHLY ? (int) $now->format('n') : null
        );

        $this->budgetPeriodRepository->create([
            'budget_id' => $budget->id,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'amount_budgeted' => $budget->amount,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        return $budget;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Budget $budget, array $data): Budget
    {
        if (($data['period_type'] ?? $budget->period_type) === Budget::PERIOD_YEARLY) {
            $data['month'] = 0;
        }

        $this->budgetRepository->update($budget->id, $data);

        /** @var Budget */
        return $budget->fresh();
    }

    public function delete(Budget $budget): bool
    {
        return $this->budgetRepository->delete($budget);
    }

    /**
     * Get suggested budget amounts from confirmed recurring groups.
     * Groups by category, computes monthly average from recurring intervals.
     *
     * @return array<int, array{category_id: int|null, category_name: string, suggested_amount: float, currency: string, recurring_count: int, sources: array<int, array{name: string, amount: float, interval: string}>}>
     */
    public function getSuggestedAmounts(int $userId): array
    {
        $groups = \App\Models\RecurringGroup::where('user_id', $userId)
            ->where('status', \App\Models\RecurringGroup::STATUS_CONFIRMED)
            ->withCount('transactions')
            ->withSum('transactions', 'amount')
            ->withMin('transactions', 'booked_date')
            ->withMax('transactions', 'booked_date')
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        // Pre-fetch all transactions for these recurring groups in a single query
        // to avoid N+1 when looking up the latest categorized transaction per group.
        /** @var array<int> $groupIds */
        $groupIds = $groups->pluck('id')->all();
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, \App\Models\Transaction>> $transactionsByGroup */
        $transactionsByGroup = Transaction::query()
            ->whereIn('recurring_group_id', $groupIds)
            ->whereNotNull('category_id')
            ->with('category')
            ->orderBy('booked_date', 'desc')
            ->get()
            ->groupBy('recurring_group_id');

        // Group recurring items by category (via their transactions)
        /** @var array<int|string, array{category_id: int|null, category_name: string, total: float, currency: string, count: int, sources: array<int, array{name: string, amount: float, interval: string}>}> $byCategory */
        $byCategory = [];

        foreach ($groups as $group) {
            // Get the category from the most recent categorized transaction in this group
            /** @var \Illuminate\Support\Collection<int, \App\Models\Transaction> $groupTxs */
            $groupTxs = $transactionsByGroup->get($group->id, collect());
            /** @var \App\Models\Transaction|null $latestTx */
            $latestTx = $groupTxs->first();

            $categoryId = $latestTx !== null ? $latestTx->category_id : null;
            /** @var \App\Models\Category|null $txCategory */
            $txCategory = $latestTx?->category;
            $categoryName = $txCategory !== null ? $txCategory->name : 'Uncategorized';
            $currency = $latestTx !== null ? $latestTx->currency : 'EUR';
            $key = $categoryId ?? 'none';

            $stats = $group->stats;
            $avgAmount = $stats['average_amount'] ?? null;
            if ($avgAmount === null) {
                continue;
            }

            // Convert to monthly amount
            $monthlyAmount = $this->toMonthlyAmount(abs($avgAmount), $group->interval ?? 'monthly');

            if (! isset($byCategory[$key])) {
                $byCategory[$key] = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'total' => 0.0,
                    'currency' => $currency,
                    'count' => 0,
                    'sources' => [],
                ];
            }

            $byCategory[$key]['total'] += $monthlyAmount;
            $byCategory[$key]['count']++;
            $byCategory[$key]['sources'][] = [
                'name' => $group->name ?? 'Unknown',
                'amount' => round($monthlyAmount, 2),
                'interval' => $group->interval ?? 'monthly',
            ];
        }

        $result = [];
        foreach ($byCategory as $data) {
            $result[] = [
                'category_id' => $data['category_id'],
                'category_name' => $data['category_name'],
                'suggested_amount' => round($data['total'] * 1.1, 2), // 10% buffer
                'currency' => $data['currency'],
                'recurring_count' => $data['count'],
                'sources' => $data['sources'],
            ];
        }

        // Sort by suggested_amount desc
        usort($result, fn (array $a, array $b) => $b['suggested_amount'] <=> $a['suggested_amount']);

        return $result;
    }

    /**
     * Get subscription budget suggestions: individual recurring groups + aggregate total.
     *
     * @return array{individual: array<int, array{recurring_group_id: int, name: string, suggested_amount: float, currency: string, interval: string}>, aggregate: array{suggested_amount: float, currency: string, count: int}}
     */
    public function getSubscriptionSuggestions(int $userId): array
    {
        $groups = \App\Models\RecurringGroup::where('user_id', $userId)
            ->where('status', \App\Models\RecurringGroup::STATUS_CONFIRMED)
            ->withCount('transactions')
            ->withSum('transactions', 'amount')
            ->get();

        $individual = [];
        $aggregateTotal = 0.0;
        $currency = 'EUR';

        foreach ($groups as $group) {
            $stats = $group->stats;
            $avgAmount = $stats['average_amount'] ?? null;
            if ($avgAmount === null) {
                continue;
            }

            $monthlyAmount = $this->toMonthlyAmount(abs($avgAmount), $group->interval ?? 'monthly');
            $suggested = round($monthlyAmount * 1.1, 2);

            $latestTx = Transaction::where('recurring_group_id', $group->id)->orderBy('booked_date', 'desc')->first();
            if ($latestTx !== null) {
                $currency = $latestTx->currency;
            }

            $individual[] = [
                'recurring_group_id' => $group->id,
                'name' => $group->name ?? 'Unknown',
                'suggested_amount' => $suggested,
                'currency' => $currency,
                'interval' => $group->interval ?? 'monthly',
            ];

            $aggregateTotal += $suggested;
        }

        return [
            'individual' => $individual,
            'aggregate' => [
                'suggested_amount' => round($aggregateTotal, 2),
                'currency' => $currency,
                'count' => count($individual),
            ],
        ];
    }

    private function toMonthlyAmount(float $amount, string $interval): float
    {
        return match ($interval) {
            'weekly' => $amount * (52 / 12),
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount, // monthly
        };
    }

    /**
     * @param  Collection<int, Budget>  $budgets
     * @param  Collection<int, BudgetPeriod>  $existingPeriods
     */
    private function autoCreateMissingPeriods(
        Collection $budgets,
        Collection $existingPeriods,
        Carbon $viewStart,
        Carbon $viewEnd,
        string $periodType,
        int $year,
        ?int $month
    ): void {
        $budgetsWithoutPeriods = $budgets->filter(
            fn (Budget $b) => ! $existingPeriods->has($b->id) && $b->auto_create_next
        );

        if ($budgetsWithoutPeriods->isEmpty()) {
            return;
        }

        // Try to find a previous period to copy amount from
        foreach ($budgetsWithoutPeriods as $budget) {
            $previousPeriod = $this->findPreviousPeriod($budget, $viewStart);
            $amountBudgeted = $previousPeriod
                ? (float) $previousPeriod->amount_budgeted
                : (float) $budget->amount;

            // Calculate rollover if enabled
            $rolloverAmount = 0.0;
            if ($budget->rollover_enabled && $previousPeriod !== null) {
                $rolloverAmount = $this->calculateRollover($budget, $previousPeriod);

                // Close previous period
                if ($previousPeriod->status === BudgetPeriod::STATUS_ACTIVE) {
                    $previousPeriod->update([
                        'status' => BudgetPeriod::STATUS_CLOSED,
                        'closed_at' => now(),
                    ]);
                }
            } elseif ($previousPeriod !== null && $previousPeriod->status === BudgetPeriod::STATUS_ACTIVE) {
                // Close previous period even without rollover
                $previousPeriod->update([
                    'status' => BudgetPeriod::STATUS_CLOSED,
                    'closed_at' => now(),
                ]);
            }

            try {
                $this->budgetPeriodRepository->create([
                    'budget_id' => $budget->id,
                    'start_date' => $viewStart->format('Y-m-d'),
                    'end_date' => $viewEnd->format('Y-m-d'),
                    'amount_budgeted' => $amountBudgeted,
                    'rollover_amount' => $rolloverAmount,
                    'status' => BudgetPeriod::STATUS_ACTIVE,
                ]);
            } catch (QueryException $e) {
                // A concurrent request may have created this period first; the unique
                // (budget_id, start_date) constraint guards against duplicates. Ignore
                // that specific race (caller re-fetches periods) but rethrow anything else.
                if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                    throw $e;
                }
            }
        }
    }

    private function findPreviousPeriod(Budget $budget, Carbon $currentStart): ?BudgetPeriod
    {
        return BudgetPeriod::where('budget_id', $budget->id)
            ->whereDate('start_date', '<', $currentStart->format('Y-m-d'))
            ->orderBy('start_date', 'desc')
            ->first();
    }

    private function computePeriodStart(string $periodType, int $year, ?int $month): Carbon
    {
        if ($periodType === Budget::PERIOD_MONTHLY && $month !== null && $month >= 1) {
            return Carbon::createStrict($year, $month, 1)->startOfDay();
        }

        return Carbon::createStrict($year, 1, 1)->startOfDay();
    }

    private function computePeriodEnd(string $periodType, int $year, ?int $month): Carbon
    {
        if ($periodType === Budget::PERIOD_MONTHLY && $month !== null && $month >= 1) {
            return Carbon::createStrict($year, $month, 1)->endOfMonth()->endOfDay();
        }

        return Carbon::createStrict($year, 12, 31)->endOfDay();
    }
}
