<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_an_account(): void
    {
        $account = Account::factory()->create();

        $this->get("/accounts/{$account->id}")->assertRedirect('/login');
    }

    public function test_owner_can_view_their_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get("/accounts/{$account->id}")
            ->assertOk();
    }

    public function test_user_cannot_view_another_users_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get("/accounts/{$account->id}")
            ->assertForbidden();
    }

    public function test_owner_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->delete("/accounts/{$account->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }

    public function test_user_cannot_delete_another_users_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->delete("/accounts/{$account->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_owner_can_update_sync_options_of_their_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/accounts/{$account->id}/sync-options", [
                'update_existing' => true,
                'force_max_date_range' => false,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(
            ['update_existing' => true, 'force_max_date_range' => false],
            $account->fresh()->sync_options
        );
    }

    public function test_user_cannot_update_sync_options_of_another_users_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->putJson("/accounts/{$account->id}/sync-options", [
                'update_existing' => true,
            ])
            ->assertForbidden();

        $this->assertNull($account->fresh()->sync_options);
    }

    public function test_account_user_id_is_not_mass_assignable(): void
    {
        $account = new Account(['user_id' => 99]);

        $this->assertNull($account->user_id);
    }

    public function test_store_ignores_user_id_supplied_in_request_and_uses_authenticated_user(): void
    {
        $user = User::factory()->create();
        $attacker = User::factory()->create();

        $this->actingAs($user)
            ->post('/accounts', [
                'name' => 'New Account',
                'type' => 'checking',
                'currency' => 'EUR',
                'balance' => 0,
                'user_id' => $attacker->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounts', [
            'name' => 'New Account',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('accounts', [
            'name' => 'New Account',
            'user_id' => $attacker->id,
        ]);
    }
}
