<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\BudgetPeriod;
use Illuminate\Support\Collection;

interface BudgetPeriodRepositoryInterface
{
    /**
     * @return Collection<int, BudgetPeriod>
     */
    public function findByBudget(int $budgetId): Collection;

    public function findActiveForBudget(int $budgetId): ?BudgetPeriod;

    /**
     * @param  array<int>  $budgetIds
     * @return Collection<int, BudgetPeriod>
     */
    public function findForBudgetsAndDate(array $budgetIds, string $date): Collection;

    /**
     * @param  array<int>  $budgetIds
     * @return Collection<int, BudgetPeriod>
     */
    public function findForBudgetsInRange(array $budgetIds, string $startDate, string $endDate): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BudgetPeriod;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePeriod(BudgetPeriod $period, array $data): BudgetPeriod;

    public function deletePeriod(BudgetPeriod $period): bool;
}
