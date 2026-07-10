<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Transactions;

use App\Models\Account;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionBulkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionBulkServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionBulkService $service;

    private User $user;

    private User $otherUser;

    private Account $account;

    private Account $otherAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(TransactionBulkService::class);
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);
        $this->otherAccount = Account::factory()->create(['user_id' => $this->otherUser->id, 'currency' => 'EUR']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(Account $account, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'account_id' => $account->id,
            'transaction_id' => 'tx-'.uniqid('', true),
            'amount' => -10.0,
            'currency' => 'EUR',
            'booked_date' => '2026-05-01',
            'processed_date' => '2026-05-01',
            'description' => 'test',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
        ], $overrides));
    }

    public function test_owned_transactions_silently_drops_foreign_ids(): void
    {
        $own = $this->makeTransaction($this->account);
        $foreign = $this->makeTransaction($this->otherAccount);

        $result = $this->service->ownedTransactions($this->user, [$own->id, $foreign->id]);

        $this->assertCount(1, $result);
        /** @var Transaction $first */
        $first = $result->first();
        $this->assertSame($own->id, $first->id);
    }

    public function test_owned_transactions_returns_empty_for_empty_input(): void
    {
        $this->assertCount(0, $this->service->ownedTransactions($this->user, []));
    }

    public function test_apply_assignments_updates_only_provided_fields(): void
    {
        $tx = $this->makeTransaction($this->account, ['category_id' => null, 'counterparty_id' => null]);
        $category = $this->user->categories()->create(['name' => 'Food']);

        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);
        $updated = $this->service->applyAssignments($owned, ['category_id' => (string) $category->id], $this->user);

        $this->assertSame(1, $updated);
        $tx->refresh();
        $this->assertSame($category->id, $tx->category_id);
    }

    public function test_apply_assignments_empty_string_maps_to_null(): void
    {
        $category = $this->user->categories()->create(['name' => 'Food']);
        $tx = $this->makeTransaction($this->account, ['category_id' => $category->id]);

        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);
        $this->service->applyAssignments($owned, ['category_id' => ''], $this->user);

        $tx->refresh();
        $this->assertNull($tx->category_id);
    }

    public function test_apply_notes_replace_mode_overwrites_existing(): void
    {
        $tx = $this->makeTransaction($this->account, ['note' => 'old']);
        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);

        $rows = $this->service->applyNotes($owned, 'new', 'replace');

        $this->assertSame('new', $rows[0]['note']);
        $tx->refresh();
        $this->assertSame('new', $tx->note);
    }

    public function test_apply_notes_append_mode_concatenates(): void
    {
        $tx = $this->makeTransaction($this->account, ['note' => 'old']);
        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);

        $this->service->applyNotes($owned, 'new', 'append');

        $tx->refresh();
        $this->assertSame("old\nnew", $tx->note);
    }

    public function test_apply_tags_set_mode_replaces_tags(): void
    {
        $tag1 = Tag::create(['user_id' => $this->user->id, 'name' => 'Food']);
        $tag2 = Tag::create(['user_id' => $this->user->id, 'name' => 'Travel']);
        $tx = $this->makeTransaction($this->account);
        $tx->tags()->attach($tag1->id);

        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);
        $this->service->applyTags($owned, [$tag2->id], 'set');

        $tx->refresh()->load('tags');
        $this->assertCount(1, $tx->tags);
        /** @var Tag $firstTag */
        $firstTag = $tx->tags->first();
        $this->assertSame($tag2->id, $firstTag->id);
    }

    public function test_apply_tags_add_mode_keeps_existing(): void
    {
        $tag1 = Tag::create(['user_id' => $this->user->id, 'name' => 'Food']);
        $tag2 = Tag::create(['user_id' => $this->user->id, 'name' => 'Travel']);
        $tx = $this->makeTransaction($this->account);
        $tx->tags()->attach($tag1->id);

        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);
        $this->service->applyTags($owned, [$tag2->id], 'add');

        $tx->refresh()->load('tags');
        $this->assertCount(2, $tx->tags);
    }

    public function test_apply_type_auto_pairs_two_inverse_transactions(): void
    {
        $debit = $this->makeTransaction($this->account, ['amount' => -100.0]);
        $credit = $this->makeTransaction($this->account, ['amount' => 100.0]);

        $owned = $this->service->ownedTransactions($this->user, [$debit->id, $credit->id]);
        $result = $this->service->applyType($owned, Transaction::TYPE_TRANSFER, false);

        $this->assertTrue($result['paired']);
        $debit->refresh();
        $credit->refresh();
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame($debit->id, $credit->transfer_pair_transaction_id);
    }

    public function test_apply_type_does_not_pair_when_sums_dont_cancel(): void
    {
        $a = $this->makeTransaction($this->account, ['amount' => -100.0]);
        $b = $this->makeTransaction($this->account, ['amount' => 50.0]);

        $owned = $this->service->ownedTransactions($this->user, [$a->id, $b->id]);
        $result = $this->service->applyType($owned, Transaction::TYPE_TRANSFER, false);

        $this->assertFalse($result['paired']);
    }

    public function test_apply_type_clear_transfer_pair_nulls_partner(): void
    {
        $a = $this->makeTransaction($this->account, ['amount' => -100.0]);
        $b = $this->makeTransaction($this->account, ['amount' => 100.0]);
        $a->update(['transfer_pair_transaction_id' => $b->id]);
        $b->update(['transfer_pair_transaction_id' => $a->id]);

        $owned = $this->service->ownedTransactions($this->user, [$a->id]);
        $this->service->applyType($owned, Transaction::TYPE_PAYMENT, true);

        $a->refresh();
        $b->refresh();
        $this->assertNull($a->transfer_pair_transaction_id);
        $this->assertNull($b->transfer_pair_transaction_id);
    }

    public function test_delete_all_clears_partner_pair_and_detaches_tags(): void
    {
        $tag = Tag::create(['user_id' => $this->user->id, 'name' => 'Food']);
        $a = $this->makeTransaction($this->account);
        $b = $this->makeTransaction($this->account);
        $a->tags()->attach($tag->id);
        $a->update(['transfer_pair_transaction_id' => $b->id]);

        $owned = $this->service->ownedTransactions($this->user, [$a->id]);
        $deleted = $this->service->deleteAll($owned);

        $this->assertSame([$a->id], $deleted);
        $this->assertDatabaseMissing('transactions', ['id' => $a->id]);
        $b->refresh();
        $this->assertNull($b->transfer_pair_transaction_id);
    }

    public function test_mixed_ownership_only_affects_caller_transactions(): void
    {
        $own = $this->makeTransaction($this->account);
        $foreign = $this->makeTransaction($this->otherAccount);
        $category = $this->user->categories()->create(['name' => 'Food']);

        $owned = $this->service->ownedTransactions($this->user, [$own->id, $foreign->id]);
        $this->service->applyAssignments($owned, ['category_id' => (string) $category->id], $this->user);

        $own->refresh();
        $foreign->refresh();
        $this->assertSame($category->id, $own->category_id);
        $this->assertNull($foreign->category_id);
    }

    public function test_apply_assignments_drops_foreign_owned_target_category(): void
    {
        // A category owned by another user must never be applied to the caller's
        // transactions — it is silently dropped, leaving the field unchanged.
        $tx = $this->makeTransaction($this->account, ['category_id' => null]);
        $foreignCategory = $this->otherUser->categories()->create(['name' => 'Foreign']);

        $owned = $this->service->ownedTransactions($this->user, [$tx->id]);
        $this->service->applyAssignments($owned, ['category_id' => (string) $foreignCategory->id], $this->user);

        $tx->refresh();
        $this->assertNull($tx->category_id);
    }
}
