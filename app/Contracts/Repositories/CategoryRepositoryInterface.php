<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * @extends NamedRepositoryInterface<Category>
 */
interface CategoryRepositoryInterface extends NamedRepositoryInterface
{
    /**
     * @return Collection<int, Category>
     */
    public function getChildCategories(int $parentId): Collection;

    /**
     * @return Collection<int, Category>
     */
    public function getRootCategories(int $userId): Collection;
}
