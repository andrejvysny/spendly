<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleCondition;
use Illuminate\Support\Collection;

interface RuleConditionRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): RuleCondition;
    public function update(int $id, array $data): ?RuleCondition;
    public function findByRule(int $ruleId): Collection;
    public function deleteByRule(int $ruleId): int;
}
