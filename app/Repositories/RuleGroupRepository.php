<?php

namespace App\Repositories;

use App\Models\RuleEngine\RuleGroup;
use App\Contracts\Repositories\RuleGroupRepositoryInterface;
use Illuminate\Support\Collection;

class RuleGroupRepository extends BaseRepository implements RuleGroupRepositoryInterface
{
    public function __construct(RuleGroup $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): RuleGroup
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?RuleGroup
    {
        $group = $this->model->find($id);
        if (!$group) {
            return null;
        }

        $group->update($data);
        return $group->fresh();
    }

    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }
}
