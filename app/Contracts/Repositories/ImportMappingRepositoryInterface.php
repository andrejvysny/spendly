<?php

namespace App\Contracts\Repositories;

use App\Models\Import\ImportMapping;
use Illuminate\Support\Collection;

interface ImportMappingRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): ImportMapping;

    public function update(int $id, array $data): ?ImportMapping;

    public function findByUser(int $userId): Collection;

    public function findByUserAndName(int $userId, string $name): ?ImportMapping;

    public function findByUserAndBankProvider(int $userId, string $bankProvider): Collection;
}
