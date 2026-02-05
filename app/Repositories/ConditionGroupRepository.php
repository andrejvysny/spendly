<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ConditionGroupRepositoryInterface;
use App\Models\RuleEngine\ConditionGroup;
use Illuminate\Support\Collection;

class ConditionGroupRepository extends BaseRepository implements ConditionGroupRepositoryInterface
{
    public function __construct(ConditionGroup $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, ConditionGroup>
     */
    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }
}
