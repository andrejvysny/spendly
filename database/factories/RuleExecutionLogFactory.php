<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleExecutionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleExecutionLog>
 */
class RuleExecutionLogFactory extends Factory
{
    protected $model = RuleExecutionLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $matched = $this->faker->boolean(70); // 70% match rate

        return [
            'rule_id' => Rule::factory(),
            'transaction_id' => (string) $this->faker->numberBetween(1000, 999999),
            'matched' => $matched,
            'actions_executed' => $matched ? $this->faker->randomElements([
                'set_category' => 'Category set to Groceries',
                'add_tag' => 'Tag "Shopping" added',
                'set_note' => 'Note updated',
                'mark_reconciled' => 'Transaction marked as reconciled',
            ], $this->faker->numberBetween(1, 3)) : [],
            'execution_context' => [
                'trigger' => $this->faker->randomElement(['transaction_created', 'transaction_updated', 'manual']),
                'dry_run' => $this->faker->boolean(20), // 20% dry runs
                'execution_time' => $this->faker->randomFloat(3, 0.001, 2.000),
                'user_id' => $this->faker->numberBetween(1, 100),
            ],
        ];
    }

    /**
     * Indicate that the rule matched.
     */
    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'matched' => true,
            'actions_executed' => $this->faker->randomElements([
                'set_category' => 'Category set',
                'add_tag' => 'Tag added',
                'set_note' => 'Note updated',
            ], $this->faker->numberBetween(1, 3)),
        ]);
    }

    /**
     * Indicate that the rule did not match.
     */
    public function notMatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'matched' => false,
            'actions_executed' => [],
        ]);
    }

    /**
     * Indicate that this was a dry run.
     */
    public function dryRun(): static
    {
        return $this->state(fn (array $attributes) => [
            'execution_context' => array_merge($attributes['execution_context'] ?? [], [
                'dry_run' => true,
            ]),
        ]);
    }
}
