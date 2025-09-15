<?php

namespace App\Contracts\Repositories;

use App\Models\Category;
use Illuminate\Support\Collection;

interface CategoryRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Category;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?Category;

    /**
     * @return Collection<int, Category>
     */
    public function findByUserId(int $userId): Collection;

    public function findByUserAndName(int $userId, string $name): ?Category;

    /**
     * @return Collection<int, Category>
     */
    public function getChildCategories(int $parentId): Collection;

    /**
     * @return Collection<int, Category>
     */
    public function getRootCategories(int $userId): Collection;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function firstOrCreate(array $attributes, array $values = []): Category;
}
