<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends BaseRepositoryContract<TModel>
 */
interface UserScopedRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @return Collection<int, TModel>
     */
    public function findByUser(int $userId): Collection;
}
