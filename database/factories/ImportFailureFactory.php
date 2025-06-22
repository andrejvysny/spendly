<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\ImportFailure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ImportFailure>
 */
class ImportFailureFactory extends Factory
{
    protected $model = ImportFailure::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $errorTypes = [
            ImportFailure::ERROR_TYPE_VALIDATION_FAILED,
            ImportFailure::ERROR_TYPE_DUPLICATE,
            ImportFailure::ERROR_TYPE_PROCESSING_ERROR,
            ImportFailure::ERROR_TYPE_PARSING_ERROR,
        ];

        $rawData = [
            fake()->date('Y-m-d'),
            fake()->randomFloat(2, -1000, 1000),
            fake()->company(),
            fake()->sentence(),
            fake()->iban(),
        ];

        return [
            'import_id' => Import::factory(),
            'row_number' => fake()->numberBetween(1, 1000),
            'raw_data' => $rawData,
            'error_type' => fake()->randomElement($errorTypes),
            'error_message' => fake()->randomElement([
                'Invalid date format',
                'Duplicate transaction detected',
                'Missing required field: amount',
                'Invalid IBAN format',
                'Amount must be numeric',
                'Partner field is required',
            ]),
            'error_details' => [
                'message' => fake()->sentence(),
                'errors' => [fake()->sentence()],
                'field' => fake()->randomElement(['date', 'amount', 'partner', 'iban']),
            ],
            'parsed_data' => $this->generateParsedData(),
            'metadata' => [
                'row_number' => fake()->numberBetween(1, 1000),
                'headers' => ['Date', 'Amount', 'Partner', 'Description', 'IBAN'],
                'delimiter' => ';',
                'quote_char' => '"',
            ],
            'status' => ImportFailure::STATUS_PENDING,
        ];
    }

    /**
     * Indicate that the failure has been reviewed.
     */
    public function reviewed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ImportFailure::STATUS_REVIEWED,
                'reviewed_at' => fake()->dateTimeBetween('-1 week', 'now'),
                'reviewed_by' => User::factory(),
                'review_notes' => fake()->sentence(),
            ];
        });
    }

    /**
     * Indicate that the failure has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ImportFailure::STATUS_RESOLVED,
                'reviewed_at' => fake()->dateTimeBetween('-1 week', 'now'),
                'reviewed_by' => User::factory(),
                'review_notes' => fake()->sentence(),
            ];
        });
    }

    /**
     * Indicate that the failure has been ignored.
     */
    public function ignored(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => ImportFailure::STATUS_IGNORED,
                'reviewed_at' => fake()->dateTimeBetween('-1 week', 'now'),
                'reviewed_by' => User::factory(),
                'review_notes' => fake()->sentence(),
            ];
        });
    }

    /**
     * Create a validation failure.
     */
    public function validationFailed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'error_type' => ImportFailure::ERROR_TYPE_VALIDATION_FAILED,
                'error_message' => fake()->randomElement([
                    'Invalid date format',
                    'Missing required field: amount',
                    'Amount must be numeric',
                    'Partner field is required',
                ]),
                'error_details' => [
                    'message' => 'Validation failed',
                    'errors' => [
                        fake()->randomElement(['Date', 'Amount', 'Partner']) . ' is invalid',
                    ],
                ],
            ];
        });
    }

    /**
     * Create a duplicate failure.
     */
    public function duplicate(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'error_type' => ImportFailure::ERROR_TYPE_DUPLICATE,
                'error_message' => 'Duplicate transaction detected',
                'error_details' => [
                    'message' => 'Duplicate transaction',
                    'duplicate_fingerprint' => fake()->sha256(),
                ],
                'metadata' => array_merge($attributes['metadata'] ?? [], [
                    'duplicate' => true,
                ]),
            ];
        });
    }

    /**
     * Create a processing error failure.
     */
    public function processingError(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'error_type' => ImportFailure::ERROR_TYPE_PROCESSING_ERROR,
                'error_message' => fake()->randomElement([
                    'Unable to process transaction',
                    'Account not found',
                    'Currency conversion failed',
                ]),
                'error_details' => [
                    'message' => 'Processing failed',
                    'exception' => fake()->sentence(),
                ],
            ];
        });
    }

    /**
     * Generate realistic parsed data.
     */
    private function generateParsedData(): ?array
    {
        if (fake()->boolean(70)) {
            return [
                'booked_date' => fake()->date('Y-m-d H:i:s'),
                'amount' => fake()->randomFloat(2, -1000, 1000),
                'partner' => fake()->company(),
                'description' => fake()->sentence(),
                'currency' => 'EUR',
                'account_id' => fake()->numberBetween(1, 10),
                'transaction_id' => 'IMP-' . fake()->uuid(),
            ];
        }

        return null; // Some failures might not have parsed data
    }
} 