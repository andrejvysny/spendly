<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Create a user with an owned account and one transaction. */
    private function userWithTransaction(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $transaction = Transaction::factory()->create(['account_id' => $account->id]);

        return [$user, $account, $transaction];
    }

    // -----------------------------------------------------------------
    // Guest redirects
    // -----------------------------------------------------------------

    public function test_guest_redirected_from_transactions_index(): void
    {
        $this->get(route('transactions.index'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_filter(): void
    {
        $this->get(route('transactions.filter'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_load_more(): void
    {
        $this->get(route('transactions.load-more'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_bulk_update(): void
    {
        $this->post(route('transactions.bulk-update'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_bulk_delete(): void
    {
        $this->post(route('transactions.bulk-delete'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_bulk_type_update(): void
    {
        $this->post(route('transactions.bulk-type-update'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Authenticated — happy path
    // -----------------------------------------------------------------

    public function test_authenticated_user_can_view_transactions_index(): void
    {
        [$user] = $this->userWithTransaction();
        $this->actingAs($user)->get(route('transactions.index'))->assertOk();
    }

    public function test_authenticated_user_can_call_filter_endpoint(): void
    {
        [$user] = $this->userWithTransaction();
        $this->actingAs($user)
            ->getJson(route('transactions.filter'))
            ->assertOk()
            ->assertJsonStructure(['transactions', 'totalSummary', 'isFiltered']);
    }

    public function test_authenticated_user_can_call_load_more_endpoint(): void
    {
        [$user] = $this->userWithTransaction();
        $this->actingAs($user)
            ->getJson(route('transactions.load-more'))
            ->assertOk()
            ->assertJsonStructure(['transactions', 'monthlySummaries']);
    }

    // -----------------------------------------------------------------
    // Filter — returns only user's own transactions
    // -----------------------------------------------------------------

    public function test_filter_does_not_return_other_users_transactions(): void
    {
        [$userA] = $this->userWithTransaction();
        [$userB, , $txB] = $this->userWithTransaction();

        $response = $this->actingAs($userA)
            ->getJson(route('transactions.filter'))
            ->assertOk();

        $ids = collect($response->json('transactions.data'))->pluck('id');
        $this->assertFalse($ids->contains($txB->id));
    }

    // -----------------------------------------------------------------
    // bulkUpdate — ownership enforcement
    // -----------------------------------------------------------------

    public function test_bulk_update_silently_ignores_foreign_transactions(): void
    {
        // bulkUpdate uses a whereHas(account.user_id) filter — foreign tx is
        // excluded from the update set, so the response is still 200.
        [$userA] = $this->userWithTransaction();
        [$userB, , $txB] = $this->userWithTransaction();

        $originalCategoryId = $txB->category_id;

        $this->actingAs($userA)
            ->postJson(route('transactions.bulk-update'), [
                'transaction_ids' => [$txB->id],
                'category_id' => '999',
            ])
            ->assertOk();

        // userB's transaction must remain unchanged
        $this->assertDatabaseHas('transactions', [
            'id' => $txB->id,
            'category_id' => $originalCategoryId,
        ]);
    }

    public function test_bulk_update_updates_own_transactions(): void
    {
        [$user, $account, $tx] = $this->userWithTransaction();
        $category = \App\Models\Category::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('transactions.bulk-update'), [
                'transaction_ids' => [$tx->id],
                'category_id' => (string) $category->id,
            ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Transactions updated successfully']);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'category_id' => $category->id]);
    }

    // -----------------------------------------------------------------
    // bulkDelete — ownership enforcement
    // -----------------------------------------------------------------

    public function test_bulk_delete_silently_skips_foreign_transactions(): void
    {
        [$userA] = $this->userWithTransaction();
        [$userB, , $txB] = $this->userWithTransaction();

        $this->actingAs($userA)
            ->postJson(route('transactions.bulk-delete'), [
                'transaction_ids' => [$txB->id],
            ])
            ->assertOk();

        // txB must still exist
        $this->assertDatabaseHas('transactions', ['id' => $txB->id]);
    }

    public function test_bulk_delete_deletes_own_transactions(): void
    {
        [$user, , $tx] = $this->userWithTransaction();

        $this->actingAs($user)
            ->postJson(route('transactions.bulk-delete'), [
                'transaction_ids' => [$tx->id],
            ])
            ->assertOk()
            ->assertJsonFragment(['deleted_count' => 1]);

        $this->assertDatabaseMissing('transactions', ['id' => $tx->id]);
    }

    // -----------------------------------------------------------------
    // bulkTypeUpdate — ownership enforcement
    // -----------------------------------------------------------------

    public function test_bulk_type_update_silently_ignores_foreign_transactions(): void
    {
        [$userA] = $this->userWithTransaction();
        [$userB, , $txB] = $this->userWithTransaction();

        $originalType = $txB->type;

        $this->actingAs($userA)
            ->postJson(route('transactions.bulk-type-update'), [
                'transaction_ids' => [$txB->id],
                'type' => 'TRANSFER',
            ])
            ->assertOk();

        $this->assertDatabaseHas('transactions', ['id' => $txB->id, 'type' => $originalType]);
    }

    public function test_bulk_type_update_updates_own_transaction_type(): void
    {
        [$user, , $tx] = $this->userWithTransaction();

        $this->actingAs($user)
            ->postJson(route('transactions.bulk-type-update'), [
                'transaction_ids' => [$tx->id],
                'type' => 'PAYMENT',
            ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Transaction types updated successfully']);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'type' => 'PAYMENT']);
    }

    // -----------------------------------------------------------------
    // updateTransaction (PUT) — ownership enforcement
    // -----------------------------------------------------------------

    public function test_update_transaction_rejects_foreign_transaction(): void
    {
        [$userA] = $this->userWithTransaction();
        [, , $txB] = $this->userWithTransaction();

        $this->actingAs($userA)
            ->putJson(route('transactions.update', $txB->id), [
                'description' => 'Hacked',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('transactions', ['id' => $txB->id, 'description' => 'Hacked']);
    }

    public function test_update_transaction_updates_own_transaction(): void
    {
        [$user, , $tx] = $this->userWithTransaction();

        $this->actingAs($user)
            ->putJson(route('transactions.update', $tx->id), [
                'description' => 'Updated description',
                'note' => 'Test note',
            ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Transaction updated successfully']);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'description' => 'Updated description']);
    }

    // -----------------------------------------------------------------
    // bulkNoteUpdate — ownership
    // -----------------------------------------------------------------

    public function test_bulk_note_update_silently_ignores_foreign_transactions(): void
    {
        [$userA] = $this->userWithTransaction();
        [, , $txB] = $this->userWithTransaction();

        $this->actingAs($userA)
            ->postJson(route('transactions.bulk-note-update'), [
                'transaction_ids' => [$txB->id],
                'note' => 'injected',
                'method' => 'replace',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('transactions', ['id' => $txB->id, 'note' => 'injected']);
    }

    // -----------------------------------------------------------------
    // Two-transaction TRANSFER auto-pairing
    // -----------------------------------------------------------------

    public function test_bulk_type_update_auto_pairs_two_matching_transfer_transactions(): void
    {
        $user = User::factory()->create();
        /** @var Account $account */
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        /** @var Account $otherAccount */
        $otherAccount = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        /** @var Transaction $txCredit */
        $txCredit = Transaction::factory()->create(['account_id' => $account->id, 'amount' => 100.00]);
        /** @var Transaction $txDebit */
        $txDebit = Transaction::factory()->create(['account_id' => $otherAccount->id, 'amount' => -100.00]);

        $this->actingAs($user)
            ->postJson(route('transactions.bulk-type-update'), [
                'transaction_ids' => [$txCredit->id, $txDebit->id],
                'type' => 'TRANSFER',
            ])
            ->assertOk()
            ->assertJsonFragment(['paired' => true]);
    }

    public function test_bulk_type_update_blocks_pairing_on_same_account(): void
    {
        $user = User::factory()->create();
        /** @var Account $account */
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        /** @var Transaction $txCredit */
        $txCredit = Transaction::factory()->create(['account_id' => $account->id, 'amount' => 100.00]);
        /** @var Transaction $txDebit */
        $txDebit = Transaction::factory()->create(['account_id' => $account->id, 'amount' => -100.00]);

        $this->actingAs($user)
            ->postJson(route('transactions.bulk-type-update'), [
                'transaction_ids' => [$txCredit->id, $txDebit->id],
                'type' => 'TRANSFER',
            ])
            ->assertOk()
            ->assertJsonFragment(['paired' => false, 'pair_blocked_reason' => 'same_account']);
    }
}
