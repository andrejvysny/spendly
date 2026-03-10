<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounterpartyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_counterparties_index(): void
    {
        $this->get('/counterparties')->assertRedirect('/login');
    }

    public function test_user_can_create_counterparty(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/counterparties', [
                'name' => 'Shop',
                'description' => 'Local shop',
                'logo' => null,
                'type' => 'merchant',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('counterparties', [
            'user_id' => $user->id,
            'name' => 'Shop',
            'type' => 'merchant',
        ]);
    }

    public function test_user_can_create_counterparty_with_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/counterparties', [
                'name' => 'John Doe',
                'description' => 'Friend',
                'type' => 'person',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('counterparties', [
            'user_id' => $user->id,
            'name' => 'John Doe',
            'type' => 'person',
        ]);
    }

    public function test_user_can_update_own_counterparty(): void
    {
        $user = User::factory()->create();
        $counterparty = $user->counterparties()->create(['name' => 'Old']);

        $this->actingAs($user)
            ->put("/counterparties/{$counterparty->id}", [
                'name' => 'New',
                'description' => 'Updated',
                'logo' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'name' => 'New',
        ]);
    }

    public function test_user_cannot_update_other_users_counterparty(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $counterparty = $other->counterparties()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->put("/counterparties/{$counterparty->id}", ['name' => 'Fail'])
            ->assertForbidden();
    }

    public function test_user_can_delete_counterparty_and_replace_transactions(): void
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
        $toDelete = $user->counterparties()->create(['name' => 'Old']);
        $replacement = $user->counterparties()->create(['name' => 'New']);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'counterparty_id' => $toDelete->id,
        ]);

        $this->actingAs($user)
            ->delete("/counterparties/{$toDelete->id}", [
                'replacement_action' => 'replace',
                'replacement_counterparty_id' => $replacement->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('counterparties', ['id' => $toDelete->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'counterparty_id' => $replacement->id,
        ]);
    }

    public function test_user_cannot_delete_other_users_counterparty(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $counterparty = $other->counterparties()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->delete("/counterparties/{$counterparty->id}")
            ->assertForbidden();
    }
}
