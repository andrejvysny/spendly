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
    public function findForUserAndPeriod(int $userId, string $periodType, int $year, ?int $month): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Budget;
}
