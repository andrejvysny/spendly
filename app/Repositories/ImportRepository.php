<?php

namespace App\Repositories;

use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Models\Import\Import;
use Illuminate\Support\Collection;

class ImportRepository extends BaseRepository implements ImportRepositoryInterface
{
    public function __construct(Import $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): Import
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Import
    {
        $import = $this->model->find($id);
        if (! $import) {
            return null;
        }

        $import->update($data);

        return $import->fresh();
    }

    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findByUserWithPagination(int $userId, int $perPage = 15): object
    {
        return $this->model->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function findByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    public function findByUserAndStatus(int $userId, string $status): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('status', $status)
            ->get();
    }

    public function incrementProcessedRows(int $id, int $count = 1): bool
    {
        return $this->model->where('id', $id)->increment('processed_rows', $count) > 0;
    }

    public function incrementFailedRows(int $id, int $count = 1): bool
    {
        return $this->model->where('id', $id)->increment('failed_rows', $count) > 0;
    }

    public function incrementSkippedRows(int $id, int $count = 1): bool
    {
        return $this->model->where('id', $id)->increment('skipped_rows', $count) > 0;
    }
}
