<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Models\Category;
use App\Repositories\Concerns\UserScoped;
use Illuminate\Support\Collection;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    use UserScoped;

    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, Category>
     */
    public function getChildCategories(int $parentId): Collection
    {
        return $this->model->where('parent_category_id', $parentId)->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function getRootCategories(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->whereNull('parent_category_id')
            ->get();
    }

    /**
     * Get all descendant category IDs (children + grandchildren). Max 2 levels.
     *
     * @return array<int>
     */
    public function getAllDescendantIds(int $categoryId): array
    {
        /** @var array<int> $childIds */
        $childIds = $this->model->where('parent_category_id', $categoryId)
            ->pluck('id')
            ->toArray();

        if ($childIds === []) {
            return [];
        }

        /** @var array<int> $grandchildIds */
        $grandchildIds = $this->model->whereIn('parent_category_id', $childIds)
            ->pluck('id')
            ->toArray();

        return array_merge($childIds, $grandchildIds);
    }
}
