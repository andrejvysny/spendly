<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\BudgetPeriodRepositoryInterface;
use App\Contracts\Repositories\BudgetRepositoryInterface;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function __construct(
        private readonly BudgetRepositoryInterface $budgetRepository,
        private readonly BudgetPeriodRepositoryInterface $budgetPeriodRepository,
        private readonly AccountRepositoryInterface $accountRepository
    ) {}

    /**
     * Get budgets with progress for a given month/year.
     * Auto-creates periods if none exist for the requested timeframe.
     *
     * @return Collection<int, array{budget: Budget, period: BudgetPeriod|null, spent: float, remaining: float, percentage_used: float, is_exceeded: bool}>
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

        return $budgets->map(function (Budget $budget) use ($periods) {
            /** @var BudgetPeriod|null $period */
            $period = $periods->get($budget->id);
            $effectiveAmount = $period ? $period->getEffectiveAmount() : (float) $budget->amount;
            $spent = $period ? $this->getSpentForPeriod($budget, $period) : 0.0;
            $remaining = max(0.0, $effectiveAmount - $spent);
            $percentageUsed = $effectiveAmount > 0 ? round(($spent / $effectiveAmount) * 100, 2) : 0.0;
            $isExceeded = $spent > $effectiveAmount;

            return [
                'budget' => $budget,
                'period' => $period,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
                'is_exceeded' => $isExceeded,
            ];
        });
    }

    public function getSpentForPeriod(Budget $budget, BudgetPeriod $period): float
    {
        $accountIds = $this->accountRepository->findByUser($budget->getUserId())
            ->pluck('id')
            ->toArray();

        if ($accountIds === []) {
            return 0.0;
        }

        $query = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('booked_date', '>=', $period->start_date)
            ->where('booked_date', '<=', $period->end_date)
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->where('currency', $budget->currency);

        if ($budget->category_id !== null) {
            $query->where('category_id', $budget->category_id);
        }

        $sum = (float) $query->sum(DB::raw('ABS(amount)'));

        return round($sum, 2);
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

        // Group recurring items by category (via their transactions)
        /** @var array<int|string, array{category_id: int|null, category_name: string, total: float, currency: string, count: int, sources: array<int, array{name: string, amount: float, interval: string}>}> $byCategory */
        $byCategory = [];

        foreach ($groups as $group) {
            // Get the category from the most recent transaction in this group
            /** @var \App\Models\Transaction|null $latestTx */
            $latestTx = \App\Models\Transaction::where('recurring_group_id', $group->id)
                ->whereNotNull('category_id')
                ->orderBy('booked_date', 'desc')
                ->first();

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

            $this->budgetPeriodRepository->create([
                'budget_id' => $budget->id,
                'start_date' => $viewStart->format('Y-m-d'),
                'end_date' => $viewEnd->format('Y-m-d'),
                'amount_budgeted' => $amountBudgeted,
                'rollover_amount' => 0,
                'status' => BudgetPeriod::STATUS_ACTIVE,
            ]);
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
            return Carbon::createStrict($year, $month, 1)->endOfMonth()->startOfDay();
        }

        return Carbon::createStrict($year, 12, 31)->startOfDay();
    }
}
