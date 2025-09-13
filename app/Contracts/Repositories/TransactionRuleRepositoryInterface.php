<?php

namespace App\Contracts\Repositories;

use App\Models\TransactionRule;
use Illuminate\Support\Collection;

interface TransactionRuleRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): TransactionRule;
    public function findByTransaction(int $transactionId): Collection;
    public function findByRule(int $ruleId): Collection;
}
