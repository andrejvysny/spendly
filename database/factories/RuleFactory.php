<?php

namespace Database\Factories;

use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleEngine\Rule>
 */
class RuleFactory extends Factory
{
    protected $model = Rule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rule_group_id' => RuleGroup::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'trigger_type' => $this->faker->randomElement(Rule::getTriggerTypes()),
            'stop_processing' => $this->faker->boolean(20), // 20% chance of stopping
            'order' => $this->faker->numberBetween(0, 100),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Rule $rule) {
            // Ensure user_id matches the rule group's user_id
            if ($rule->rule_group_id && $rule->ruleGroup) {
                $rule->user_id = $rule->ruleGroup->user_id;
            }
        });
    }

    /**
     * Indicate that the rule is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the rule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the rule should stop processing.
     */
    public function stopProcessing(): static
    {
        return $this->state(fn (array $attributes) => [
            'stop_processing' => true,
        ]);
    }

    /**
     * Set a specific trigger type.
     */
    public function triggerType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => $type,
        ]);
    }
}
