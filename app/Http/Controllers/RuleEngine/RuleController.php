<?php

namespace App\Http\Controllers\RuleEngine;

use App\Http\Controllers\Controller;
use App\Models\RuleEngine\ActionType;
use App\Models\RuleEngine\ConditionField;
use App\Models\RuleEngine\ConditionOperator;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleGroup;
use App\Models\RuleEngine\Trigger;
use App\Models\Transaction;
use App\Repositories\RuleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends Controller
{
    protected RuleRepository $ruleRepository;

    public function __construct(RuleRepository $ruleRepository)
    {
        $this->ruleRepository = $ruleRepository;
    }

    /**
     * Display the rules page using Inertia.
     */
    public function indexPage(Request $request): Response
    {
        $user = $request->user();

        $ruleGroups = $this->ruleRepository->getRuleGroups(
            $user,
            $request->boolean('active_only', false)
        );

        // Get data for dynamic inputs
        $categories = $user->categories()->select('id', 'name')->get();
        $merchants = $user->merchants()->select('id', 'name')->get();
        $tags = $user->tags()->select('id', 'name')->get();

        // ActionType input configuration
        $actionInputConfig = [
            ActionType::ACTION_SET_CATEGORY->value => [
                'type' => 'select',
                'model' => 'categories',
                'placeholder' => 'Select a category',
            ],
            ActionType::ACTION_SET_MERCHANT->value => [
                'type' => 'select',
                'model' => 'merchants',
                'placeholder' => 'Select a merchant',
            ],
            ActionType::ACTION_ADD_TAG->value => [
                'type' => 'select',
                'model' => 'tags',
                'placeholder' => 'Select a tag',
            ],
            ActionType::ACTION_REMOVE_TAG->value => [
                'type' => 'select',
                'model' => 'tags',
                'placeholder' => 'Select a tag',
            ],
            ActionType::ACTION_REMOVE_ALL_TAGS->value => [
                'type' => 'none',
                'placeholder' => 'No value needed',
            ],
            ActionType::ACTION_SET_DESCRIPTION->value => [
                'type' => 'text',
                'placeholder' => 'Enter description',
            ],
            ActionType::ACTION_APPEND_DESCRIPTION->value => [
                'type' => 'text',
                'placeholder' => 'Enter text to append',
            ],
            ActionType::ACTION_PREPEND_DESCRIPTION->value => [
                'type' => 'text',
                'placeholder' => 'Enter text to prepend',
            ],
            ActionType::ACTION_SET_NOTE->value => [
                'type' => 'text',
                'placeholder' => 'Enter note',
            ],
            ActionType::ACTION_APPEND_NOTE->value => [
                'type' => 'text',
                'placeholder' => 'Enter text to append',
            ],
            ActionType::ACTION_SET_TYPE->value => [
                'type' => 'select',
                'model' => 'transaction_types',
                'placeholder' => 'Select transaction type',
            ],
            ActionType::ACTION_MARK_RECONCILED->value => [
                'type' => 'none',
                'placeholder' => 'No value needed',
            ],
            ActionType::ACTION_SEND_NOTIFICATION->value => [
                'type' => 'text',
                'placeholder' => 'Enter notification message',
            ],
            ActionType::ACTION_CREATE_TAG_IF_NOT_EXISTS->value => [
                'type' => 'text',
                'placeholder' => 'Enter tag name',
            ],
            ActionType::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS->value => [
                'type' => 'text',
                'placeholder' => 'Enter category name',
            ],
            ActionType::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS->value => [
                'type' => 'text',
                'placeholder' => 'Enter merchant name',
            ],
        ];

        return Inertia::render('rules/index', [
            'initialRuleGroups' => $ruleGroups,
            'ruleOptions' => [
                'trigger_types' => Trigger::cases(),
                'fields' => ConditionField::cases(),
                'operators' => ConditionOperator::cases(),
                'logic_operators' => ['AND', 'OR'],
                'action_types' => ActionType::cases(),
                'field_operators' => [
                    'numeric' => ConditionOperator::numeric(),
                    'string' => ConditionOperator::string(),
                ],
                'categories' => $categories,
                'merchants' => $merchants,
                'tags' => $tags,
                'transaction_types' => [
                    Transaction::TYPE_TRANSFER => 'Transfer',
                    Transaction::TYPE_CARD_PAYMENT => 'Card Payment',
                    Transaction::TYPE_EXCHANGE => 'Exchange',
                    Transaction::TYPE_PAYMENT => 'Payment',
                    Transaction::TYPE_WITHDRAWAL => 'Withdrawal',
                    Transaction::TYPE_DEPOSIT => 'Deposit',
                ],
            ],
            'actionInputConfig' => $actionInputConfig,
        ]);
    }

    /**
     * Display a listing of rule groups and rules for API calls.
     */
    public function index(Request $request): JsonResponse
    {
        $ruleGroups = $this->ruleRepository->getRuleGroups(
            $request->user(),
            $request->boolean('active_only', false)
        );

        return response()->json([
            'data' => $ruleGroups,
        ]);
    }

    /**
     * Store a new rule group.
     */
    public function storeGroup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ruleGroup = $this->ruleRepository->createRuleGroup(
            $request->user(),
            $validator->validated()
        );

        return response()->json([
            'message' => 'Rule group created successfully',
            'data' => $ruleGroup,
        ], 201);
    }

    /**
     * Update a rule group.
     */
    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $ruleGroup = RuleGroup::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ruleGroup = $this->ruleRepository->updateRuleGroup($ruleGroup, $validator->validated());

        return response()->json([
            'message' => 'Rule group updated successfully',
            'data' => $ruleGroup,
        ]);
    }

    /**
     * Delete a rule group.
     */
    public function destroyGroup(Request $request, int $id): JsonResponse
    {
        $ruleGroup = RuleGroup::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Check if group has rules
        if ($ruleGroup->rules()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete rule group that contains rules. Please delete all rules first.',
            ], 422);
        }

        $ruleGroup->delete();

        return response()->json([
            'message' => 'Rule group deleted successfully',
        ]);
    }

    /**
     * Store a new rule.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rule_group_id' => 'required|exists:rule_groups,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, Trigger::cases())),
            'stop_processing' => 'nullable|boolean',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'condition_groups' => 'required|array|min:1',
            'condition_groups.*.logic_operator' => 'required|in:AND,OR',
            'condition_groups.*.conditions' => 'required|array|min:1',
            'condition_groups.*.conditions.*.field' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ConditionField::cases())),
            'condition_groups.*.conditions.*.operator' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ConditionOperator::cases())),
            'condition_groups.*.conditions.*.value' => 'required|string',
            'condition_groups.*.conditions.*.is_case_sensitive' => 'nullable|boolean',
            'condition_groups.*.conditions.*.is_negated' => 'nullable|boolean',
            'actions' => 'required|array|min:1',
            'actions.*.action_type' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ActionType::cases())),
            'actions.*.action_value' => 'nullable',
            'actions.*.stop_processing' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify rule group belongs to user
        $ruleGroup = RuleGroup::findOrFail($request->input('rule_group_id'));
        if ($ruleGroup->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $rule = DB::transaction(function () use ($request, $validator) {
            return $this->ruleRepository->createRule(
                $request->user(),
                $validator->validated()
            );
        });

        return response()->json([
            'message' => 'Rule created successfully',
            'data' => $rule,
        ], 201);
    }

    /**
     * Display the specified rule.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $rule = $this->ruleRepository->getRule($id, $request->user());

        if (! $rule) {
            return response()->json(['error' => 'Rule not found'], 404);
        }

        return response()->json([
            'data' => $rule,
        ]);
    }

    /**
     * Update the specified rule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = Rule::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => 'nullable|in:'.implode(',', array_map(fn ($case) => $case->value, Trigger::cases())),
            'stop_processing' => 'nullable|boolean',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'condition_groups' => 'nullable|array',
            'condition_groups.*.logic_operator' => 'required_with:condition_groups|in:AND,OR',
            'condition_groups.*.conditions' => 'required_with:condition_groups|array|min:1',
            'condition_groups.*.conditions.*.field' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ConditionField::cases())),
            'condition_groups.*.conditions.*.operator' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ConditionOperator::cases())),
            'condition_groups.*.conditions.*.value' => 'required|string',
            'condition_groups.*.conditions.*.is_case_sensitive' => 'nullable|boolean',
            'condition_groups.*.conditions.*.is_negated' => 'nullable|boolean',
            'actions' => 'nullable|array',
            'actions.*.action_type' => 'required|in:'.implode(',', array_map(fn ($case) => $case->value, ActionType::cases())),
            'actions.*.action_value' => 'nullable',
            'actions.*.stop_processing' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rule = $this->ruleRepository->updateRule($rule, $validator->validated());

        return response()->json([
            'message' => 'Rule updated successfully',
            'data' => $rule,
        ]);
    }

    /**
     * Remove the specified rule.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $rule = Rule::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->ruleRepository->deleteRule($rule);

        return response()->json([
            'message' => 'Rule deleted successfully',
        ]);
    }

    /**
     * Duplicate a rule.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $rule = Rule::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $newRule = $this->ruleRepository->duplicateRule(
            $rule,
            $request->input('name')
        );

        return response()->json([
            'message' => 'Rule duplicated successfully',
            'data' => $newRule,
        ], 201);
    }

    /**
     * Get rule statistics.
     */
    public function statistics(Request $request, int $id): JsonResponse
    {
        $rule = Rule::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $days = $request->input('days', 30);
        $statistics = $this->ruleRepository->getRuleStatistics($rule, $days);

        return response()->json([
            'data' => $statistics,
        ]);
    }

    /**
     * Reorder rules within a group or globally.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rules' => 'required|array',
            'rules.*.id' => 'required|exists:rules,id',
            'rules.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify all rules belong to the current user
        $ruleIds = collect($validator->validated()['rules'])->pluck('id');
        $userRules = Rule::whereIn('id', $ruleIds)
            ->where('user_id', $request->user()->id)
            ->pluck('id');

        if ($userRules->count() !== $ruleIds->count()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Update rule orders
        foreach ($validator->validated()['rules'] as $ruleData) {
            Rule::where('id', $ruleData['id'])->update(['order' => $ruleData['order']]);
        }

        return response()->json(['message' => 'Rules reordered successfully']);
    }

    /**
     * Toggle the activation status of a rule group.
     */
    public function toggleGroupActivation(Request $request, int $id): JsonResponse
    {
        $ruleGroup = RuleGroup::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $ruleGroup->update(['is_active' => ! $ruleGroup->is_active]);

        return response()->json([
            'message' => 'Rule group activation status updated successfully',
            'data' => $ruleGroup,
        ]);
    }

    /**
     * Toggle the activation status of a rule.
     */
    public function toggleRuleActivation(Request $request, int $id): JsonResponse
    {
        $rule = Rule::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json([
            'message' => 'Rule activation status updated successfully',
            'data' => $rule,
        ]);
    }

    /**
     * Get available fields, operators, and action types.
     */
    public function getOptions(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'data' => [
                'trigger_types' => Trigger::cases(),
                'fields' => ConditionField::cases(),
                'operators' => ConditionOperator::cases(),
                'logic_operators' => ['AND', 'OR'],
                'action_types' => ActionType::cases(),
                'field_operators' => [
                    'numeric' => ConditionOperator::numeric(),
                    'string' => ConditionOperator::string(),
                ],
                // Add data for select inputs
                'categories' => $user->categories()->select('id', 'name')->get(),
                'merchants' => $user->merchants()->select('id', 'name')->get(),
                'tags' => $user->tags()->select('id', 'name')->get(),
                'transaction_types' => [
                    Transaction::TYPE_TRANSFER => 'Transfer',
                    Transaction::TYPE_CARD_PAYMENT => 'Card Payment',
                    Transaction::TYPE_EXCHANGE => 'Exchange',
                    Transaction::TYPE_PAYMENT => 'Payment',
                    Transaction::TYPE_WITHDRAWAL => 'Withdrawal',
                    Transaction::TYPE_DEPOSIT => 'Deposit',
                ],
            ],
        ]);
    }

    /**
     * Get action input configuration for frontend.
     */
    public function getActionInputConfig(): JsonResponse
    {
        return response()->json([
            'data' => [
                'action_input_types' => [
                    ActionType::ACTION_SET_CATEGORY->value => [
                        'type' => 'select',
                        'model' => 'categories',
                        'placeholder' => 'Select a category',
                    ],
                    ActionType::ACTION_SET_MERCHANT->value => [
                        'type' => 'select',
                        'model' => 'merchants',
                        'placeholder' => 'Select a merchant',
                    ],
                    ActionType::ACTION_ADD_TAG->value => [
                        'type' => 'select',
                        'model' => 'tags',
                        'placeholder' => 'Select a tag',
                    ],
                    ActionType::ACTION_REMOVE_TAG->value => [
                        'type' => 'select',
                        'model' => 'tags',
                        'placeholder' => 'Select a tag',
                    ],
                    ActionType::ACTION_REMOVE_ALL_TAGS->value => [
                        'type' => 'none',
                        'placeholder' => 'No value needed',
                    ],
                    ActionType::ACTION_SET_DESCRIPTION->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter description',
                    ],
                    ActionType::ACTION_APPEND_DESCRIPTION->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter text to append',
                    ],
                    ActionType::ACTION_PREPEND_DESCRIPTION->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter text to prepend',
                    ],
                    ActionType::ACTION_SET_NOTE->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter note',
                    ],
                    ActionType::ACTION_APPEND_NOTE->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter text to append',
                    ],
                    ActionType::ACTION_SET_TYPE->value => [
                        'type' => 'select',
                        'model' => 'transaction_types',
                        'placeholder' => 'Select transaction type',
                    ],
                    ActionType::ACTION_MARK_RECONCILED->value => [
                        'type' => 'none',
                        'placeholder' => 'No value needed',
                    ],
                    ActionType::ACTION_SEND_NOTIFICATION->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter notification message',
                    ],
                    ActionType::ACTION_CREATE_TAG_IF_NOT_EXISTS->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter tag name',
                    ],
                    ActionType::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter category name',
                    ],
                    ActionType::ACTION_CREATE_MERCHANT_IF_NOT_EXISTS->value => [
                        'type' => 'text',
                        'placeholder' => 'Enter merchant name',
                    ],
                ],
            ],
        ]);
    }
}
