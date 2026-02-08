<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BudgetRepositoryInterface;
use App\Models\Budget;
use App\Repositories\Concerns\UserScoped;
use Illuminate\Support\Collection;

class BudgetRepository extends BaseRepository implements BudgetRepositoryInterface
{
    use UserScoped;

    public function __construct(Budget $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, Budget>
     */
    public function findByUser(int $userId): Collection
    {
        $collection = $this->model->where('user_id', $userId)
            ->with('category')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        return $collection;
    }

    /**
     * @return Collection<int, Budget>
     */
    public function findForUserAndPeriod(int $userId, string $periodType, int $year, ?int $month): Collection
    {
        $query = $this->model->where('user_id', $userId)
            ->where('period_type', $periodType)
            ->where('year', $year)
            ->with('category');

        if ($periodType === Budget::PERIOD_MONTHLY) {
            $query->where('month', $month ?? 0);
        } else {
            $query->where('month', 0);
        }

        return $query->orderBy('month')->orderBy('category_id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Budget
    {
        $budget = $this->model->create($data);

        return $budget;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Budget $budget, array $data): Budget
    {
        $budget->update($data);

        return $budget->fresh();
    }

    public function delete(Budget $budget): bool
    {
        return parent::delete($budget);
    }
}
