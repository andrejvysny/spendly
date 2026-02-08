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
        $year = (int) $this->faker->year();
        $month = $this->faker->numberBetween(1, 12);

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'amount' => $this->faker->randomFloat(2, 100, 2000),
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => $year,
            'month' => $month,
            'name' => null,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => Budget::PERIOD_YEARLY,
            'month' => 0,
        ]);
    }
}
