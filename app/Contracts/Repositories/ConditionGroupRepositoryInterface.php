<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\ConditionGroup;
use Illuminate\Support\Collection;

interface ConditionGroupRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): ConditionGroup;

    public function update(int $id, array $data): ?ConditionGroup;

    public function findByRule(int $ruleId): Collection;
}
