<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounterpartyControllerTest extends TestCase
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
            ->postJson(route('admin.counterparties.store'), [
                'name' => 'Lidl',
                'target_user_id' => $this->regularUser->id,
            ])
            ->assertForbidden();
    }

    public function test_creates_counterparty_for_target_user_with_type(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.counterparties.store'), [
                'name' => 'ACME Software',
                'type' => 'employer',
                'target_user_id' => $this->regularUser->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('counterparty.name', 'ACME Software')
            ->assertJsonPath('counterparty.type', 'employer');

        $this->assertDatabaseHas('counterparties', [
            'name' => 'ACME Software',
            'type' => 'employer',
            'user_id' => $this->regularUser->id,
        ]);
    }

    public function test_type_defaults_to_merchant(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.counterparties.store'), [
                'name' => 'Lidl',
                'target_user_id' => $this->regularUser->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('counterparties', [
            'name' => 'Lidl',
            'type' => 'merchant',
            'user_id' => $this->regularUser->id,
        ]);
    }

    public function test_invalid_type_rejected(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.counterparties.store'), [
                'name' => 'Lidl',
                'type' => 'alien',
                'target_user_id' => $this->regularUser->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_target_user_id_required(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('admin.counterparties.store'), ['name' => 'Lidl'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_user_id']);
    }
}
