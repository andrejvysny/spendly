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
        return $this->model->where('parent_id', $parentId)->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function getRootCategories(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->whereNull('parent_id')
            ->get();
    }
}
