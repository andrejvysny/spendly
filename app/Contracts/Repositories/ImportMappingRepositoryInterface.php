<?php

namespace App\Contracts\Repositories;

use App\Models\Import\ImportMapping;
use Illuminate\Support\Collection;

interface ImportMappingRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ImportMapping;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?ImportMapping;

    /**
     * @return Collection<int, ImportMapping>
     */
    public function findByUser(int $userId): Collection;

    public function findByUserAndName(int $userId, string $name): ?ImportMapping;

    /**
     * @return Collection<int, ImportMapping>
     */
    public function findByUserAndBankProvider(int $userId, string $bankProvider): Collection;
}
