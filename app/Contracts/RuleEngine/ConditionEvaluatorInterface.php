<?php

namespace App\Contracts\RuleEngine;

use App\Models\RuleEngine\ConditionField;
use App\Models\RuleEngine\ConditionOperator;
use App\Models\RuleEngine\RuleCondition;
use App\Models\Transaction;

interface ConditionEvaluatorInterface
{
    /**
     * Evaluate a single condition against a transaction.
     */
    public function evaluate(RuleCondition $condition, Transaction $transaction): bool;

    /**
     * Evaluate a condition against a pre-extracted field value.
     * This method enables performance optimization by allowing field value caching.
     */
    public function evaluateWithValue(RuleCondition $condition, $fieldValue): bool;

    /**
     * Check if the evaluator supports a given operator.
     */
    public function supportsOperator(ConditionOperator $operator): bool;

    /**
     * Get the value from the transaction for the specified field.
     */
    public function getFieldValue(Transaction $transaction, ConditionField $field): mixed;
}
