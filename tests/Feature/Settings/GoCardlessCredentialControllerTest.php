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

    private function setInstanceCredentials(?string $secretId, ?string $secretKey): void
    {
        config([
            'services.gocardless.secret_id' => $secretId,
            'services.gocardless.secret_key' => $secretKey,
        ]);
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
                ->has('gocardless_credential_source')
                ->has('gocardless_has_instance_credentials')
                ->has('gocardless_has_user_override')
                ->has('gocardless_use_mock')
            );
    }

    public function test_edit_shows_no_credentials_for_new_user(): void
    {
        $this->setInstanceCredentials(null, null);
        $user = User::factory()->create(['gocardless_secret_id' => null]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', false)
                ->where('gocardless_credential_source', 'none')
                ->where('gocardless_has_instance_credentials', false)
                ->where('gocardless_has_user_override', false)
                ->where('gocardless_secret_id_masked', null)
            );
    }

    public function test_edit_shows_masked_id_when_user_credentials_set(): void
    {
        $this->setInstanceCredentials(null, null);
        $user = User::factory()->create([
            'gocardless_secret_id' => 'abcd1234efgh5678',
            'gocardless_secret_key' => 'some-secret-key',
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', true)
                ->where('gocardless_credential_source', 'user')
                ->where('gocardless_has_user_override', true)
                // last 4 chars visible, rest masked
                ->where('gocardless_secret_id_masked', '************5678')
            );
    }

    public function test_edit_reports_instance_source_when_only_env_credentials_exist(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', true)
                ->where('gocardless_credential_source', 'instance')
                ->where('gocardless_has_instance_credentials', true)
                ->where('gocardless_has_user_override', false)
            );
    }

    /**
     * The instance pair is shared by every account on the installation. Handing even a masked tail
     * of it to each user would leak an administrator secret across the whole tenancy.
     */
    public function test_instance_secret_is_never_exposed_to_users(): void
    {
        $this->setInstanceCredentials('instance-id-ending-9999', 'instance-key');
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->withoutVite();
        $response = $this->actingAs($user)->get(route('bank_data.edit'))->assertOk();

        $response->assertInertia(fn ($page) => $page->where('gocardless_secret_id_masked', null));
        $response->assertDontSee('9999', false);
        $response->assertDontSee('instance-key', false);
    }

    public function test_user_override_takes_precedence_over_instance_credentials(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');
        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-secret-id',
            'gocardless_secret_key' => 'user-secret-key',
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('gocardless_credential_source', 'user')
                ->where('gocardless_has_instance_credentials', true)
                ->where('gocardless_has_user_override', true)
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

        // Both blank is the "leave current credentials alone" case, not a validation error.
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

    public function test_update_rejects_secret_id_without_secret_key(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->actingAs($user)
            ->patchJson(route('bank_data.update'), [
                'gocardless_secret_id' => 'lonely-id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gocardless_secret_key');

        $user->refresh();
        $this->assertNull($user->gocardless_secret_id);
    }

    public function test_update_rejects_secret_key_without_secret_id(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->actingAs($user)
            ->patchJson(route('bank_data.update'), [
                'gocardless_secret_key' => 'lonely-key',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gocardless_secret_id');

        $user->refresh();
        $this->assertNull($user->gocardless_secret_key);
    }

    /**
     * Tokens outlive nothing but the pair that issued them, so changing the pair must take them
     * along — otherwise the next sync replays a token the new credentials never minted.
     */
    public function test_update_discards_tokens_minted_under_the_previous_credentials(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => 'old-id',
            'gocardless_secret_key' => 'old-key',
            'gocardless_token_secret_hash' => hash('sha256', 'old-id'),
            'gocardless_access_token' => 'access-token',
            'gocardless_refresh_token' => 'refresh-token',
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->patch(route('bank_data.update'), [
                'gocardless_secret_id' => 'rotated-id',
                'gocardless_secret_key' => 'rotated-key',
            ])
            ->assertRedirect(route('bank_data.edit'));

        $user->refresh();
        $this->assertSame('rotated-id', $user->gocardless_secret_id);
        $this->assertNull($user->gocardless_access_token);
        $this->assertNull($user->gocardless_refresh_token);
        $this->assertNull($user->gocardless_access_token_expires_at);
        $this->assertNull($user->gocardless_refresh_token_expires_at);
        $this->assertNull($user->gocardless_token_secret_hash);
    }

    // ── purge ────────────────────────────────────────────────────────────

    public function test_guest_cannot_purge_credentials(): void
    {
        $this->delete(route('bank_data.purgeGoCardlessCredentials'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_purge_credentials(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret-id',
            'gocardless_secret_key' => 'secret-key',
            'gocardless_token_secret_hash' => hash('sha256', 'secret-id'),
            'gocardless_access_token' => 'access-token',
            'gocardless_refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->delete(route('bank_data.purgeGoCardlessCredentials'))
            ->assertRedirect(route('bank_data.edit'))
            ->assertSessionHas('success', 'Personal credentials removed. Bank sync is no longer configured.');

        $user->refresh();
        $this->assertNull($user->gocardless_secret_id);
        $this->assertNull($user->gocardless_secret_key);
        $this->assertNull($user->gocardless_access_token);
        $this->assertNull($user->gocardless_refresh_token);
        $this->assertNull($user->gocardless_refresh_token_expires_at);
        $this->assertNull($user->gocardless_access_token_expires_at);
        $this->assertNull($user->gocardless_token_secret_hash);
    }

    public function test_purge_falls_back_to_instance_credentials_when_configured(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret-id',
            'gocardless_secret_key' => 'secret-key',
            'gocardless_token_secret_hash' => hash('sha256', 'secret-id'),
            'gocardless_access_token' => 'access-token',
            'gocardless_refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->delete(route('bank_data.purgeGoCardlessCredentials'))
            ->assertRedirect(route('bank_data.edit'))
            ->assertSessionHas(
                'success',
                'Personal credentials removed. Bank sync now uses the credentials configured by the server administrator.'
            );

        $user->refresh();
        $this->assertNull($user->gocardless_secret_id);
        $this->assertNull($user->gocardless_token_secret_hash);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('bank_data.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('has_gocardless_credentials', true)
                ->where('gocardless_credential_source', 'instance')
            );
    }
}
