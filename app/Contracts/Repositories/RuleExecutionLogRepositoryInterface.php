<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleExecutionLog;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepositoryContract<RuleExecutionLog>
 */
interface RuleExecutionLogRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @return Collection<int, RuleExecutionLog>
     */
    public function findByRule(int $ruleId): Collection;

    /**
     * @return Collection<int, RuleExecutionLog>
     */
    public function findByTransaction(int $transactionId): Collection;
}
