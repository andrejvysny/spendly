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

        return [
            'transaction_id' => 'TRX-'.$this->faker->unique()->numerify('######'),
            'amount' => $amount,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'description' => $this->faker->sentence(3),
            'target_iban' => $type === 'TRANSFER' ? $this->faker->iban('SK') : null,
            'source_iban' => $type === 'TRANSFER' ? $this->faker->iban('SK') : null,
            'partner' => $this->faker->company(),
            'type' => $type,
            'metadata' => null,
            'balance_after_transaction' => $amount,
            'account_id' => Account::first()->id,
        ];
    }
}
