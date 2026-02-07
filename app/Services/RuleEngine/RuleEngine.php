<?php

namespace App\Services\RuleEngine;

use App\Contracts\RuleEngine\ActionExecutorInterface;
use App\Contracts\RuleEngine\ConditionEvaluatorInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Models\RuleEngine\ConditionField;
use App\Models\RuleEngine\ConditionGroup;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleAction;
use App\Models\RuleEngine\RuleCondition;
use App\Models\RuleEngine\RuleExecutionLog;
use App\Models\RuleEngine\RuleGroup;
use App\Models\RuleEngine\Trigger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RuleEngine implements RuleEngineInterface
{
    private User $user;

    private bool $logging = true;

    private bool $dryRun = false;

    private array $executionResults = [];

    // Caching infrastructure for performance optimization
    private ?Collection $cachedRules = null;

    private array $cachedConditionGroups = [];

    private array $cachedActions = [];

    private array $transactionFieldCache = [];

    private array $pendingLogs = [];

    private string $currentTriggerType = '';

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    public function __construct(
        private readonly ConditionEvaluatorInterface $conditionEvaluator,
        private readonly ActionExecutorInterface $actionExecutor
    ) {}

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function processTransaction(Transaction $transaction, Trigger $triggerType): void
    {
        $this->processTransactions(collect([$transaction]), $triggerType);
    }

    public function processTransactions(Collection $transactions, Trigger $triggerType): void
    {
        $rules = $this->getActiveRulesForTrigger($triggerType);

        foreach ($transactions as $transaction) {
            $this->processTransactionThroughRules($transaction, $rules);
        }

        // Flush any pending logs after processing all transactions
        $this->flushPendingLogs();
    }

    public function processTransactionsForRules(Collection $transactions, Collection $ruleIds): void
    {
        $rules = Rule::with(['conditionGroups.conditions', 'actions'])
            ->whereIn('id', $ruleIds)
            ->where('user_id', $this->user->id)
            ->where('is_active', true)
            ->get();

        // Pre-cache the rule relationships
        $this->precacheRuleRelationships($rules);

        foreach ($transactions as $transaction) {
            $this->processTransactionThroughRules($transaction, $rules);
        }

        // Flush any pending logs after processing
        $this->flushPendingLogs();
    }

    public function processDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate, ?array $ruleIds = null): void
    {
        $query = Transaction::where('user_id', $this->user->id)
            ->whereBetween('booked_date', [$startDate, $endDate])
            ->with(['account', 'tags']); // Eager load relationships to avoid N+1

        if ($ruleIds !== null) {
            $rules = Rule::with(['conditionGroups.conditions', 'actions'])
                ->whereIn('id', $ruleIds)
                ->where('user_id', $this->user->id)
                ->where('is_active', true)
                ->orderBy('order')
                ->get();
        } else {
            $rules = $this->getActiveRulesForTrigger(Trigger::MANUAL);
        }

        // Pre-cache the rule relationships for better performance
        $this->precacheRuleRelationships($rules);

        $processed = 0;
        $chunkSize = 100;

        // Process in chunks to avoid memory issues
        $query->chunk($chunkSize, function ($transactions) use ($rules, &$processed, $chunkSize) {
            foreach ($transactions as $transaction) {
                $this->processTransactionThroughRules($transaction, $rules);
            }

            $processed += $transactions->count();

            // Flush logs periodically during chunked processing
            $this->flushPendingLogs();

            // Clear transaction field cache periodically to manage memory
            if (count($this->transactionFieldCache) > 1000) {
                $this->transactionFieldCache = [];
            }

            // Log progress for large operations
            if ($processed % ($chunkSize * 10) === 0) {
                Log::info("RuleEngine: Processed {$processed} transactions");
            }
        });

        // Final flush of any remaining logs
        $this->flushPendingLogs();

        Log::info('RuleEngine: Date range processing completed', [
            'total_processed' => $processed,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'cache_stats' => $this->getCacheStats(),
        ]);
    }

    public function setLogging(bool $enabled): self
    {
        $this->logging = $enabled;

        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function getExecutionResults(): array
    {
        return $this->executionResults;
    }

    public function clearExecutionResults(): self
    {
        $this->executionResults = [];

        return $this;
    }

    /**
     * Clear all caches to free memory or when rules change.
     */
    public function clearCaches(): self
    {
        $this->cachedRules = null;
        $this->cachedConditionGroups = [];
        $this->cachedActions = [];
        $this->transactionFieldCache = [];
        $this->pendingLogs = [];
        $this->currentTriggerType = '';

        // Also clear ActionExecutor caches if it supports clearing
        if (method_exists($this->actionExecutor, 'clearCaches')) {
            $this->actionExecutor->clearCaches();
        }

        // Also clear ConditionEvaluator caches if it supports clearing
        if (method_exists($this->conditionEvaluator, 'clearCache')) {
            $this->conditionEvaluator->clearCache();
        }

        return $this;
    }

    /**
     * Process a large number of transactions efficiently with batch optimization.
     */
    public function processBatch(Collection $transactionIds, Trigger $triggerType, int $batchSize = 50): void
    {
        $rules = $this->getActiveRulesForTrigger($triggerType);

        if ($rules->isEmpty()) {
            Log::info('No active rules found for trigger type: '.$triggerType->value);

            return;
        }

        $processed = 0;
        $total = $transactionIds->count();

        Log::info('Starting batch processing', [
            'total_transactions' => $total,
            'batch_size' => $batchSize,
            'trigger_type' => $triggerType,
            'active_rules' => $rules->count(),
        ]);

        // Process in batches
        $transactionIds->chunk($batchSize)->each(function ($chunk) use ($batchSize, $rules, &$processed, $total) {
            // Load transactions with relationships for this chunk
            $transactions = Transaction::with(['account', 'tags', 'category', 'merchant'])
                ->whereIn('id', $chunk->toArray())
                ->where('user_id', $this->user->id)
                ->get();

            foreach ($transactions as $transaction) {
                $this->processTransactionThroughRules($transaction, $rules);
                $processed++;
            }

            // Periodic cleanup and logging
            $this->flushPendingLogs();

            if (count($this->transactionFieldCache) > 500) {
                $this->transactionFieldCache = [];
            }

            if ($processed % ($batchSize * 5) === 0) {
                Log::info("Batch processing progress: {$processed}/{$total} transactions");
            }
        });

        $this->flushPendingLogs();

        Log::info('Batch processing completed', [
            'total_processed' => $processed,
            'cache_stats' => $this->getCacheStats(),
        ]);
    }

    /**
     * Get cache statistics for debugging.
     */
    public function getCacheStats(): array
    {
        $stats = [
            'rule_engine' => [
                'hits' => $this->cacheHits,
                'misses' => $this->cacheMisses,
                'hit_ratio' => $this->cacheMisses > 0 ? $this->cacheHits / ($this->cacheHits + $this->cacheMisses) : 1.0,
                'cached_rules' => $this->cachedRules?->count() ?? 0,
                'cached_condition_groups' => count($this->cachedConditionGroups),
                'cached_actions' => count($this->cachedActions),
                'cached_transaction_fields' => count($this->transactionFieldCache),
                'pending_logs' => count($this->pendingLogs),
            ],
        ];

        // Get ActionExecutor cache stats if available
        if (method_exists($this->actionExecutor, 'getCacheStats')) {
            $stats['action_executor'] = $this->actionExecutor->getCacheStats();
        }

        // Get ConditionEvaluator cache stats if available
        if (method_exists($this->conditionEvaluator, 'getCacheStats')) {
            $stats['condition_evaluator'] = $this->conditionEvaluator->getCacheStats();
        }

        return $stats;
    }

    /**
     * Flush pending logs to database.
     */
    public function flushPendingLogs(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        try {
            RuleExecutionLog::insert($this->pendingLogs);
            $this->pendingLogs = [];
        } catch (\Exception $e) {
            Log::error('Failed to flush pending logs', [
                'error' => $e->getMessage(),
                'pending_count' => count($this->pendingLogs),
            ]);
        }
    }

    private function getActiveRulesForTrigger(Trigger $triggerType): Collection
    {
        // Check if we have cached rules for this trigger type
        if ($this->cachedRules !== null && $this->currentTriggerType === $triggerType->value) {
            $this->cacheHits++;

            return $this->cachedRules;
        }

        $this->cacheMisses++;
        $this->currentTriggerType = $triggerType->value;

        // Load all rules with their relationships in a single optimized query
        $rules = RuleGroup::with([
            'rules' => function ($query) use ($triggerType) {
                $query->where('is_active', true)
                    ->where('trigger_type', $triggerType->value)
                    ->orderBy('order')
                    ->with(['conditionGroups.conditions', 'actions']);
            },
        ])
            ->where('user_id', $this->user->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->pluck('rules')
            ->flatten();

        // Cache the rules
        $this->cachedRules = $rules;

        // Pre-cache condition groups and actions for faster access
        $this->precacheRuleRelationships($rules);

        return $rules;
    }

    /**
     * Pre-cache condition groups and actions to avoid repeated queries.
     */
    private function precacheRuleRelationships(Collection $rules): void
    {
        foreach ($rules as $rule) {
            // Cache condition groups
            $conditionGroups = $rule->conditionGroups;
            $this->cachedConditionGroups[$rule->id] = $conditionGroups->keyBy('id');

            // Cache actions
            $actions = $rule->actions;
            $this->cachedActions[$rule->id] = $actions->sortBy('order');
        }
    }

    private function processTransactionThroughRules(Transaction $transaction, Collection $rules): void
    {
        foreach ($rules as $rule) {
            if ($this->evaluateRule($rule, $transaction)) {
                $this->executeRuleActions($rule, $transaction);

                if ($rule->stop_processing) {
                    break;
                }
            }
        }
    }

    private function evaluateRule(Rule $rule, Transaction $transaction): bool
    {
        // Use cached condition groups instead of querying database
        $conditionGroups = $this->getCachedConditionGroups($rule->id);

        if ($conditionGroups->isEmpty()) {
            return false;
        }

        // Between condition groups, we use OR logic
        foreach ($conditionGroups as $group) {
            if ($this->evaluateConditionGroup($group, $transaction)) {
                $this->queueExecutionLog($rule, $transaction, true);

                return true;
            }
        }

        $this->queueExecutionLog($rule, $transaction, false);

        return false;
    }

    /**
     * Get cached condition groups for a rule.
     */
    private function getCachedConditionGroups(int $ruleId): Collection
    {
        if (isset($this->cachedConditionGroups[$ruleId])) {
            $this->cacheHits++;

            return $this->cachedConditionGroups[$ruleId];
        }

        $this->cacheMisses++;

        // Fallback to database query if not cached (shouldn't happen in normal flow)
        $conditionGroups = ConditionGroup::with('conditions')
            ->where('rule_id', $ruleId)
            ->orderBy('order')
            ->get()
            ->keyBy('id');

        $this->cachedConditionGroups[$ruleId] = $conditionGroups;

        return $conditionGroups;
    }

    /**
     * Get cached actions for a rule.
     */
    private function getCachedActions(int $ruleId): Collection
    {
        if (isset($this->cachedActions[$ruleId])) {
            $this->cacheHits++;

            return $this->cachedActions[$ruleId];
        }

        $this->cacheMisses++;

        // Fallback to database query if not cached
        $actions = RuleAction::where('rule_id', $ruleId)
            ->orderBy('order')
            ->get();

        $this->cachedActions[$ruleId] = $actions;

        return $actions;
    }

    private function evaluateConditionGroup(ConditionGroup $group, Transaction $transaction): bool
    {
        $conditions = $group->orderedConditions;

        if ($conditions->isEmpty()) {
            return false;
        }

        $results = [];
        foreach ($conditions as $condition) {
            $result = $this->evaluateConditionWithCache($condition, $transaction);

            // Apply negation if needed
            if ($condition->is_negated) {
                $result = ! $result;
            }

            $results[] = $result;
        }

        // Apply AND/OR logic
        if ($group->isAndLogic()) {
            return ! in_array(false, $results, true);
        } else {
            return in_array(true, $results, true);
        }
    }

    /**
     * Evaluate condition with transaction field value caching.
     */
    private function evaluateConditionWithCache(RuleCondition $condition, Transaction $transaction): bool
    {
        $cacheKey = $transaction->id.'.'.$condition->field;

        // Check if field value is cached
        if (! isset($this->transactionFieldCache[$cacheKey])) {
            // Convert string field to ConditionField enum
            $fieldEnum = ConditionField::from($condition->field);
            $this->transactionFieldCache[$cacheKey] = $this->conditionEvaluator->getFieldValue($transaction, $fieldEnum);
            $this->cacheMisses++;
        } else {
            $this->cacheHits++;
        }

        $fieldValue = $this->transactionFieldCache[$cacheKey];

        // Use ConditionEvaluator to perform the actual evaluation
        return $this->conditionEvaluator->evaluateWithValue($condition, $fieldValue);
    }

    private function executeRuleActions(Rule $rule, Transaction $transaction): void
    {
        // Use cached actions instead of querying database
        $actions = $this->getCachedActions($rule->id);
        $executedActions = [];

        // Use single transaction for all actions of this rule
        DB::beginTransaction();
        try {
            foreach ($actions as $action) {
                if (! $this->dryRun) {
                    $success = $this->actionExecutor->execute($action, $transaction);

                    if ($success) {
                        $executedActions[] = [
                            'type' => $action->action_type,
                            'value' => $action->action_value,
                            'description' => $this->actionExecutor->getActionDescription($action),
                        ];
                    }

                    if ($action->stop_processing && $success) {
                        break;
                    }
                } else {
                    // In dry run mode, just collect what would be done
                    $executedActions[] = [
                        'type' => $action->action_type,
                        'value' => $action->action_value,
                        'description' => $this->actionExecutor->getActionDescription($action),
                        'dry_run' => true,
                    ];
                }
            }

            if (! $this->dryRun) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            $this->recordExecutionResult($rule, $transaction, true, $executedActions);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Rule action execution failed', [
                'rule_id' => $rule->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $this->recordExecutionResult($rule, $transaction, false, [], $e->getMessage());
        }
    }

    /**
     * Queue execution log for batch processing instead of immediate database write.
     */
    private function queueExecutionLog(Rule $rule, Transaction $transaction, bool $matched): void
    {
        if (! $this->logging) {
            return;
        }

        $this->pendingLogs[] = [
            'rule_id' => $rule->id,
            'transaction_id' => $transaction->id,
            'matched' => $matched,
            'execution_context' => json_encode([
                'trigger_type' => $rule->trigger_type,
                'dry_run' => $this->dryRun,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Flush logs in batches to avoid memory issues
        if (count($this->pendingLogs) >= 100) {
            $this->flushPendingLogs();
        }
    }

    /**
     * Legacy method for immediate logging (kept for backward compatibility).
     */
    private function logExecution(Rule $rule, Transaction $transaction, bool $matched): void
    {
        $this->queueExecutionLog($rule, $transaction, $matched);
    }

    private function recordExecutionResult(Rule $rule, Transaction $transaction, bool $success, array $actions, ?string $error = null): void
    {
        $result = [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'transaction_id' => $transaction->id,
            'success' => $success,
            'actions' => $actions,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($error) {
            $result['error'] = $error;
        }

        $this->executionResults[] = $result;

        // Update pending logs with action results if logging is enabled and actions were executed
        if ($this->logging && ! empty($actions)) {
            // Find the most recent pending log for this rule and transaction
            for ($i = count($this->pendingLogs) - 1; $i >= 0; $i--) {
                $log = &$this->pendingLogs[$i];
                if ($log['rule_id'] === $rule->id &&
                    $log['transaction_id'] === $transaction->id &&
                    ! isset($log['actions_executed'])) {
                    $log['actions_executed'] = json_encode($actions);
                    break;
                }
            }
        }
    }
}
