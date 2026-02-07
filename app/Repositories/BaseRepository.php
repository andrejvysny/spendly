<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BaseRepositoryContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
     *
     * @param  callable():TReturn  $callback
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

    /**
     * @param  array<string>  $columns
     * @return Collection<int, Model>
     */
    public function all(array $columns = ['*']): Collection
    {
        return $this->model->all($columns);
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

    /**
     * @param  array<string, mixed>  $data
     * @return Model
     */
    public function create(array $data): object
    {
        return $this->model->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Model|null
     */
    public function update(int $id, array $data): ?object
    {
        $model = $this->model->find($id);
        if (! $model) {
            return null;
        }

        $model->update($data);

        return $model->fresh();
    }
}
