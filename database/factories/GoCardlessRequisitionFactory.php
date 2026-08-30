<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoCardlessRequisition>
 */
class GoCardlessRequisitionFactory extends Factory
{
    /**
     * @var class-string<GoCardlessRequisition>
     */
    protected $model = GoCardlessRequisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'requisition_id' => (string) Str::uuid(),
            'reference' => (string) Str::uuid(),
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'institution_name' => 'Sandbox Finance',
            'agreement_id' => null,
            'status' => GoCardlessRequisitionStatus::PENDING,
            'gocardless_status' => null,
            'max_historical_days' => 90,
            'access_valid_for_days' => 90,
            'accepted_at' => null,
            'access_valid_until' => null,
            'access_valid_until_estimated' => true,
            'accounts' => [],
            'link' => 'https://ob.gocardless.com/psd2/start/'.Str::uuid()->toString().'/SANDBOXFINANCE_SFIN0000',
            'return_to' => null,
            'callback_completed_at' => null,
            'last_checked_at' => null,
            'expiry_warning_sent_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * Pending authorization: no accounts yet, mirrors GoCardless "CR" status.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoCardlessRequisitionStatus::PENDING,
            'gocardless_status' => 'CR',
            'accounts' => [],
            'accepted_at' => null,
            'access_valid_until' => null,
        ]);
    }

    /**
     * User completed bank auth: accounts available, access window open.
     */
    public function linked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoCardlessRequisitionStatus::LINKED,
            'gocardless_status' => 'LN',
            'accounts' => ['acct-uuid-1'],
            'accepted_at' => now(),
            'access_valid_until' => now()->addDays(90),
            'access_valid_until_estimated' => false,
        ]);
    }

    /**
     * Access window has passed; user needs to reconnect.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoCardlessRequisitionStatus::EXPIRED,
            'gocardless_status' => 'EX',
            'access_valid_until' => now()->subDay(),
            'access_valid_until_estimated' => false,
        ]);
    }
}
