<?php

namespace App\Services\RuleEngine;

use App\Contracts\RuleEngine\ActionExecutorInterface;
use App\Contracts\RuleEngine\ConditionEvaluatorInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Models\ConditionGroup;
use App\Models\Rule;
use App\Models\RuleExecutionLog;
use App\Models\RuleGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RuleEngine implements RuleEngineInterface
{
    private User $user;
    private ConditionEvaluatorInterface $conditionEvaluator;
    private ActionExecutorInterface $actionExecutor;
    private bool $logging = true;
    private bool $dryRun = false;
    private array $executionResults = [];

    public function __construct(
        ConditionEvaluatorInterface $conditionEvaluator,
        ActionExecutorInterface $actionExecutor
    ) {
        $this->conditionEvaluator = $conditionEvaluator;
        $this->actionExecutor = $actionExecutor;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function processTransaction(Transaction $transaction, string $triggerType): void
    {
        $this->processTransactions(collect([$transaction]), $triggerType);
    }

    public function processTransactions(Collection $transactions, string $triggerType): void
    {
        $rules = $this->getActiveRulesForTrigger($triggerType);
        
        foreach ($transactions as $transaction) {
            $this->processTransactionThroughRules($transaction, $rules);
        }
    }

    public function processTransactionsForRules(Collection $transactions, Collection $ruleIds): void
    {
        $rules = Rule::with(['conditionGroups.conditions', 'actions'])
            ->whereIn('id', $ruleIds)
            ->where('user_id', $this->user->id)
            ->where('is_active', true)
            ->get();

        foreach ($transactions as $transaction) {
            $this->processTransactionThroughRules($transaction, $rules);
        }
    }

    public function processDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate, ?array $ruleIds = null): void
    {
        $query = Transaction::where('user_id', $this->user->id)
            ->whereBetween('booked_date', [$startDate, $endDate]);

        if ($ruleIds !== null) {
            $rules = Rule::with(['conditionGroups.conditions', 'actions'])
                ->whereIn('id', $ruleIds)
                ->where('user_id', $this->user->id)
                ->where('is_active', true)
                ->get();
        } else {
            $rules = $this->getActiveRulesForTrigger(Rule::TRIGGER_MANUAL);
        }

        // Process in chunks to avoid memory issues
        $query->chunk(100, function ($transactions) use ($rules) {
            foreach ($transactions as $transaction) {
                $this->processTransactionThroughRules($transaction, $rules);
            }
        });
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

    private function getActiveRulesForTrigger(string $triggerType): Collection
    {
        return RuleGroup::with([
            'rules' => function ($query) use ($triggerType) {
                $query->where('is_active', true)
                    ->where('trigger_type', $triggerType)
                    ->orderBy('order')
                    ->with(['conditionGroups.conditions', 'actions']);
            }
        ])
        ->where('user_id', $this->user->id)
        ->where('is_active', true)
        ->orderBy('order')
        ->get()
        ->pluck('rules')
        ->flatten();
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
        $conditionGroups = $rule->conditionGroups()->with('conditions')->orderBy('order')->get();
        
        if ($conditionGroups->isEmpty()) {
            return false;
        }

        // Between condition groups, we use OR logic
        foreach ($conditionGroups as $group) {
            if ($this->evaluateConditionGroup($group, $transaction)) {
                $this->logExecution($rule, $transaction, true);
                return true;
            }
        }

        $this->logExecution($rule, $transaction, false);
        return false;
    }

    private function evaluateConditionGroup(ConditionGroup $group, Transaction $transaction): bool
    {
        $conditions = $group->orderedConditions;
        
        if ($conditions->isEmpty()) {
            return false;
        }

        $results = [];
        foreach ($conditions as $condition) {
            $result = $this->conditionEvaluator->evaluate($condition, $transaction);
            
            // Apply negation if needed
            if ($condition->is_negated) {
                $result = !$result;
            }
            
            $results[] = $result;
        }

        // Apply AND/OR logic
        if ($group->isAndLogic()) {
            return !in_array(false, $results, true);
        } else {
            return in_array(true, $results, true);
        }
    }

    private function executeRuleActions(Rule $rule, Transaction $transaction): void
    {
        $actions = $rule->actions()->orderBy('order')->get();
        $executedActions = [];

        DB::beginTransaction();
        try {
            foreach ($actions as $action) {
                if (!$this->dryRun) {
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

            if (!$this->dryRun) {
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

    private function logExecution(Rule $rule, Transaction $transaction, bool $matched): void
    {
        if (!$this->logging) {
            return;
        }

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'transaction_id' => $transaction->id,
            'matched' => $matched,
            'execution_context' => [
                'trigger_type' => $rule->trigger_type,
                'dry_run' => $this->dryRun,
            ],
        ]);
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

        if ($this->logging && !empty($actions)) {
            RuleExecutionLog::where('rule_id', $rule->id)
                ->where('transaction_id', $transaction->id)
                ->where('matched', true)
                ->latest()
                ->first()
                ?->update(['actions_executed' => $actions]);
        }
    }
} 