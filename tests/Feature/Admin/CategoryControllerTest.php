<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
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

    public function test_unauthenticated_user_redirected(): void
    {
        $this->postJson(route('admin.categories.store'), ['name' => 'Foo'])
            ->assertUnauthorized();
    }

    public function test_non_superadmin_gets_403(): void
    {
        $this->actingAs($this->regularUser)
            ->postJson(route('admin.categories.store'), ['name' => 'Foo'])
            ->assertForbidden();
    }

    public function test_superadmin_creates_category_for_themselves_when_no_target_user(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [
                'name' => 'Groceries',
                'color' => '#22c55e',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['category' => ['id', 'name', 'color']]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Groceries',
            'user_id' => $this->superadmin->id,
            'color' => '#22c55e',
        ]);
    }

    public function test_superadmin_creates_category_for_target_user(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [
                'name' => 'Dining',
                'target_user_id' => $this->regularUser->id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => 'Dining',
            'user_id' => $this->regularUser->id,
        ]);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_target_user_id_must_exist(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [
                'name' => 'Foo',
                'target_user_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_user_id']);
    }

    public function test_parent_category_id_must_exist_when_provided(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [
                'name' => 'Sub',
                'parent_category_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_category_id']);
    }

    public function test_parent_category_id_links_when_valid(): void
    {
        $parent = Category::create([
            'user_id' => $this->regularUser->id,
            'name' => 'Food',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.categories.store'), [
                'name' => 'Restaurants',
                'parent_category_id' => $parent->id,
                'target_user_id' => $this->regularUser->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => 'Restaurants',
            'parent_category_id' => $parent->id,
            'user_id' => $this->regularUser->id,
        ]);
    }
}
