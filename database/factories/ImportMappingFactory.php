<?php

namespace Database\Factories;

use App\Models\Import\ImportMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Import\ImportMapping>
 */
class ImportMappingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ImportMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company().' Bank Import',
            'bank_name' => $this->faker->randomElement(['Chase Bank', 'Bank of America', 'Wells Fargo', 'Citibank', null]),
            'column_mapping' => [
                'date' => $this->faker->randomElement(['Transaction Date', 'Date', 'Trans Date', 'Posted Date']),
                'amount' => $this->faker->randomElement(['Amount', 'Transaction Amount', 'Value']),
                'description' => $this->faker->randomElement(['Description', 'Transaction Description', 'Details', 'Memo']),
            ],
            'date_format' => $this->faker->randomElement(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y']),
            'amount_format' => $this->faker->randomElement(['decimal', 'comma_decimal', 'space_decimal']),
            'amount_type_strategy' => $this->faker->randomElement(['single_column', 'separate_columns', 'sign_based']),
            'currency' => $this->faker->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'last_used_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
