<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends BaseRepositoryContract<TModel>
 */
interface RuleScopedRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @return Collection<int, TModel>
     */
    public function findByRule(int $ruleId): Collection;

    public function deleteByRule(int $ruleId): int;
}
