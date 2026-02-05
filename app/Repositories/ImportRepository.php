<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Models\Import\Import;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ImportRepository extends BaseRepository implements ImportRepositoryInterface
{
    public function __construct(Import $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Import
    {
        $model = parent::create($data);

        return $model instanceof Import ? $model : $this->model->find($model->getKey());
    }

    /**
     * @return Collection<int, Import>
     */
    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<Import>
     */
    public function findByUserWithPagination(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Import>
     */
    public function findByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    /**
     * @return Collection<int, Import>
     */
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
