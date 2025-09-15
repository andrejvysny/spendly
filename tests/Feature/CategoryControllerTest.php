<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_categories_index(): void
    {
        $this->get('/categories')->assertRedirect('/login');
    }

    public function test_user_can_view_categories(): void
    {
        $user = User::factory()->create();
        $user->categories()->create(['name' => 'Food']);

        $this->actingAs($user)
            ->get('/categories')
            ->assertOk();
    }

    public function test_user_can_create_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/categories', [
                'name' => 'Groceries',
                'description' => 'Food items',
                'color' => '#ffffff',
                'icon' => 'shopping-cart',
                'parent_category_id' => null,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Groceries',
        ]);
    }

    public function test_user_can_update_own_category(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Old']);

        $response = $this->actingAs($user)
            ->put("/categories/{$category->id}", [
                'name' => 'New',
                'description' => 'Updated',
                'color' => '#000000',
                'icon' => null,
                'parent_category_id' => null,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'New',
            'description' => 'Updated',
        ]);
    }

    public function test_user_cannot_update_other_users_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->put("/categories/{$category->id}", ['name' => 'Fail'])
            ->assertForbidden();
    }

    public function test_user_can_delete_category_and_replace_transactions(): void
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
        $toDelete = $user->categories()->create(['name' => 'Old']);
        $replacement = $user->categories()->create(['name' => 'New']);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'category_id' => $toDelete->id,
        ]);

        $this->actingAs($user)
            ->delete("/categories/{$toDelete->id}", [
                'replacement_action' => 'replace',
                'replacement_category_id' => $replacement->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('categories', ['id' => $toDelete->id]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'category_id' => $replacement->id,
        ]);
    }

    public function test_user_cannot_delete_other_users_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->delete("/categories/{$category->id}")
            ->assertForbidden();
    }
}
