<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface RuleRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createRuleGroup(User $user, array $data): RuleGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRuleGroup(RuleGroup $ruleGroup, array $data): RuleGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(User $user, array $data): Rule;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(Rule $rule, array $data): Rule;

    public function deleteRule(Rule $rule): bool;

    /**
     * @return Collection<int, RuleGroup>
     */
    public function getRuleGroups(User $user, bool $activeOnly = false): Collection;

    public function getRule(int $ruleId, User $user): ?Rule;

    /**
     * @return Collection<int, Rule>
     */
    public function getRulesByTrigger(User $user, string $triggerType, bool $activeOnly = true): Collection;

    public function duplicateRule(Rule $rule, ?string $newName = null): Rule;

    /**
     * @param  array<int>  $ruleIds
     */
    public function reorderRules(array $ruleIds): void;

    /**
     * @return array<string, mixed>
     */
    public function getRuleStatistics(Rule $rule, ?int $days = 30): array;
}
