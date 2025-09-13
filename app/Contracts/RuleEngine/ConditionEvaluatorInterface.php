<?php

namespace App\Contracts\RuleEngine;

use App\Models\RuleEngine\RuleCondition;
use App\Models\Transaction;

interface ConditionEvaluatorInterface
{
    /**
     * Evaluate a single condition against a transaction.
     */
    public function evaluate(RuleCondition $condition, Transaction $transaction): bool;

    /**
     * Check if the evaluator supports a given operator.
     */
    public function supportsOperator(string $operator): bool;

    /**
     * Get the value from the transaction for the specified field.
     */
    public function getFieldValue(Transaction $transaction, string $field): mixed;
}
