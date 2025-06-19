<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => $this->faker->company().' Account',
            'bank_name' => $this->faker->company().' Bank',
            'iban' => $this->faker->iban(),
            'type' => $this->faker->randomElement(['checking', 'savings']),
            'currency' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'balance' => $this->faker->randomFloat(2, 0, 10000),
            'is_gocardless_synced' => false,
            'gocardless_account_id' => null,
            'gocardless_last_synced_at' => null,
            'import_data' => null,
            'sync_options' => null,
        ];
    }
}
