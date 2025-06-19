<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $types = ['TRANSFER', 'DEPOSIT', 'WITHDRAWAL', 'PAYMENT'];
        $type = $this->faker->randomElement($types);
        $amount = $this->faker->randomFloat(2, -1000, 2000);
        $date = $this->faker->dateTimeBetween('-3 months', 'now');
        $targetIban = $type === 'TRANSFER' ? $this->faker->iban('SK') : null;
        $sourceIban = $type === 'TRANSFER' ? $this->faker->iban('SK') : null;
        $partner = $this->faker->company();

        $identifierFields = [
            'amount' => $amount,
            'currency' => 'EUR',
            'booked_date' => $date->format('Y-m-d H:i:s'),
            'source_iban' => $sourceIban,
            'target_iban' => $targetIban,
            'partner' => $partner,
        ];
        ksort($identifierFields);
        $identifierJson = json_encode($identifierFields, JSON_THROW_ON_ERROR);
        $identifier     = hash('sha256', $identifierJson);
        return [
            'transaction_id' => 'TRX-'.$this->faker->unique()->numerify('######'),
            'amount' => $amount,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'description' => $this->faker->sentence(3),
            'target_iban' => $targetIban,
            'source_iban' => $sourceIban,
            'partner' => $partner,
            'type' => $type,
            'metadata' => null,
            'balance_after_transaction' => $amount,
            'account_id' => Account::first()->id,
            'duplicate_identifier' => $identifier,
            'original_amount' => $identifierFields['amount'],
            'original_currency' => $identifierFields['currency'],
            'original_booked_date' => $identifierFields['booked_date'],
            'original_source_iban' => $identifierFields['source_iban'],
            'original_target_iban' => $identifierFields['target_iban'],
            'original_partner' => $identifierFields['partner'],
        ];
    }
}
