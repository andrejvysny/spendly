<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Budget;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepositoryContract<Budget>
 */
interface BudgetRepositoryInterface extends BaseRepositoryContract
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
}
