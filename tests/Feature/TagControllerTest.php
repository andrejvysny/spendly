<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_tags_index(): void
    {
        $this->get('/tags')->assertRedirect('/login');
    }

    public function test_user_can_create_tag(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/tags', [
                'name' => 'Food',
                'color' => '#ffffff',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tags', [
            'user_id' => $user->id,
            'name' => 'Food',
        ]);
    }

    public function test_user_can_update_own_tag(): void
    {
        $user = User::factory()->create();
        $tag = $user->tags()->create(['name' => 'Old']);

        $this->actingAs($user)
            ->put("/tags/{$tag->id}", [
                'name' => 'New',
                'color' => '#000000',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'New',
        ]);
    }

    public function test_user_cannot_update_other_users_tag(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tag = $other->tags()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->put("/tags/{$tag->id}", ['name' => 'Fail'])
            ->assertForbidden();
    }

    public function test_user_can_delete_tag(): void
    {
        $user = User::factory()->create();
        $tag = $user->tags()->create(['name' => 'Temp']);

        $this->actingAs($user)
            ->delete("/tags/{$tag->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_user_cannot_delete_other_users_tag(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tag = $other->tags()->create(['name' => 'Other']);

        $this->actingAs($user)
            ->delete("/tags/{$tag->id}")
            ->assertForbidden();
    }
}
