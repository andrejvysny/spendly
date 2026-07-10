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
     * Get all descendant category IDs at any depth (breadth-first), guarding
     * against cycles. Excludes the category itself.
     *
     * @return array<int>
     */
    public function getAllDescendantIds(int $categoryId): array
    {
        $descendants = [];
        $visited = [$categoryId => true];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            /** @var array<int> $children */
            $children = $this->model->newQuery()
                ->whereIn('parent_category_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                $childId = (int) $childId;
                if (isset($visited[$childId])) {
                    continue; // already seen — cycle guard
                }
                $visited[$childId] = true;
                $descendants[] = $childId;
                $frontier[] = $childId;
            }
        }

        return $descendants;
    }
}
