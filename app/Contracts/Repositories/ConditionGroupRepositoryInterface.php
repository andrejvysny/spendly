<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\ConditionGroup;
use Illuminate\Support\Collection;

interface ConditionGroupRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ConditionGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?ConditionGroup;

    /**
     * @return Collection<int, ConditionGroup>
     */
    public function findByRule(int $ruleId): Collection;
}
