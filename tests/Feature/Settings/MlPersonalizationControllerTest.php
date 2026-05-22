<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Jobs\RetrainMlModelJob;
use App\Models\MlPersonalizationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MlPersonalizationControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── edit ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_redirected_from_edit(): void
    {
        $this->get(route('ml_engine.edit'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_edit(): void
    {
        $user = User::factory()->create();

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('ml_engine.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/ml_engine')->has('settings'));
    }

    public function test_edit_creates_settings_on_first_access(): void
    {
        $user = User::factory()->create();

        $this->withoutVite();
        $this->actingAs($user)->get(route('ml_engine.edit'))->assertOk();

        $this->assertDatabaseHas('ml_personalization_settings', ['user_id' => $user->id]);
    }

    public function test_edit_returns_existing_settings_values(): void
    {
        $user = User::factory()->create();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => true,
            'retrain_threshold' => 25,
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('ml_engine.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/ml_engine')
                ->where('settings.auto_retrain', true)
                ->where('settings.retrain_threshold', 25)
            );
    }

    public function test_edit_does_not_create_duplicate_settings_on_subsequent_access(): void
    {
        $user = User::factory()->create();

        $this->withoutVite();
        $this->actingAs($user)->get(route('ml_engine.edit'));
        $this->actingAs($user)->get(route('ml_engine.edit'));

        $this->assertDatabaseCount('ml_personalization_settings', 1);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_redirected_from_update(): void
    {
        $this->patch(route('ml_engine.update'), ['auto_retrain' => true])
            ->assertRedirect(route('login'));
    }

    public function test_update_persists_valid_fields(): void
    {
        $user = User::factory()->create();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => false,
            'retrain_threshold' => 10,
        ]);

        $this->actingAs($user)->patch(route('ml_engine.update'), [
            'auto_retrain' => true,
            'retrain_threshold' => 50,
        ]);

        $this->assertDatabaseHas('ml_personalization_settings', [
            'user_id' => $user->id,
            'auto_retrain' => true,
            'retrain_threshold' => 50,
        ]);
    }

    public function test_update_validates_retrain_threshold_minimum(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('ml_engine.update'), ['retrain_threshold' => 0])
            ->assertSessionHasErrors('retrain_threshold');
    }

    public function test_update_validates_retrain_threshold_maximum(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('ml_engine.update'), ['retrain_threshold' => 1001])
            ->assertSessionHasErrors('retrain_threshold');
    }

    public function test_update_validates_retrain_threshold_must_be_integer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('ml_engine.update'), ['retrain_threshold' => 'abc'])
            ->assertSessionHasErrors('retrain_threshold');
    }

    public function test_update_validates_model_version_max_length(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('ml_engine.update'), ['model_version' => str_repeat('a', 256)])
            ->assertSessionHasErrors('model_version');
    }

    public function test_update_validates_personalization_vector_must_be_array(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('ml_engine.update'), ['personalization_vector' => 'not-an-array'])
            ->assertSessionHasErrors('personalization_vector');
    }

    public function test_update_ignores_null_values_without_overwriting(): void
    {
        $user = User::factory()->create();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => true,
            'retrain_threshold' => 20,
            'model_version' => 'v1',
        ]);

        // Send only retrain_threshold; model_version should be untouched
        $this->actingAs($user)->patch(route('ml_engine.update'), [
            'retrain_threshold' => 30,
        ]);

        $this->assertDatabaseHas('ml_personalization_settings', [
            'user_id' => $user->id,
            'retrain_threshold' => 30,
            'model_version' => 'v1',
        ]);
    }

    public function test_update_only_mutates_own_settings(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        MlPersonalizationSetting::create(['user_id' => $other->id, 'auto_retrain' => false, 'retrain_threshold' => 10]);

        $this->actingAs($user)->patch(route('ml_engine.update'), ['auto_retrain' => true]);

        // Other user's settings unchanged
        $this->assertDatabaseHas('ml_personalization_settings', [
            'user_id' => $other->id,
            'auto_retrain' => false,
        ]);
    }

    // ── retrain ───────────────────────────────────────────────────────────

    public function test_retrain_requires_auth(): void
    {
        $this->postJson(route('ml_engine.retrain'))
            ->assertUnauthorized();
    }

    public function test_retrain_dispatches_job_for_authenticated_user(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('ml_engine.retrain'))
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'queued']);

        Bus::assertDispatched(RetrainMlModelJob::class, function (RetrainMlModelJob $job) use ($user): bool {
            // The job stores userId as a private property; verify via serialized representation
            return str_contains(serialize($job), (string) $user->id);
        });
    }

    public function test_retrain_response_contains_expected_keys(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('ml_engine.retrain'));

        $response->assertJsonStructure(['success', 'status', 'message']);
    }
}
