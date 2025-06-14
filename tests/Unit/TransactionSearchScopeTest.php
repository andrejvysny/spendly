<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_transactions_by_description_or_partner(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Test Account',
            'bank_name' => 'Test Bank',
            'iban' => 'DE89370400440532013000',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);

        $matchByDescription = Transaction::factory()->create([
            'description' => 'Coffee Shop',
            'partner' => 'Local Cafe',
            'account_id' => $account->id,
        ]);

        $matchByPartner = Transaction::factory()->create([
            'description' => 'Online Purchase',
            'partner' => 'Amazon',
            'account_id' => $account->id,
        ]);

        $noMatch = Transaction::factory()->create([
            'description' => 'Grocery Store',
            'partner' => 'Supermarket',
            'account_id' => $account->id,
        ]);

        $results = Transaction::query()->search('Coffee')->get();
        $this->assertTrue($results->contains($matchByDescription));
        $this->assertFalse($results->contains($matchByPartner));
        $this->assertFalse($results->contains($noMatch));

        $results = Transaction::query()->search('Amazon')->get();
        $this->assertTrue($results->contains($matchByPartner));
        $this->assertFalse($results->contains($matchByDescription));

        // Empty search term should return all records
        $results = Transaction::query()->search('')->get();
        $this->assertCount(3, $results);
    }
}
