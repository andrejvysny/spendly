<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_merchants_index(): void
    {
        $this->get('/merchants')->assertRedirect('/login');
    }

    public function test_user_can_create_merchant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/merchants', [
                'name' => 'Shop',
                'description' => 'Local shop',
                'logo' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('merchants', [
            'user_id' => $user->id,
            'name' => 'Shop',
        ]);
    }

    public function test_user_can_update_own_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = $user->merchants()->create(['name' => 'Old']);

        $this->actingAs($user)
            ->put("/merchants/{$merchant->id}", [
                'name' => 'New',
                'description' => 'Updated',
                'logo' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'name' => 'New',
        ]);
    }

    public function test_user_cannot_update_other_users_merchant(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $merchant = $other->merchants()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->put("/merchants/{$merchant->id}", ['name' => 'Fail'])
            ->assertForbidden();
    }

    public function test_user_can_delete_merchant_and_replace_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Test',
            'bank_name' => 'Bank',
            'iban' => 'DE89370400440532013000',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);
        $toDelete = $user->merchants()->create(['name' => 'Old']);
        $replacement = $user->merchants()->create(['name' => 'New']);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'merchant_id' => $toDelete->id,
        ]);

        $this->actingAs($user)
            ->delete("/merchants/{$toDelete->id}", [
                'replacement_action' => 'replace',
                'replacement_merchant_id' => $replacement->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('merchants', ['id' => $toDelete->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'merchant_id' => $replacement->id,
        ]);
    }

    public function test_user_cannot_delete_other_users_merchant(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $merchant = $other->merchants()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->delete("/merchants/{$merchant->id}")
            ->assertForbidden();
    }
}
