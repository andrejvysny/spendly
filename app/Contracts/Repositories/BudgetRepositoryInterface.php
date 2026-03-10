<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Budget;
use Illuminate\Support\Collection;

interface BudgetRepositoryInterface
{
    /**
     * @return Collection<int, Budget>
     */
    public function findByUser(int $userId): Collection;

    /**
     * @return Collection<int, Budget>
     */
    public function findActiveByUser(int $userId): Collection;

    /**
     * @return Collection<int, Budget>
     */
    public function findByUserAndPeriodType(int $userId, string $periodType): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Budget;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBudget(Budget $budget, array $data): Budget;

    public function deleteBudget(Budget $budget): bool;
}
