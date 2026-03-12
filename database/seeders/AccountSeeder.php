<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->firstOrFail();

        $accounts = [
            [
                'name' => 'Main Checking',
                'bank_name' => 'Demo Bank',
                'iban' => 'DE89370400440532013000',
                'type' => 'checking',
                'currency' => 'EUR',
                'balance' => 3200.00,
                'opening_balance' => 3200.00,
                'is_gocardless_synced' => false,
            ],
            [
                'name' => 'Savings',
                'bank_name' => 'Demo Bank',
                'iban' => 'DE89370400440532013001',
                'type' => 'savings',
                'currency' => 'EUR',
                'balance' => 12000.00,
                'opening_balance' => 12000.00,
                'is_gocardless_synced' => false,
            ],
            [
                'name' => 'Credit Card',
                'bank_name' => 'Demo Bank',
                'iban' => 'DE89370400440532013002',
                'type' => 'credit',
                'currency' => 'EUR',
                'balance' => -800.00,
                'opening_balance' => -800.00,
                'is_gocardless_synced' => false,
            ],
            [
                'name' => 'Investment',
                'bank_name' => 'Demo Bank',
                'iban' => 'DE89370400440532013003',
                'type' => 'investment',
                'currency' => 'EUR',
                'balance' => 20000.00,
                'opening_balance' => 20000.00,
                'is_gocardless_synced' => false,
            ],
            [
                'name' => 'Revolut USD',
                'bank_name' => 'Revolut',
                'iban' => 'LT683250013083708433',
                'type' => 'checking',
                'currency' => 'USD',
                'balance' => 1200.00,
                'opening_balance' => 1200.00,
                'is_gocardless_synced' => false,
            ],
            [
                'name' => 'Cash Wallet',
                'bank_name' => null,
                'iban' => null,
                'type' => 'checking',
                'currency' => 'EUR',
                'balance' => 200.00,
                'opening_balance' => 200.00,
                'is_gocardless_synced' => false,
            ],
        ];

        foreach ($accounts as $account) {
            Account::create([
                'name' => $account['name'],
                'bank_name' => $account['bank_name'],
                'iban' => $account['iban'],
                'type' => $account['type'],
                'currency' => $account['currency'],
                'balance' => $account['balance'],
                'opening_balance' => $account['opening_balance'],
                'is_gocardless_synced' => $account['is_gocardless_synced'],
                'user_id' => $user->id,
            ]);
        }
    }
}
