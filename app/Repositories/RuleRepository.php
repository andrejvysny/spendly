<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\RuleRepositoryInterface;
use App\Models\RuleEngine\ConditionGroup;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleAction;
use App\Models\RuleEngine\RuleCondition;
use App\Models\RuleEngine\RuleGroup;
use App\Models\User;
use Illuminate\Support\Collection;

class RuleRepository extends BaseRepository implements RuleRepositoryInterface
{
    public function __construct(Rule $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
     */
    public function updateRuleGroup(RuleGroup $ruleGroup, array $data): RuleGroup
    {
        $ruleGroup->update($data);

        return $ruleGroup->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(User $user, array $data): Rule
    {
        return $this->transaction(function () use ($user, $data) {
            $rule = $this->model->create([
                'user_id' => $user->id,
                'rule_group_id' => $data['rule_group_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger_type' => $data['trigger_type'],
                'stop_processing' => $data['stop_processing'] ?? false,
                'order' => $data['order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

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

            $loaded = $rule->load(['conditionGroups.conditions', 'actions']);

            return $loaded instanceof Rule ? $loaded : $this->model->find($rule->getKey());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(Rule $rule, array $data): Rule
    {
        return $this->transaction(function () use ($rule, $data) {
            $rule->update([
                'name' => $data['name'] ?? $rule->name,
                'description' => $data['description'] ?? $rule->description,
                'trigger_type' => $data['trigger_type'] ?? $rule->trigger_type,
                'stop_processing' => $data['stop_processing'] ?? $rule->stop_processing,
                'order' => $data['order'] ?? $rule->order,
                'is_active' => $data['is_active'] ?? $rule->is_active,
            ]);

            if (isset($data['condition_groups'])) {
                $rule->conditionGroups()->delete();

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

            if (isset($data['actions'])) {
                $rule->actions()->delete();

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

            $fresh = $rule->fresh(['conditionGroups.conditions', 'actions']);

            return $fresh instanceof Rule ? $fresh : $this->model->find($rule->getKey());
        });
    }

    public function deleteRule(Rule $rule): bool
    {
        return $rule->delete();
    }

    /**
     * @return Collection<int, RuleGroup>
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

    public function getRule(int $ruleId, User $user): ?Rule
    {
        $rule = $this->model->with(['ruleGroup', 'conditionGroups.conditions', 'actions'])
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        return $rule instanceof Rule ? $rule : null;
    }

    /**
     * @return Collection<int, Rule>
     */
    public function getRulesByTrigger(User $user, string $triggerType, bool $activeOnly = true): Collection
    {
        $query = $this->model->with(['conditionGroups.conditions', 'actions'])
            ->where('user_id', $user->id)
            ->where('trigger_type', $triggerType);

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('order')->get();
    }

    public function duplicateRule(Rule $rule, ?string $newName = null): Rule
    {
        return $this->transaction(function () use ($rule, $newName) {
            $newRule = $rule->replicate();
            $newRule->name = $newName ?? $rule->name.' (Copy)';
            $newRule->save();

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

            foreach ($rule->actions as $action) {
                $newAction = $action->replicate();
                $newAction->rule_id = $newRule->id;
                $newAction->save();
            }

            $loaded = $newRule->load(['conditionGroups.conditions', 'actions']);

            return $loaded instanceof Rule ? $loaded : $this->model->find($newRule->getKey());
        });
    }

    /**
     * @param  array<int>  $ruleIds
     */
    public function reorderRules(array $ruleIds): void
    {
        foreach ($ruleIds as $order => $ruleId) {
            $this->model->where('id', $ruleId)->update(['order' => $order]);
        }
    }

    /**
     * @return array<string, mixed>
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
