<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Import\Import;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends UserScopedRepositoryInterface<Import>
 */
interface ImportRepositoryInterface extends UserScopedRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<Import>
     */
    public function findByUserWithPagination(int $userId, int $perPage = 15): LengthAwarePaginator;

    /**
     * @return Collection<int, Import>
     */
    public function findByStatus(string $status): Collection;

    /**
     * @return Collection<int, Import>
     */
    public function findByUserAndStatus(int $userId, string $status): Collection;

    public function incrementProcessedRows(int $id, int $count = 1): bool;

    public function incrementFailedRows(int $id, int $count = 1): bool;

    public function incrementSkippedRows(int $id, int $count = 1): bool;
}
