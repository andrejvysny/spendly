<?php

namespace App\Repositories;

use App\Contracts\Repositories\RuleActionRepositoryInterface;
use App\Models\RuleEngine\RuleAction;
use Illuminate\Support\Collection;

class RuleActionRepository extends BaseRepository implements RuleActionRepositoryInterface
{
    public function __construct(RuleAction $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): RuleAction
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?RuleAction
    {
        $action = $this->model->find($id);
        if (!$action) {
            return null;
        }

        $action->update($data);
        return $action->fresh();
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
