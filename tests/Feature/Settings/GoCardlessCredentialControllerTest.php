<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoCardlessCredentialControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);
    }

    // ── edit ──────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_from_bank_data_edit(): void
    {
        $this->get(route('bank_data.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_bank_data_page(): void
    {
        $user = User::factory()->create();

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/bank_data')
                ->has('has_gocardless_credentials')
                ->has('gocardless_use_mock')
            );
    }

    public function test_edit_shows_no_credentials_for_new_user(): void
    {
        $user = User::factory()->create(['gocardless_secret_id' => null]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', false)
                ->where('gocardless_secret_id_masked', null)
            );
    }

    public function test_edit_shows_masked_id_when_credentials_set(): void
    {
        $user = User::factory()->create(['gocardless_secret_id' => 'abcd1234efgh5678']);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', true)
                // last 4 chars visible, rest masked
                ->where('gocardless_secret_id_masked', '************5678')
            );
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_guest_cannot_update_credentials(): void
    {
        $this->patch(route('bank_data.update'), [
            'gocardless_secret_id' => 'new-id',
            'gocardless_secret_key' => 'new-key',
        ])->assertRedirect(route('login'));
    }

    public function test_user_can_save_credentials(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('bank_data.update'), [
                'gocardless_secret_id' => 'new-secret-id',
                'gocardless_secret_key' => 'new-secret-key',
            ])
            ->assertRedirect(route('bank_data.edit'));

        $user->refresh();
        $this->assertSame('new-secret-id', $user->gocardless_secret_id);
        $this->assertSame('new-secret-key', $user->gocardless_secret_key);
    }

    public function test_update_does_not_overwrite_with_empty_values(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => 'existing-id',
            'gocardless_secret_key' => 'existing-key',
        ]);

        // Send empty strings — controller checks empty(), so should not overwrite
        $this->actingAs($user)
            ->patch(route('bank_data.update'), [
                'gocardless_secret_id' => '',
                'gocardless_secret_key' => '',
            ])
            ->assertRedirect(route('bank_data.edit'));

        $user->refresh();
        $this->assertSame('existing-id', $user->gocardless_secret_id);
        $this->assertSame('existing-key', $user->gocardless_secret_key);
    }

    // ── purge ────────────────────────────────────────────────────────────

    public function test_guest_cannot_purge_credentials(): void
    {
        $this->delete(route('bank_data.purgeGoCardlessCredentials'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_purge_credentials(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret-id',
            'gocardless_secret_key' => 'secret-key',
            'gocardless_access_token' => 'access-token',
            'gocardless_refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->delete(route('bank_data.purgeGoCardlessCredentials'))
            ->assertRedirect(route('bank_data.edit'));

        $user->refresh();
        $this->assertNull($user->gocardless_secret_id);
        $this->assertNull($user->gocardless_secret_key);
        $this->assertNull($user->gocardless_access_token);
        $this->assertNull($user->gocardless_refresh_token);
        $this->assertNull($user->gocardless_refresh_token_expires_at);
        $this->assertNull($user->gocardless_access_token_expires_at);
    }
}
