<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use Illuminate\Support\Collection;

trait RuleScoped
{
    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }

    public function deleteByRule(int $ruleId): int
    {
        return $this->model->where('rule_id', $ruleId)->delete();
    }
}
