<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetPeriod>
 */
class BudgetPeriodFactory extends Factory
{
    protected $model = BudgetPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = Carbon::now()->startOfMonth();

        return [
            'budget_id' => Budget::factory(),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->copy()->endOfMonth()->format('Y-m-d'),
            'amount_budgeted' => $this->faker->randomFloat(2, 100, 2000),
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BudgetPeriod::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function forMonth(int $year, int $month): static
    {
        $startDate = Carbon::createStrict($year, $month, 1);

        return $this->state(fn (array $attributes) => [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->copy()->endOfMonth()->format('Y-m-d'),
        ]);
    }

    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => sprintf('%04d-01-01', $year),
            'end_date' => sprintf('%04d-12-31', $year),
        ]);
    }

    public function withRollover(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'rollover_amount' => $amount,
        ]);
    }
}
