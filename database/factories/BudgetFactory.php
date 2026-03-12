<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\RecurringGroup;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'target_type' => Budget::TARGET_CATEGORY,
            'amount' => $this->faker->randomFloat(2, 100, 2000),
            'currency' => 'EUR',
            'mode' => Budget::MODE_LIMIT,
            'period_type' => Budget::PERIOD_MONTHLY,
            'name' => null,
            'rollover_enabled' => false,
            'rollover_cap' => null,
            'include_subcategories' => true,
            'include_transfers' => false,
            'auto_create_next' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Budget $budget) {
            if ($budget->target_key === null) {
                $targetId = match ($budget->target_type) {
                    Budget::TARGET_CATEGORY => $budget->category_id,
                    Budget::TARGET_TAG => $budget->tag_id,
                    Budget::TARGET_COUNTERPARTY => $budget->counterparty_id,
                    Budget::TARGET_SUBSCRIPTION => $budget->recurring_group_id,
                    Budget::TARGET_ACCOUNT => $budget->account_id,
                    default => null,
                };
                $budget->target_key = Budget::computeTargetKey($budget->target_type, $targetId);
            }
        });
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => Budget::PERIOD_YEARLY,
        ]);
    }

    public function envelope(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => Budget::MODE_ENVELOPE,
        ]);
    }

    public function withRollover(): static
    {
        return $this->state(fn (array $attributes) => [
            'rollover_enabled' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function overall(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'target_type' => Budget::TARGET_OVERALL,
            'target_key' => 'overall',
        ]);
    }

    public function forTag(?Tag $tag = null): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'tag_id' => $tag?->id ?? Tag::factory(),
            'target_type' => Budget::TARGET_TAG,
        ]);
    }

    public function forCounterparty(?Counterparty $counterparty = null): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'counterparty_id' => $counterparty?->id ?? Counterparty::factory(),
            'target_type' => Budget::TARGET_COUNTERPARTY,
        ]);
    }

    public function forSubscription(?RecurringGroup $group = null): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'recurring_group_id' => $group?->id ?? RecurringGroup::factory(),
            'target_type' => Budget::TARGET_SUBSCRIPTION,
        ]);
    }

    public function forAccount(?Account $account = null): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'account_id' => $account?->id ?? Account::factory(),
            'target_type' => Budget::TARGET_ACCOUNT,
        ]);
    }

    public function forAllSubscriptions(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => null,
            'target_type' => Budget::TARGET_ALL_SUBSCRIPTIONS,
            'target_key' => 'all_subs',
        ]);
    }
}
