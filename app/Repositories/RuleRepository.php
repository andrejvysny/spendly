<?php

namespace App\Repositories;

use App\Contracts\Repositories\RuleRepositoryInterface;
use App\Models\ConditionGroup;
use App\Models\Rule;
use App\Models\RuleAction;
use App\Models\RuleCondition;
use App\Models\RuleGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RuleRepository extends BaseRepository implements RuleRepositoryInterface
{
    public function __construct(Rule $model)
    {
        parent::__construct($model);
    }
{
    /**
     * Create a new rule group.
     */
    public function createRuleGroup(User $user, array $data): RuleGroup
    {
        return RuleGroup::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'order' => $data['order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Update a rule group.
     */
    public function updateRuleGroup(RuleGroup $ruleGroup, array $data): RuleGroup
    {
        $ruleGroup->update($data);

        return $ruleGroup->fresh();
    }

    /**
     * Create a new rule with conditions and actions.
     */
    public function createRule(User $user, array $data): Rule
    {
        return DB::transaction(function () use ($user, $data) {
            // Create the rule
            $rule = Rule::create([
                'user_id' => $user->id,
                'rule_group_id' => $data['rule_group_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger_type' => $data['trigger_type'],
                'stop_processing' => $data['stop_processing'] ?? false,
                'order' => $data['order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Create condition groups and conditions
            if (isset($data['condition_groups'])) {
                foreach ($data['condition_groups'] as $groupIndex => $groupData) {
                    $conditionGroup = ConditionGroup::create([
                        'rule_id' => $rule->id,
                        'logic_operator' => $groupData['logic_operator'] ?? 'AND',
                        'order' => $groupData['order'] ?? $groupIndex,
                    ]);

                    if (isset($groupData['conditions'])) {
                        foreach ($groupData['conditions'] as $conditionIndex => $conditionData) {
                            RuleCondition::create([
                                'condition_group_id' => $conditionGroup->id,
                                'field' => $conditionData['field'],
                                'operator' => $conditionData['operator'],
                                'value' => $conditionData['value'],
                                'is_case_sensitive' => $conditionData['is_case_sensitive'] ?? false,
                                'is_negated' => $conditionData['is_negated'] ?? false,
                                'order' => $conditionData['order'] ?? $conditionIndex,
                            ]);
                        }
                    }
                }
            }

            // Create actions
            if (isset($data['actions'])) {
                foreach ($data['actions'] as $actionIndex => $actionData) {
                    $action = new RuleAction([
                        'rule_id' => $rule->id,
                        'action_type' => $actionData['action_type'],
                        'order' => $actionData['order'] ?? $actionIndex,
                        'stop_processing' => $actionData['stop_processing'] ?? false,
                    ]);
                    $action->setEncodedValue($actionData['action_value'] ?? null);
                    $action->save();
                }
            }

            return $rule->load(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Update a rule with conditions and actions.
     */
    public function updateRule(Rule $rule, array $data): Rule
    {
        return DB::transaction(function () use ($rule, $data) {
            // Update the rule
            $rule->update([
                'name' => $data['name'] ?? $rule->name,
                'description' => $data['description'] ?? $rule->description,
                'trigger_type' => $data['trigger_type'] ?? $rule->trigger_type,
                'stop_processing' => $data['stop_processing'] ?? $rule->stop_processing,
                'order' => $data['order'] ?? $rule->order,
                'is_active' => $data['is_active'] ?? $rule->is_active,
            ]);

            // Handle condition groups if provided
            if (isset($data['condition_groups'])) {
                // Delete existing condition groups
                $rule->conditionGroups()->delete();

                // Create new condition groups
                foreach ($data['condition_groups'] as $groupIndex => $groupData) {
                    $conditionGroup = ConditionGroup::create([
                        'rule_id' => $rule->id,
                        'logic_operator' => $groupData['logic_operator'] ?? 'AND',
                        'order' => $groupData['order'] ?? $groupIndex,
                    ]);

                    if (isset($groupData['conditions'])) {
                        foreach ($groupData['conditions'] as $conditionIndex => $conditionData) {
                            RuleCondition::create([
                                'condition_group_id' => $conditionGroup->id,
                                'field' => $conditionData['field'],
                                'operator' => $conditionData['operator'],
                                'value' => $conditionData['value'],
                                'is_case_sensitive' => $conditionData['is_case_sensitive'] ?? false,
                                'is_negated' => $conditionData['is_negated'] ?? false,
                                'order' => $conditionData['order'] ?? $conditionIndex,
                            ]);
                        }
                    }
                }
            }

            // Handle actions if provided
            if (isset($data['actions'])) {
                // Delete existing actions
                $rule->actions()->delete();

                // Create new actions
                foreach ($data['actions'] as $actionIndex => $actionData) {
                    $action = new RuleAction([
                        'rule_id' => $rule->id,
                        'action_type' => $actionData['action_type'],
                        'order' => $actionData['order'] ?? $actionIndex,
                        'stop_processing' => $actionData['stop_processing'] ?? false,
                    ]);
                    $action->setEncodedValue($actionData['action_value'] ?? null);
                    $action->save();
                }
            }

            return $rule->fresh(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Delete a rule.
     */
    public function deleteRule(Rule $rule): bool
    {
        return $rule->delete();
    }

    /**
     * Get all rule groups for a user.
     */
    public function getRuleGroups(User $user, bool $activeOnly = false): Collection
    {
        $query = $user->ruleGroups()->with(['rules' => function ($query) {
            $query->with(['conditionGroups.conditions', 'actions'])
                ->orderBy('order');
        }]);

        if ($activeOnly) {
            $query->active();
        }

        return $query->ordered()->get();
    }

    /**
     * Get a single rule with all relationships.
     */
    public function getRule(int $ruleId, User $user): ?Rule
    {
        return Rule::with(['ruleGroup', 'conditionGroups.conditions', 'actions'])
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Get rules by trigger type.
     */
    public function getRulesByTrigger(User $user, string $triggerType, bool $activeOnly = true): Collection
    {
        $query = Rule::with(['conditionGroups.conditions', 'actions'])
            ->where('user_id', $user->id)
            ->where('trigger_type', $triggerType);

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('order')->get();
    }

    /**
     * Duplicate a rule.
     */
    public function duplicateRule(Rule $rule, ?string $newName = null): Rule
    {
        return DB::transaction(function () use ($rule, $newName) {
            $newRule = $rule->replicate();
            $newRule->name = $newName ?? $rule->name.' (Copy)';
            $newRule->save();

            // Duplicate condition groups and conditions
            foreach ($rule->conditionGroups as $group) {
                $newGroup = $group->replicate();
                $newGroup->rule_id = $newRule->id;
                $newGroup->save();

                foreach ($group->conditions as $condition) {
                    $newCondition = $condition->replicate();
                    $newCondition->condition_group_id = $newGroup->id;
                    $newCondition->save();
                }
            }

            // Duplicate actions
            foreach ($rule->actions as $action) {
                $newAction = $action->replicate();
                $newAction->rule_id = $newRule->id;
                $newAction->save();
            }

            return $newRule->load(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Reorder rules within a group.
     */
    public function reorderRules(array $ruleIds): void
    {
        foreach ($ruleIds as $order => $ruleId) {
            Rule::where('id', $ruleId)->update(['order' => $order]);
        }
    }

    /**
     * Get rule execution statistics.
     */
    public function getRuleStatistics(Rule $rule, ?int $days = 30): array
    {
        $query = $rule->executionLogs();

        if ($days !== null) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $total = $query->count();
        $matched = $query->matched()->count();

        return [
            'total_executions' => $total,
            'total_matches' => $matched,
            'match_rate' => $total > 0 ? round(($matched / $total) * 100, 2) : 0,
            'last_matched' => $query->matched()->latest()->value('created_at'),
            'last_executed' => $query->latest()->value('created_at'),
        ];
    }
}
