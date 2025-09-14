<?php

namespace App\Contracts\Repositories;

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

    public function find(int $id): ?object;

    public function all(array $columns = ['*']): array;

    public function count(): int;

    public function exists(int $id): bool;

    public function forceDelete(int $id): bool;
}
