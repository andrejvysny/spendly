<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleAction;
use Illuminate\Support\Collection;

interface RuleActionRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): RuleAction;
    public function update(int $id, array $data): ?RuleAction;
    public function findByRule(int $ruleId): Collection;
    public function deleteByRule(int $ruleId): int;
}
