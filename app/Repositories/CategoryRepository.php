<?php

namespace App\Repositories;

use App\Models\Category;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): Category
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Category
    {
        $category = $this->model->find($id);
        if (!$category) {
            return null;
        }

        $category->update($data);
        return $category->fresh();
    }

    public function findByUserId(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function findByUserAndName(int $userId, string $name): ?Category
    {
        return $this->model->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    public function getChildCategories(int $parentId): Collection
    {
        return $this->model->where('parent_id', $parentId)->get();
    }

    public function getRootCategories(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->whereNull('parent_id')
            ->get();
    }

    public function firstOrCreate(array $attributes, array $values = []): Category
    {
        return $this->model->firstOrCreate($attributes, $values);
    }
}
