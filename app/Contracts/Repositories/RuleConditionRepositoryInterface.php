<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleCondition;
use Illuminate\Support\Collection;

interface RuleConditionRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RuleCondition;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?RuleCondition;

    /**
     * @return Collection<int, RuleCondition>
     */
    public function findByRule(int $ruleId): Collection;

    public function deleteByRule(int $ruleId): int;
}
