<?php

namespace App\Repositories;

use App\Contracts\Repositories\RuleExecutionLogRepositoryInterface;
use App\Models\RuleEngine\RuleExecutionLog;
use Illuminate\Support\Collection;

class RuleExecutionLogRepository extends BaseRepository implements RuleExecutionLogRepositoryInterface
{
    public function __construct(RuleExecutionLog $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): RuleExecutionLog
    {
        return $this->model->create($data);
    }

    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }

    public function findByTransaction(int $transactionId): Collection
    {
        return $this->model->where('transaction_id', $transactionId)->get();
    }
}
