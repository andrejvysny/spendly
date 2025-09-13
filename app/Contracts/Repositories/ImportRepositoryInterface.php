<?php

namespace App\Contracts\Repositories;

use App\Models\Import\Import;
use Illuminate\Support\Collection;

interface ImportRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): Import;
    public function update(int $id, array $data): ?Import;
    public function findByUser(int $userId): Collection;
    public function findByUserWithPagination(int $userId, int $perPage = 15): object;
    public function findByStatus(string $status): Collection;
    public function findByUserAndStatus(int $userId, string $status): Collection;
    public function incrementProcessedRows(int $id, int $count = 1): bool;
    public function incrementFailedRows(int $id, int $count = 1): bool;
    public function incrementSkippedRows(int $id, int $count = 1): bool;
}
