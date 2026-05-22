<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['is_superadmin' => true, 'email_verified_at' => now()]);
        $this->regularUser = User::factory()->create(['is_superadmin' => false, 'email_verified_at' => now()]);
    }

    // -----------------------------------------------------------------------
    // admin.index
    // -----------------------------------------------------------------------

    public function test_unauthenticated_user_redirected_to_login_for_admin_index(): void
    {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    public function test_non_superadmin_gets_403_on_admin_index(): void
    {
        $this->actingAs($this->regularUser)
            ->get(route('admin.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_view_admin_index(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/index'));
    }

    // -----------------------------------------------------------------------
    // admin.users (GET)
    // -----------------------------------------------------------------------

    public function test_get_users_endpoint_403_for_non_superadmin(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson(route('admin.users'))
            ->assertForbidden();
    }

    public function test_get_users_endpoint_returns_users_for_superadmin(): void
    {
        $this->actingAs($this->superadmin)
            ->getJson(route('admin.users'))
            ->assertOk()
            ->assertJsonStructure(['users' => [['id', 'name', 'email']]]);
    }

    // -----------------------------------------------------------------------
    // admin.categories (GET)
    // -----------------------------------------------------------------------

    public function test_get_categories_403_for_non_superadmin(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson(route('admin.categories'))
            ->assertForbidden();
    }

    public function test_get_categories_returns_categories(): void
    {
        Category::factory()->for($this->regularUser)->create(['name' => 'Food', 'color' => '#ff0000']);

        $this->actingAs($this->superadmin)
            ->getJson(route('admin.categories'))
            ->assertOk()
            ->assertJsonStructure(['categories' => [['id', 'name', 'color']]]);
    }

    // -----------------------------------------------------------------------
    // admin.counterparties (GET)
    // -----------------------------------------------------------------------

    public function test_get_counterparties_403_for_non_superadmin(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson(route('admin.counterparties'))
            ->assertForbidden();
    }

    public function test_get_counterparties_returns_counterparties(): void
    {
        Counterparty::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->getJson(route('admin.counterparties'))
            ->assertOk()
            ->assertJsonStructure(['counterparties' => [['id', 'name', 'type']]]);
    }

    // -----------------------------------------------------------------------
    // admin.tags (GET)
    // -----------------------------------------------------------------------

    public function test_get_tags_403_for_non_superadmin(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson(route('admin.tags'))
            ->assertForbidden();
    }

    public function test_get_tags_returns_tags(): void
    {
        Tag::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->getJson(route('admin.tags'))
            ->assertOk()
            ->assertJsonStructure(['tags' => [['id', 'name', 'color']]]);
    }

    // -----------------------------------------------------------------------
    // admin.transactions (GET)
    // -----------------------------------------------------------------------

    public function test_transactions_endpoint_403_for_non_superadmin(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson(route('admin.transactions'))
            ->assertForbidden();
    }

    public function test_transactions_endpoint_returns_paginated_results(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        Transaction::factory()->for($account, 'account')->create();

        $this->actingAs($this->superadmin)
            ->getJson(route('admin.transactions'))
            ->assertOk()
            ->assertJsonStructure([
                'transactions' => ['data', 'current_page', 'last_page', 'total', 'has_more_pages'],
                'stats' => ['total', 'labeled', 'unlabeled', 'flagged', 'duplicates'],
                'filter_options' => ['accounts'],
                'filters',
            ]);
    }

    public function test_transactions_endpoint_applies_status_filter(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $category = Category::factory()->for($this->regularUser)->create();
        // labeled transaction
        Transaction::factory()->for($account, 'account')->create(['category_id' => $category->id]);
        // unlabeled transaction
        Transaction::factory()->for($account, 'account')->create(['category_id' => null, 'counterparty_id' => null]);

        $response = $this->actingAs($this->superadmin)
            ->getJson(route('admin.transactions', ['status' => 'labeled']))
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('transactions.total'));
    }

    // -----------------------------------------------------------------------
    // admin.bulk-label (POST)
    // -----------------------------------------------------------------------

    public function test_bulk_label_403_for_non_superadmin(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();

        $this->actingAs($this->regularUser)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['needs_manual_review' => true],
            ])
            ->assertForbidden();
    }

    public function test_bulk_label_validates_missing_transaction_ids_and_similar_group_key(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'labels' => ['needs_manual_review' => true],
                // neither transaction_ids nor similar_group_key provided
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_ids']);
    }

    public function test_bulk_label_validates_empty_labels(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => [],  // min:1
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['labels']);
    }

    public function test_bulk_label_updates_category_id(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['category_id' => null]);
        $category = Category::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['category_id' => $category->id],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'category_id' => $category->id]);
    }

    public function test_bulk_label_sets_is_transfer_type(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['type' => Transaction::TYPE_PAYMENT]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['is_transfer' => true],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'type' => Transaction::TYPE_TRANSFER]);
    }

    public function test_bulk_label_sets_is_duplicate_identifier(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['duplicate_identifier' => null]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['is_duplicate' => true],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', [
            'id' => $tx->id,
            'duplicate_identifier' => 'superadmin:' . $tx->id,
        ]);
    }

    public function test_bulk_label_clears_duplicate_identifier_when_false(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create([
            'duplicate_identifier' => 'superadmin:' . 999,
        ]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['is_duplicate' => false],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'duplicate_identifier' => null]);
    }

    public function test_bulk_label_sets_needs_manual_review(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['needs_manual_review' => false]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['needs_manual_review' => true],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'needs_manual_review' => 1]);
    }

    public function test_bulk_label_sets_is_uncertain_via_needs_manual_review(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['needs_manual_review' => false]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['is_uncertain' => true],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'needs_manual_review' => 1]);
    }

    public function test_bulk_label_clears_recurring_group_when_is_recurring_false(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['recurring_group_id' => 42]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx->id],
                'labels' => ['is_recurring' => false],
            ])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'recurring_group_id' => null]);
    }

    public function test_bulk_label_only_updates_transactions_matching_supplied_ids(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx1 = Transaction::factory()->for($account, 'account')->create(['category_id' => null]);
        $tx2 = Transaction::factory()->for($account, 'account')->create(['category_id' => null]);
        $category = Category::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.bulk-label'), [
                'transaction_ids' => [$tx1->id],
                'labels' => ['category_id' => $category->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('transactions', ['id' => $tx1->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('transactions', ['id' => $tx2->id, 'category_id' => null]);
    }

    // -----------------------------------------------------------------------
    // admin.transactions.label (PATCH)
    // -----------------------------------------------------------------------

    public function test_update_label_403_for_non_superadmin(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();

        $this->actingAs($this->regularUser)
            ->patchJson(route('admin.transactions.label', $tx), ['category_id' => null])
            ->assertForbidden();
    }

    public function test_update_label_404_for_nonexistent_transaction(): void
    {
        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', 999999), ['category_id' => null])
            ->assertNotFound();
    }

    public function test_update_label_happy_path_category(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['category_id' => null]);
        $category = Category::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['category_id' => $category->id])
            ->assertOk()
            ->assertJsonStructure(['transaction', 'message']);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'category_id' => $category->id]);
    }

    public function test_update_label_happy_path_counterparty(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['counterparty_id' => null]);
        $counterparty = Counterparty::factory()->for($this->regularUser)->create();

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['counterparty_id' => $counterparty->id])
            ->assertOk()
            ->assertJson(['message' => 'Transaction updated successfully']);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'counterparty_id' => $counterparty->id]);
    }

    public function test_update_label_sets_is_duplicate_stores_superadmin_label_in_metadata(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['duplicate_identifier' => null]);

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['is_duplicate' => true])
            ->assertOk();

        $updated = Transaction::find($tx->id);
        $this->assertSame('superadmin:' . $tx->id, $updated->duplicate_identifier);
        $this->assertTrue($updated->metadata['superadmin_labels']['is_duplicate']);
    }

    public function test_update_label_sets_is_recurring_false_clears_recurring_group(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['recurring_group_id' => 7]);

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['is_recurring' => false])
            ->assertOk();

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'recurring_group_id' => null]);
    }

    public function test_update_label_validates_invalid_type_value(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['type' => 'INVALID_TYPE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_update_label_sets_needs_manual_review(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create(['needs_manual_review' => false]);

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['needs_manual_review' => true])
            ->assertOk();

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'needs_manual_review' => 1]);
    }

    public function test_update_label_tags_sync(): void
    {
        $account = Account::factory()->for($this->regularUser)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();
        $tag = Tag::factory()->for($this->superadmin)->create();

        $this->actingAs($this->superadmin)
            ->patchJson(route('admin.transactions.label', $tx), ['tags' => [$tag->id]])
            ->assertOk();

        $this->assertDatabaseHas('transaction_tag', ['transaction_id' => $tx->id, 'tag_id' => $tag->id]);
    }
}
