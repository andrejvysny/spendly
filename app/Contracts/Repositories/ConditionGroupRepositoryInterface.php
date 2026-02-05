<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\ConditionGroup;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepositoryContract<ConditionGroup>
 */
interface ConditionGroupRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @return Collection<int, ConditionGroup>
     */
    public function findByRule(int $ruleId): Collection;
}
