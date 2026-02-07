<?php

namespace Database\Factories;

use App\Models\Import\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'filename' => $this->faker->unique()->uuid.'.csv',
            'original_filename' => $this->faker->word.'.csv',
            'status' => Import::STATUS_PENDING,
            'total_rows' => $this->faker->numberBetween(1, 10),
            'processed_rows' => 0,
            'failed_rows' => 0,
            'metadata' => [],
        ];
    }
}
