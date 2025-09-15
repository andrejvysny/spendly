<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleAction;
use Illuminate\Support\Collection;

interface RuleActionRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RuleAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?RuleAction;

    /**
     * @return Collection<int, RuleAction>
     */
    public function findByRule(int $ruleId): Collection;

    public function deleteByRule(int $ruleId): int;
}
