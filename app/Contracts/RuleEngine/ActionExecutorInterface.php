<?php

namespace App\Contracts\RuleEngine;

use App\Models\RuleEngine\ActionType;
use App\Models\RuleEngine\RuleAction;
use App\Models\Transaction;

interface ActionExecutorInterface
{
    /**
     * Execute an action on a transaction.
     *
     * @return bool True if the action was executed successfully
     */
    public function execute(RuleAction $action, Transaction $transaction): bool;

    /**
     * Check if the executor supports a given action type.
     */
    public function supportsAction(ActionType $actionType): bool;

    /**
     * Validate that the action value is valid for the action type.
     */
    public function validateActionValue(ActionType $actionType, mixed $value): bool;

    /**
     * Get a description of what the action will do.
     */
    public function getActionDescription(RuleAction $action): string;
}
