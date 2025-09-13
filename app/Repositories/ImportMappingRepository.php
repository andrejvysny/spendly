<?php

namespace App\Repositories;

use App\Contracts\Repositories\ImportMappingRepositoryInterface;
use App\Models\Import\ImportMapping;
use Illuminate\Support\Collection;

class ImportMappingRepository extends BaseRepository implements ImportMappingRepositoryInterface
{
    public function __construct(ImportMapping $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): ImportMapping
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?ImportMapping
    {
        $mapping = $this->model->find($id);
        if (!$mapping) {
            return null;
        }

        $mapping->update($data);
        return $mapping->fresh();
    }

    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function findByUserAndName(int $userId, string $name): ?ImportMapping
    {
        return $this->model->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    public function findByUserAndBankProvider(int $userId, string $bankProvider): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('bank_provider', $bankProvider)
            ->get();
    }
}
