<?php

namespace App\Repositories;

use App\Contracts\Repositories\BaseRepositoryContract;
use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class BaseRepository implements BaseRepositoryContract
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Run a set of operations in a database transaction.
     *
     * @template TReturn
     * @param callable():TReturn $callback
     * @return TReturn
     */
    public function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }

    public function delete(int|Model $id): bool
    {
        if ($id instanceof Model) {
            return $id->delete();
        }
        return $this->model->destroy($id) > 0;
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function all(array $columns = ['*']): array
    {
        return $this->model->all($columns)->toArray();
    }

    public function count(): int
    {
        return $this->model->count();
    }

    public function exists(int $id): bool
    {
        return $this->model->where('id', $id)->exists();
    }

    public function forceDelete(int $id): bool
    {
        $model = $this->model->withTrashed()->find($id);
        if ($model) {
            return $model->forceDelete();
        }
        return false;
    }
}
