<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
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
            'amount' => $this->faker->randomFloat(2, 100, 2000),
            'currency' => 'EUR',
            'mode' => Budget::MODE_LIMIT,
            'period_type' => Budget::PERIOD_MONTHLY,
            'name' => null,
            'rollover_enabled' => false,
            'rollover_cap' => null,
            'include_subcategories' => true,
            'auto_create_next' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
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
}
