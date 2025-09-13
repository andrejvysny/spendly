<?php

namespace App\Repositories;

use App\Models\TransactionRule;
use App\Contracts\Repositories\TransactionRuleRepositoryInterface;
use Illuminate\Support\Collection;

class TransactionRuleRepository extends BaseRepository implements TransactionRuleRepositoryInterface
{
    public function __construct(TransactionRule $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): TransactionRule
    {
        return $this->model->create($data);
    }

    public function findByTransaction(int $transactionId): Collection
    {
        return $this->model->where('transaction_id', $transactionId)->get();
    }

    public function findByRule(int $ruleId): Collection
    {
        return $this->model->where('rule_id', $ruleId)->get();
    }
}
