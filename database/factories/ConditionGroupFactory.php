<?php

namespace Database\Factories;

use App\Models\RuleEngine\ConditionGroup;
use App\Models\RuleEngine\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleEngine\ConditionGroup>
 */
class ConditionGroupFactory extends Factory
{
    protected $model = ConditionGroup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'logic_operator' => $this->faker->randomElement(['AND', 'OR']),
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }

    /**
     * Indicate that the condition group uses AND logic.
     */
    public function andLogic(): static
    {
        return $this->state(fn (array $attributes) => [
            'logic_operator' => 'AND',
        ]);
    }

    /**
     * Indicate that the condition group uses OR logic.
     */
    public function orLogic(): static
    {
        return $this->state(fn (array $attributes) => [
            'logic_operator' => 'OR',
        ]);
    }
}
