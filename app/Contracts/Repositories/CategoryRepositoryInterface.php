<?php

namespace App\Contracts\Repositories;

use App\Models\Category;
use Illuminate\Support\Collection;

interface CategoryRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): Category;
    public function update(int $id, array $data): ?Category;
    public function findByUserId(int $userId): Collection;
    public function findByUserAndName(int $userId, string $name): ?Category;
    public function getChildCategories(int $parentId): Collection;
    public function getRootCategories(int $userId): Collection;
    public function firstOrCreate(array $attributes, array $values = []): Category;
}
