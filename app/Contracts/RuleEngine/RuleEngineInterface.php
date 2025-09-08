<?php

namespace App\Contracts\RuleEngine;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

interface RuleEngineInterface
{
    /**
     * Set the user for the rule engine.
     */
    public function setUser(User $user): self;

    /**
     * Process a single transaction through all applicable rules.
     */
    public function processTransaction(Transaction $transaction, string $triggerType): void;

    /**
     * Process multiple transactions through all applicable rules.
     */
    public function processTransactions(Collection $transactions, string $triggerType): void;

    /**
     * Process transactions for specific rules only.
     */
    public function processTransactionsForRules(Collection $transactions, Collection $ruleIds): void;

    /**
     * Process all transactions within a date range.
     */
    public function processDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate, ?array $ruleIds = null): void;

    /**
     * Set whether to log execution details.
     */
    public function setLogging(bool $enabled): self;

    /**
     * Set whether to actually execute actions (false for dry run).
     */
    public function setDryRun(bool $dryRun): self;

    /**
     * Get the execution results from the last run.
     */
    public function getExecutionResults(): array;

    /**
     * Clear execution results.
     */
    public function clearExecutionResults(): self;
}
