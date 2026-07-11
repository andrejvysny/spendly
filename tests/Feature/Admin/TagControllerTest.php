<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagControllerTest extends TestCase
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

    public function test_non_superadmin_gets_403(): void
    {
        $this->actingAs($this->regularUser)
            ->postJson(route('admin.tags.store'), [
                'name' => 'vacation',
                'target_user_id' => $this->regularUser->id,
            ])
            ->assertForbidden();
    }

    public function test_creates_tag_for_target_user(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.tags.store'), [
                'name' => 'vacation',
                'color' => '#f59e0b',
                'target_user_id' => $this->regularUser->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('tag.name', 'vacation');

        $this->assertDatabaseHas('tags', [
            'name' => 'vacation',
            'color' => '#f59e0b',
            'user_id' => $this->regularUser->id,
        ]);
    }

    public function test_target_user_id_required(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.tags.store'), ['name' => 'vacation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_user_id']);
    }
}
