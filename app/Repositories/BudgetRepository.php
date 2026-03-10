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
        return $this->model->where('user_id', $userId)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, Budget>
     */
    public function findActiveByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('is_active', true)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, Budget>
     */
    public function findByUserAndPeriodType(int $userId, string $periodType): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('period_type', $periodType)
            ->where('is_active', true)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Budget
    {
        return $this->model->create($data);
    }
}
