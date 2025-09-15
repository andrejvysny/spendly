<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleExecutionLog;
use Illuminate\Support\Collection;

interface RuleExecutionLogRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RuleExecutionLog;

    /**
     * @return Collection<int, RuleExecutionLog>
     */
    public function findByRule(int $ruleId): Collection;

    /**
     * @return Collection<int, RuleExecutionLog>
     */
    public function findByTransaction(int $transactionId): Collection;
}
