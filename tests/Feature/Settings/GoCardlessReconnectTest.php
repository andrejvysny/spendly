<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Reconnect is the escape hatch from an expired 90-day consent. It must produce a link that
 * walks the *same* callback path a first-time connection does — anything bespoke here would
 * be a second, untested way to link a bank.
 */
class GoCardlessReconnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);

        $this->app->forgetInstance(GoCardlessClientFactoryInterface::class);
        $this->app->forgetInstance(GoCardlessService::class);
        (new GoCardlessServiceProvider($this->app))->register();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staleConnection(?User $owner = null, array $attributes = []): GoCardlessRequisition
    {
        return GoCardlessRequisition::factory()
            ->linked()
            ->for($owner ?? $this->user)
            ->create(array_merge([
                'institution_id' => 'MOCK_INSTITUTION',
                'requisition_id' => 'req_stale',
                'accounts' => ['mock_account_1'],
                // Linked, but the access window has already closed — exactly the state the
                // daily consent check produces just before it flips the row to expired.
                'access_valid_until' => now()->subDay(),
            ], $attributes));
    }

    private function reconnectUrl(GoCardlessRequisition $row): string
    {
        return "/api/bank-data/gocardless/requisitions/{$row->id}/reconnect";
    }

    // ── authorization ─────────────────────────────────────────────────────

    public function test_guest_cannot_start_a_reconnect(): void
    {
        $row = $this->staleConnection();

        $this->postJson($this->reconnectUrl($row))->assertUnauthorized();
    }

    public function test_user_cannot_reconnect_another_users_connection(): void
    {
        $row = $this->staleConnection(User::factory()->create());

        $this->actingAs($this->user)
            ->postJson($this->reconnectUrl($row))
            ->assertForbidden();

        $this->assertSame(1, GoCardlessRequisition::count());
    }

    public function test_unknown_row_is_a_404(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions/9999/reconnect')
            ->assertNotFound();
    }

    public function test_reconnect_route_is_rate_limited_as_a_write(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->getName() === 'bank_data.gocardless.reconnect');

        $this->assertNotNull($route);
        $this->assertContains('throttle:gocardless-write', $route->gatherMiddleware());
    }

    // ── happy path ────────────────────────────────────────────────────────

    public function test_owner_gets_a_link_and_a_fresh_pending_row_for_the_same_institution(): void
    {
        $row = $this->staleConnection(null, ['return_to' => 'accounts']);

        $response = $this->actingAs($this->user)->postJson($this->reconnectUrl($row));

        $response->assertOk()->assertJsonStructure(['link']);

        $fresh = GoCardlessRequisition::where('id', '!=', $row->id)->firstOrFail();
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $fresh->status);
        $this->assertSame('MOCK_INSTITUTION', $fresh->getAttribute('institution_id'));
        $this->assertSame($this->user->id, $fresh->getAttribute('user_id'));
        // The reconnect must land the user back where they started it from.
        $this->assertSame('accounts', $fresh->getAttribute('return_to'));

        // The old row is still the best record of the connection until the new one completes.
        $row->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);
    }

    public function test_connection_without_an_institution_cannot_be_reconnected(): void
    {
        $row = $this->staleConnection(null, ['institution_id' => '']);

        $this->actingAs($this->user)
            ->postJson($this->reconnectUrl($row))
            ->assertStatus(422);

        $this->assertSame(1, GoCardlessRequisition::count());
    }

    // ── full loop ─────────────────────────────────────────────────────────

    public function test_completing_the_reconnect_supersedes_the_old_row_and_repairs_the_account(): void
    {
        $row = $this->staleConnection();

        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_requisition_id' => $row->id,
            'gocardless_needs_reconnect' => true,
        ]);

        $link = $this->actingAs($this->user)
            ->postJson($this->reconnectUrl($row))
            ->assertOk()
            ->json('link');

        $this->assertIsString($link);

        // The mock hands back the signed callback URL the bank would have redirected to.
        $this->get($link)->assertRedirect(route('bank_data.edit'))->assertSessionHas('success');

        $fresh = GoCardlessRequisition::where('id', '!=', $row->id)->firstOrFail();
        $row->refresh();
        $account->refresh();

        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $fresh->status);
        $this->assertSame(GoCardlessRequisitionStatus::REPLACED, $row->status);

        // Repaired, not duplicated: the same account now points at the new authorization.
        $this->assertSame($fresh->id, $account->gocardless_requisition_id);
        $this->assertFalse((bool) $account->gocardless_needs_reconnect);
        $this->assertSame(1, Account::where('user_id', $this->user->id)
            ->where('gocardless_account_id', 'mock_account_1')
            ->count());
    }

    public function test_abandoned_reconnect_leaves_the_original_connection_intact(): void
    {
        $row = $this->staleConnection();
        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_requisition_id' => $row->id,
            'gocardless_needs_reconnect' => true,
        ]);

        $this->actingAs($this->user)->postJson($this->reconnectUrl($row))->assertOk();

        // User closes the bank tab without authorizing: nothing about the old link may change.
        $row->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);
        $this->assertSame($row->id, $account->refresh()->gocardless_requisition_id);
        $this->assertTrue((bool) $account->gocardless_needs_reconnect);
    }

    // ── list payload ──────────────────────────────────────────────────────

    public function test_requisition_list_exposes_the_row_id_the_reconnect_endpoint_binds_on(): void
    {
        $row = $this->staleConnection();

        $response = $this->actingAs($this->user)->getJson('/api/bank-data/gocardless/requisitions');

        $response->assertOk()
            ->assertJsonPath('results.0.row_id', $row->id)
            ->assertJsonPath('results.0.needs_reconnect', true)
            ->assertJsonPath('results.0.local_status', 'linked');
    }
}
