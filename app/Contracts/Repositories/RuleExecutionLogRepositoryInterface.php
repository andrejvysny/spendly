<?php

namespace App\Contracts\Repositories;

use App\Models\RuleExecutionLog;
use Illuminate\Support\Collection;

interface RuleExecutionLogRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): RuleExecutionLog;
    public function findByRule(int $ruleId): Collection;
    public function findByTransaction(int $transactionId): Collection;
}
