<?php

namespace App\Repositories;

use App\Contracts\Repositories\RuleConditionRepositoryInterface;
use App\Models\RuleEngine\RuleCondition;
use Illuminate\Support\Collection;

class RuleConditionRepository extends BaseRepository implements RuleConditionRepositoryInterface
{
    public function __construct(RuleCondition $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): RuleCondition
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?RuleCondition
    {
        $condition = $this->model->find($id);
        if (!$condition) {
            return null;
        }

        $condition->update($data);
        return $condition->fresh();
    }

    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }

    public function deleteByRule(int $ruleId): int
    {
        return $this->model->where('rule_id', $ruleId)->delete();
    }
}
