<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface RuleRepositoryInterface extends BaseRepositoryContract
{
    public function createRuleGroup(User $user, array $data): RuleGroup;
    public function updateRuleGroup(RuleGroup $ruleGroup, array $data): RuleGroup;

    public function createRule(User $user, array $data): Rule;
    public function updateRule(Rule $rule, array $data): Rule;
    public function deleteRule(Rule $rule): bool;

    public function getRuleGroups(User $user, bool $activeOnly = false): Collection;
    public function getRule(int $ruleId, User $user): ?Rule;
    public function getRulesByTrigger(User $user, string $triggerType, bool $activeOnly = true): Collection;

    public function duplicateRule(Rule $rule, ?string $newName = null): Rule;
    public function reorderRules(array $ruleIds): void;
    public function getRuleStatistics(Rule $rule, ?int $days = 30): array;
}
