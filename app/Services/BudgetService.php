<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\BudgetRepositoryInterface;
use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function __construct(
        private readonly BudgetRepositoryInterface $budgetRepository,
        private readonly AccountRepositoryInterface $accountRepository
    ) {}

    public function getSpentForBudget(Budget $budget): float
    {
        $accountIds = $this->accountRepository->findByUser($budget->getUserId())
            ->pluck('id')
            ->toArray();

        if ($accountIds === []) {
            return 0.0;
        }

        $startDate = $this->getPeriodStart($budget);
        $endDate = $this->getPeriodEnd($budget);

        $sum = (float) Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('category_id', $budget->category_id)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->where('currency', $budget->currency)
            ->sum(DB::raw('ABS(amount)'));

        return round($sum, 2);
    }

    /**
     * @return Collection<int, array{budget: Budget, spent: float, remaining: float, percentage_used: float, is_exceeded: bool}>
     */
    public function getBudgetsWithProgress(int $userId, string $periodType, int $year, ?int $month): Collection
    {
        $month = $periodType === Budget::PERIOD_MONTHLY ? ($month ?? (int) date('n')) : 0;
        $budgets = $this->budgetRepository->findForUserAndPeriod($userId, $periodType, $year, $month === 0 ? null : $month);

        return $budgets->map(function (Budget $budget) {
            $spent = $this->getSpentForBudget($budget);
            $amount = (float) $budget->amount;
            $remaining = max(0.0, $amount - $spent);
            $percentageUsed = $amount > 0 ? round(($spent / $amount) * 100, 2) : 0.0;
            $isExceeded = $spent > $amount;

            return [
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
                'is_exceeded' => $isExceeded,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $userId, array $data): Budget
    {
        $data['user_id'] = $userId;
        if (($data['period_type'] ?? '') === Budget::PERIOD_YEARLY) {
            $data['month'] = 0;
        }

        return $this->budgetRepository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Budget $budget, array $data): Budget
    {
        if (($data['period_type'] ?? $budget->period_type) === Budget::PERIOD_YEARLY) {
            $data['month'] = 0;
        }

        return $this->budgetRepository->update($budget, $data);
    }

    public function delete(Budget $budget): bool
    {
        return $this->budgetRepository->delete($budget);
    }

    private function getPeriodStart(Budget $budget): Carbon
    {
        if ($budget->period_type === Budget::PERIOD_MONTHLY && $budget->month >= 1) {
            return Carbon::create($budget->year, $budget->month, 1)->startOfDay();
        }

        return Carbon::create($budget->year, 1, 1)->startOfDay();
    }

    private function getPeriodEnd(Budget $budget): Carbon
    {
        if ($budget->period_type === Budget::PERIOD_MONTHLY && $budget->month >= 1) {
            return Carbon::create($budget->year, $budget->month, 1)->endOfMonth()->endOfDay();
        }

        return Carbon::create($budget->year, 12, 31)->endOfDay();
    }
}
