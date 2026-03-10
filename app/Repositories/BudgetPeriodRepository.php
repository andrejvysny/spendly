<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BudgetPeriodRepositoryInterface;
use App\Models\BudgetPeriod;
use Illuminate\Support\Collection;

class BudgetPeriodRepository extends BaseRepository implements BudgetPeriodRepositoryInterface
{
    public function __construct(BudgetPeriod $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, BudgetPeriod>
     */
    public function findByBudget(int $budgetId): Collection
    {
        return $this->model
            ->where('budget_id', $budgetId)
            ->orderBy('start_date', 'desc')
            ->get();
    }

    public function findActiveForBudget(int $budgetId): ?BudgetPeriod
    {
        return $this->model
            ->where('budget_id', $budgetId)
            ->where('status', BudgetPeriod::STATUS_ACTIVE)
            ->orderBy('start_date', 'desc')
            ->first();
    }

    /**
     * @param  array<int>  $budgetIds
     * @return Collection<int, BudgetPeriod>
     */
    public function findForBudgetsAndDate(array $budgetIds, string $date): Collection
    {
        if ($budgetIds === []) {
            return collect();
        }

        return $this->model
            ->whereIn('budget_id', $budgetIds)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();
    }

    /**
     * @param  array<int>  $budgetIds
     * @return Collection<int, BudgetPeriod>
     */
    public function findForBudgetsInRange(array $budgetIds, string $startDate, string $endDate): Collection
    {
        if ($budgetIds === []) {
            return collect();
        }

        return $this->model
            ->whereIn('budget_id', $budgetIds)
            ->whereDate('start_date', '>=', $startDate)
            ->whereDate('end_date', '<=', $endDate)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BudgetPeriod
    {
        return $this->model->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePeriod(BudgetPeriod $period, array $data): BudgetPeriod
    {
        $period->update($data);

        /** @var BudgetPeriod */
        return $period->fresh();
    }

    public function deletePeriod(BudgetPeriod $period): bool
    {
        $result = $period->delete();

        return $result === true;
    }
}
