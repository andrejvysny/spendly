<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
interface BaseRepositoryContract
{
    /**
     * Run a set of operations in a database transaction.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function transaction(callable $callback);

    public function delete(int $id): bool;

    /**
     * @return TModel|null
     */
    public function find(int $id): ?object;

    /**
     * @param  array<string>  $columns
     * @return Collection<int, TModel>
     */
    public function all(array $columns = ['*']): Collection;

    public function count(): int;

    public function exists(int $id): bool;

    public function forceDelete(int $id): bool;

    /**
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function create(array $data): object;

    /**
     * @param  array<string, mixed>  $data
     * @return TModel|null
     */
    public function update(int $id, array $data): ?object;
}
