<?php

namespace App\Repositories;

use App\Models\ConditionGroup;
use App\Contracts\Repositories\ConditionGroupRepositoryInterface;
use Illuminate\Support\Collection;

class ConditionGroupRepository extends BaseRepository implements ConditionGroupRepositoryInterface
{
    public function __construct(ConditionGroup $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): ConditionGroup
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?ConditionGroup
    {
        $group = $this->model->find($id);
        if (!$group) {
            return null;
        }

        $group->update($data);
        return $group->fresh();
    }

    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }
}
