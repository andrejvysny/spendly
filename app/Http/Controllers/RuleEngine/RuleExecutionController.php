<?php

namespace App\Http\Controllers\RuleEngine;

use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Http\Controllers\Controller;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleGroup;
use App\Models\RuleEngine\Trigger;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RuleExecutionController extends Controller
{
    protected RuleEngineInterface $ruleEngine;

    public function __construct(RuleEngineInterface $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    /**
     * Execute rules on specific transactions.
     */
    public function executeOnTransactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_ids' => 'required|array|min:1',
            'transaction_ids.*' => 'required|integer|exists:transactions,id',
            'rule_ids' => 'nullable|array',
            'rule_ids.*' => 'required|integer|exists:rules,id',
            'dry_run' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Get transactions and verify ownership
        $transactions = Transaction::whereIn('id', $request->input('transaction_ids'))
            ->whereHas('account', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        if ($transactions->count() !== count($request->input('transaction_ids'))) {
            return response()->json(['error' => 'Some transactions not found or unauthorized'], 403);
        }

        // Verify rule ownership if specific rules provided
        if ($request->has('rule_ids')) {
            $ruleCount = Rule::whereIn('id', $request->input('rule_ids'))
                ->where('user_id', $user->id)
                ->count();

            if ($ruleCount !== count($request->input('rule_ids'))) {
                return response()->json(['error' => 'Some rules not found or unauthorized'], 403);
            }
        }

        // Execute rules
        $this->ruleEngine
            ->setUser($user)
            ->setDryRun($request->boolean('dry_run', false))
            ->clearExecutionResults();

        if ($request->has('rule_ids')) {
            $this->ruleEngine->processTransactionsForRules(
                $transactions,
                collect($request->input('rule_ids'))
            );
        } else {
            $this->ruleEngine->processTransactions(
                $transactions,
                Trigger::MANUAL
            );
        }

        $results = $this->ruleEngine->getExecutionResults();

        return response()->json([
            'message' => $request->boolean('dry_run')
                ? 'Dry run completed successfully'
                : 'Rules executed successfully',
            'data' => [
                'total_transactions' => $transactions->count(),
                'total_rules_matched' => count(array_filter($results, fn ($r) => ! empty($r['actions']))),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Execute rules on transactions within a date range.
     */
    public function executeOnDateRange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'rule_ids' => 'nullable|array',
            'rule_ids.*' => 'required|integer|exists:rules,id',
            'dry_run' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Verify rule ownership if specific rules provided
        if ($request->has('rule_ids')) {
            $ruleCount = Rule::whereIn('id', $request->input('rule_ids'))
                ->where('user_id', $user->id)
                ->count();

            if ($ruleCount !== count($request->input('rule_ids'))) {
                return response()->json(['error' => 'Some rules not found or unauthorized'], 403);
            }
        }

        $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

        // Execute rules
        $this->ruleEngine
            ->setUser($user)
            ->setDryRun($request->boolean('dry_run', false))
            ->clearExecutionResults();

        $this->ruleEngine->processDateRange(
            $startDate,
            $endDate,
            $request->input('rule_ids')
        );

        $results = $this->ruleEngine->getExecutionResults();

        return response()->json([
            'message' => $request->boolean('dry_run')
                ? 'Dry run completed successfully'
                : 'Rules executed successfully',
            'data' => [
                'date_range' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'total_rules_matched' => count(array_filter($results, fn ($r) => ! empty($r['actions']))),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Test a rule configuration without saving it.
     */
    public function testRule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_ids' => 'required|array|min:1|max:10', // Limit for testing
            'transaction_ids.*' => 'required|integer|exists:transactions,id',
            'condition_groups' => 'required|array|min:1',
            'condition_groups.*.logic_operator' => 'required|in:AND,OR',
            'condition_groups.*.conditions' => 'required|array|min:1',
            'condition_groups.*.conditions.*.field' => 'required|string',
            'condition_groups.*.conditions.*.operator' => 'required|string',
            'condition_groups.*.conditions.*.value' => 'required|string',
            'condition_groups.*.conditions.*.is_case_sensitive' => 'nullable|boolean',
            'condition_groups.*.conditions.*.is_negated' => 'nullable|boolean',
            'actions' => 'required|array|min:1',
            'actions.*.action_type' => 'required|string',
            'actions.*.action_value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Get transactions and verify ownership
        $transactions = Transaction::whereIn('id', $request->input('transaction_ids'))
            ->whereHas('account', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        if ($transactions->count() !== count($request->input('transaction_ids'))) {
            return response()->json(['error' => 'Some transactions not found or unauthorized'], 403);
        }

        // Create temporary rule object for testing
        $tempRule = new Rule([
            'user_id' => $user->id,
            'name' => 'Test Rule',
            'trigger_type' => Trigger::MANUAL,
        ]);

        // Create temporary condition groups and conditions
        $conditionGroups = collect($request->input('condition_groups'))->map(function ($groupData) {
            $group = new \App\Models\RuleEngine\ConditionGroup([
                'logic_operator' => $groupData['logic_operator'],
            ]);

            $conditions = collect($groupData['conditions'])->map(function ($conditionData) {
                return new \App\Models\RuleEngine\RuleCondition($conditionData);
            });

            $group->setRelation('conditions', $conditions);

            return $group;
        });

        $tempRule->setRelation('conditionGroups', $conditionGroups);

        // Create temporary actions
        $actions = collect($request->input('actions'))->map(function ($actionData) {
            $action = new \App\Models\RuleEngine\RuleAction([
                'action_type' => $actionData['action_type'],
            ]);
            $action->setEncodedValue($actionData['action_value'] ?? null);

            return $action;
        });

        $tempRule->setRelation('actions', $actions);

        // Test the rule against transactions
        $results = [];
        foreach ($transactions as $transaction) {
            $matched = $this->testRuleOnTransaction($tempRule, $transaction);
            $results[] = [
                'transaction_id' => $transaction->id,
                'transaction_description' => $transaction->description,
                'matched' => $matched,
                'would_execute' => $matched ? $this->getActionsDescription($actions) : [],
            ];
        }

        return response()->json([
            'message' => 'Rule test completed',
            'data' => [
                'total_tested' => count($results),
                'total_matched' => count(array_filter($results, fn ($r) => $r['matched'])),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Test if a rule matches a transaction.
     */
    private function testRuleOnTransaction(Rule $rule, Transaction $transaction): bool
    {
        $conditionEvaluator = app(\App\Contracts\RuleEngine\ConditionEvaluatorInterface::class);

        foreach ($rule->conditionGroups as $group) {
            $results = [];
            foreach ($group->conditions as $condition) {
                $result = $conditionEvaluator->evaluate($condition, $transaction);

                if ($condition->is_negated) {
                    $result = ! $result;
                }

                $results[] = $result;
            }

            // Apply AND/OR logic
            if ($group->logic_operator === 'AND') {
                if (! in_array(false, $results, true)) {
                    return true; // At least one group matched
                }
            } else {
                if (in_array(true, $results, true)) {
                    return true; // At least one group matched
                }
            }
        }

        return false;
    }

    /**
     * Execute a single rule manually.
     */
    public function executeRule(Request $request, int $ruleId): JsonResponse
    {
        $user = $request->user();

        // Get the rule and verify ownership
        $rule = Rule::where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if (! $rule) {
            return response()->json(['error' => 'Rule not found or unauthorized'], 404);
        }

        // Get all transactions for the user
        $transactions = Transaction::whereHas('account', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->get();

        // Execute the rule
        $this->ruleEngine
            ->setUser($user)
            ->setDryRun($request->boolean('dry_run', false))
            ->clearExecutionResults();

        $this->ruleEngine->processTransactionsForRules(
            $transactions,
            collect([$ruleId])
        );

        $results = $this->ruleEngine->getExecutionResults();

        return response()->json([
            'message' => $request->boolean('dry_run')
                ? 'Rule dry run completed successfully'
                : 'Rule executed successfully',
            'data' => [
                'rule_id' => $ruleId,
                'rule_name' => $rule->name,
                'total_transactions' => $transactions->count(),
                'total_rules_matched' => count(array_filter($results, fn ($r) => ! empty($r['actions']))),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Execute all rules in a rule group manually.
     */
    public function executeRuleGroup(Request $request, int $groupId): JsonResponse
    {
        $user = $request->user();

        // Get the rule group and verify ownership
        $ruleGroup = RuleGroup::where('id', $groupId)
            ->where('user_id', $user->id)
            ->with('rules')
            ->first();

        if (! $ruleGroup) {
            return response()->json(['error' => 'Rule group not found or unauthorized'], 404);
        }

        if ($ruleGroup->rules->isEmpty()) {
            return response()->json(['error' => 'Rule group has no rules to execute'], 400);
        }

        // Get all transactions for the user
        $transactions = Transaction::whereHas('account', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->get();

        // Get rule IDs from the group
        $ruleIds = $ruleGroup->rules->pluck('id')->toArray();

        // Execute the rules
        $this->ruleEngine
            ->setUser($user)
            ->setDryRun($request->boolean('dry_run', false))
            ->clearExecutionResults();

        $this->ruleEngine->processTransactionsForRules(
            $transactions,
            collect($ruleIds)
        );

        $results = $this->ruleEngine->getExecutionResults();

        return response()->json([
            'message' => $request->boolean('dry_run')
                ? 'Rule group dry run completed successfully'
                : 'Rule group executed successfully',
            'data' => [
                'rule_group_id' => $groupId,
                'rule_group_name' => $ruleGroup->name,
                'total_rules' => count($ruleIds),
                'total_transactions' => $transactions->count(),
                'total_rules_matched' => count(array_filter($results, fn ($r) => ! empty($r['actions']))),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Get descriptions of what actions would do.
     */
    private function getActionsDescription($actions): array
    {
        $actionExecutor = app(\App\Contracts\RuleEngine\ActionExecutorInterface::class);

        return $actions->map(function ($action) use ($actionExecutor) {
            return $actionExecutor->getActionDescription($action);
        })->toArray();
    }
}
