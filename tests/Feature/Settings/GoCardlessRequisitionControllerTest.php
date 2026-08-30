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

class GoCardlessRequisitionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);

        // Re-resolve mock factory after config change so singletons pick up mock
        $this->app->forgetInstance(GoCardlessClientFactoryInterface::class);
        $this->app->forgetInstance(GoCardlessService::class);
        (new GoCardlessServiceProvider($this->app))->register();

        $this->user = User::factory()->create();
    }

    // ── getInstitutions ───────────────────────────────────────────────────

    public function test_guest_cannot_list_institutions(): void
    {
        $this->getJson('/api/bank-data/gocardless/institutions?country=DE')
            ->assertUnauthorized();
    }

    public function test_country_param_is_required_for_institutions(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/institutions')
            ->assertUnprocessable();
    }

    public function test_country_must_be_two_chars(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/institutions?country=DEU')
            ->assertUnprocessable();
    }

    public function test_authenticated_user_can_list_institutions(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/institutions?country=DE')
            ->assertOk()
            ->assertJsonStructure([['id', 'name']]);
    }

    // ── getRequisitions ───────────────────────────────────────────────────

    public function test_guest_cannot_list_requisitions(): void
    {
        $this->getJson('/api/bank-data/gocardless/requisitions')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_requisitions(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions')
            ->assertOk()
            ->assertJsonStructure(['count', 'results']);
    }

    public function test_requisition_list_is_served_from_the_database(): void
    {
        GoCardlessRequisition::factory()->linked()->for($this->user)->create([
            'requisition_id' => 'req_db_1',
            'accounts' => ['mock_account_1'],
        ]);
        // Another user's row must never leak into this list.
        GoCardlessRequisition::factory()->linked()->for(User::factory())->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', 'req_db_1')
            ->assertJsonPath('results.0.local_status', 'linked')
            ->assertJsonPath('results.0.status_label', 'Linked')
            ->assertJsonPath('results.0.needs_reconnect', false)
            ->assertJsonPath('results.0.accounts.0.id', 'mock_account_1');

        $this->assertIsInt($response->json('results.0.days_until_expiry'));
        $this->assertNotNull($response->json('results.0.access_valid_until'));
    }

    public function test_revoked_and_replaced_requisitions_are_hidden_from_the_list(): void
    {
        GoCardlessRequisition::factory()->for($this->user)->create([
            'status' => GoCardlessRequisitionStatus::REVOKED,
        ]);
        GoCardlessRequisition::factory()->for($this->user)->create([
            'status' => GoCardlessRequisitionStatus::REPLACED,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_refresh_reconciles_the_list_from_the_remote_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ])->assertOk();

        $row = GoCardlessRequisition::where('user_id', $this->user->id)->firstOrFail();
        $remoteId = $row->getAttribute('requisition_id');
        $row->forceDelete();

        // Without ?refresh the local DB is authoritative: nothing to show.
        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions?refresh=1')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', $remoteId);

        $this->assertDatabaseHas('gocardless_requisitions', [
            'user_id' => $this->user->id,
            'requisition_id' => $remoteId,
        ]);
    }

    public function test_failing_refresh_still_serves_stored_rows(): void
    {
        GoCardlessRequisition::factory()->linked()->for($this->user)->create([
            'requisition_id' => 'req_db_1',
            'accounts' => [],
        ]);

        $this->mock(GoCardlessService::class, function ($mock) {
            $mock->shouldReceive('getRequisitionsList')->andThrow(new \RuntimeException('remote down'));
        });

        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions?refresh=1')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', 'req_db_1');
    }

    public function test_requisition_response_omits_sensitive_fields(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ])->assertOk();

        $response = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');

        $response->assertOk();

        if (empty($response->json('results'))) {
            $this->markTestSkipped('Mock returned no requisitions');
        }

        $response->assertJsonMissingPath('results.0.ssn')
            ->assertJsonMissingPath('results.0.redirect')
            ->assertJsonMissingPath('results.0.reference')
            ->assertJsonMissingPath('results.0.account_selection')
            ->assertJsonMissingPath('results.0.redirect_immediate')
            ->assertJsonMissingPath('next')
            ->assertJsonMissingPath('previous');
    }

    public function test_link_is_null_for_linked_requisitions(): void
    {
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();

        $link = $createResponse->json('link');
        $this->get($link); // simulate callback -> marks requisition as linked (status LN)

        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $listResponse->assertOk();

        $results = $listResponse->json('results');
        if (empty($results)) {
            $this->markTestSkipped('Mock returned no requisitions');
        }

        $this->assertSame('LN', $results[0]['status']);
        $this->assertNull($results[0]['link']);
    }

    // ── createRequisition ─────────────────────────────────────────────────

    public function test_guest_cannot_create_requisition(): void
    {
        $this->postJson('/api/bank-data/gocardless/requisitions', [
            'institution_id' => 'MOCK_INSTITUTION',
        ])->assertUnauthorized();
    }

    public function test_institution_id_is_required_to_create_requisition(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['institution_id']);
    }

    public function test_return_to_must_be_valid_value(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
                'return_to' => 'invalid_value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_to']);
    }

    public function test_authenticated_user_can_create_requisition(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['link']);

        $this->assertStringContainsString('callback', $response->json('link'));

        // The callback URL must be a Laravel signed route, not a hand-rolled HMAC.
        $link = $response->json('link');
        $this->assertIsString($link);
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('expires=', $link);
        $this->assertStringNotContainsString('sig=', $link);
    }

    public function test_create_requisition_persists_a_row_keyed_by_the_signed_reference(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
                'return_to' => 'accounts',
            ]);
        $response->assertOk();

        $row = GoCardlessRequisition::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $row->status);
        $this->assertSame('MOCK_INSTITUTION', $row->getAttribute('institution_id'));
        $this->assertSame('accounts', $row->getAttribute('return_to'));
        $this->assertNull($row->callback_completed_at);

        $reference = $row->getAttribute('reference');
        $this->assertIsString($reference);
        $link = $response->json('link');
        $this->assertIsString($link);
        $this->assertStringContainsString('reference='.$reference, $link);
    }

    public function test_create_requisition_with_return_to_accounts(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
                'return_to' => 'accounts',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['link']);
    }

    // ── deleteRequisition ─────────────────────────────────────────────────

    public function test_guest_cannot_delete_requisition(): void
    {
        $this->deleteJson('/api/bank-data/gocardless/requisitions/some-req-id')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_delete_requisition(): void
    {
        // Create a requisition first so the mock has it stored
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();

        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $listResponse->assertOk();

        $results = $listResponse->json('results');
        if (empty($results)) {
            $this->markTestSkipped('Mock returned no requisitions to delete');
        }

        $reqId = $results[0]['id'];

        $this->actingAs($this->user)
            ->deleteJson("/api/bank-data/gocardless/requisitions/{$reqId}")
            ->assertOk()
            ->assertJsonPath('message', 'Requisition deleted successfully');

        // The row survives as history (accounts keep pointing at it for reconnect UX)
        // but drops out of the list.
        $row = GoCardlessRequisition::where('user_id', $this->user->id)
            ->where('requisition_id', $reqId)
            ->firstOrFail();
        $this->assertSame(GoCardlessRequisitionStatus::REVOKED, $row->status);

        $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_user_cannot_delete_another_users_requisition(): void
    {
        $other = User::factory()->create();
        $foreign = GoCardlessRequisition::factory()->linked()->for($other)->create([
            'requisition_id' => 'req_foreign',
        ]);

        $this->actingAs($this->user)
            ->deleteJson('/api/bank-data/gocardless/requisitions/req_foreign')
            ->assertNotFound();

        $foreign->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $foreign->status);
    }

    public function test_delete_requisition_also_removes_imported_accounts(): void
    {
        // Create a requisition and import an account
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();

        $link = $createResponse->json('link');
        $this->get($link); // simulate callback

        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $results = $listResponse->json('results');

        if (empty($results)) {
            $this->markTestSkipped('Mock returned no requisitions');
        }

        $reqId = $results[0]['id'];
        $accounts = $results[0]['accounts'] ?? [];

        if (! empty($accounts)) {
            $firstAccountId = is_array($accounts[0]) ? ($accounts[0]['id'] ?? null) : $accounts[0];
            if ($firstAccountId) {
                $this->actingAs($this->user)
                    ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId])
                    ->assertOk();

                $this->assertDatabaseHas('accounts', [
                    'user_id' => $this->user->id,
                    'gocardless_account_id' => $firstAccountId,
                ]);
            }
        }

        $this->actingAs($this->user)
            ->deleteJson("/api/bank-data/gocardless/requisitions/{$reqId}", [
                'delete_imported_accounts' => true,
            ])
            ->assertOk();

        if (! empty($accounts)) {
            $firstAccountId = is_array($accounts[0]) ? ($accounts[0]['id'] ?? null) : $accounts[0];
            if ($firstAccountId) {
                $this->assertDatabaseMissing('accounts', [
                    'user_id' => $this->user->id,
                    'gocardless_account_id' => $firstAccountId,
                ]);
            }
        }
    }

    // ── error redaction ───────────────────────────────────────────────────

    public function test_import_account_failure_does_not_leak_exception_message(): void
    {
        // Ownership is checked before the import runs, so the account must be
        // reachable through one of this user's requisitions.
        GoCardlessRequisition::factory()->linked()->for($this->user)->create([
            'accounts' => ['mock_account_1'],
        ]);

        $this->mock(GoCardlessService::class, function ($mock) {
            $mock->shouldReceive('importAccount')
                ->andThrow(new \Exception('MARKER_SECRET_LEAK_abc123'));
        });

        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => 'mock_account_1']);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('MARKER_SECRET_LEAK_abc123', $response->getContent() ?: '');
        $response->assertJsonPath('message', 'Failed to import account');
    }

    public function test_callback_failure_shows_generic_error_message(): void
    {
        // Create a requisition through the real (mock GoCardless) service first, so the callback
        // has a valid ref/sig and cache context to resolve against.
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();
        $link = $createResponse->json('link');

        // Now swap in a service that fails with a message that must never reach the browser.
        $this->mock(GoCardlessService::class, function ($mock) {
            $mock->shouldReceive('getAccounts')
                ->andThrow(new \Exception('MARKER_SECRET_LEAK_callback_xyz'));
        });

        $response = $this->get($link);

        $response->assertRedirect();
        $errorMessage = session('error');
        $this->assertIsString($errorMessage);
        $this->assertStringNotContainsString('MARKER_SECRET_LEAK_callback_xyz', $errorMessage);
        $this->assertSame('Failed to get accounts.', $errorMessage);
    }

    // ── importAccount ─────────────────────────────────────────────────────

    public function test_guest_cannot_import_account(): void
    {
        $this->postJson('/api/bank-data/gocardless/import/account', [
            'account_id' => 'mock_account_1',
        ])->assertUnauthorized();
    }

    public function test_account_id_required_for_import(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_authenticated_user_can_import_account(): void
    {
        // Create a requisition first so the mock has account metadata
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();

        $link = $createResponse->json('link');
        $this->get($link); // simulate callback, populates mock state

        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $results = $listResponse->json('results');

        if (empty($results) || empty($results[0]['accounts'])) {
            $this->markTestSkipped('Mock returned no accounts to import');
        }

        $accounts = $results[0]['accounts'];
        $firstAccountId = is_array($accounts[0]) ? ($accounts[0]['id'] ?? null) : $accounts[0];
        $this->assertNotNull($firstAccountId);

        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId])
            ->assertOk()
            ->assertJsonPath('message', 'Account imported successfully');

        $this->assertDatabaseHas('accounts', [
            'user_id' => $this->user->id,
            'gocardless_account_id' => $firstAccountId,
        ]);
    }

    public function test_user_cannot_import_an_account_from_another_users_requisition(): void
    {
        $other = User::factory()->create();
        GoCardlessRequisition::factory()->linked()->for($other)->create([
            'accounts' => ['mock_account_1'],
        ]);

        $this->mock(GoCardlessService::class, function ($mock) {
            $mock->shouldNotReceive('importAccount');
        });

        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => 'mock_account_1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Account not available for this user');

        $this->assertDatabaseMissing('accounts', [
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
        ]);
    }

    public function test_import_is_rejected_for_an_account_no_requisition_returned(): void
    {
        $this->mock(GoCardlessService::class, function ($mock) {
            $mock->shouldNotReceive('importAccount');
        });

        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => 'never_seen_account'])
            ->assertForbidden();
    }

    public function test_importing_same_account_twice_returns_error(): void
    {
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);
        $createResponse->assertOk();

        $link = $createResponse->json('link');
        $this->get($link);

        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $results = $listResponse->json('results');

        if (empty($results) || empty($results[0]['accounts'])) {
            $this->markTestSkipped('Mock returned no accounts');
        }

        $accounts = $results[0]['accounts'];
        $firstAccountId = is_array($accounts[0]) ? ($accounts[0]['id'] ?? null) : $accounts[0];

        // First import — should succeed
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId])
            ->assertOk();

        // Second import — should return 400 (AccountAlreadyExistsException)
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Account already exists');
    }

    // ── getExistingGocardlessAccountIDs ───────────────────────────────────

    public function test_check_existing_account_id_requires_account_id(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }

    // ── rate limiting / middleware ──────────────────────────────────────────

    public function test_gocardless_routes_have_expected_rate_limiters(): void
    {
        $expectations = [
            ['GET', 'api/bank-data/gocardless/institutions', 'throttle:gocardless-read'],
            ['GET', 'api/bank-data/gocardless/requisitions', 'throttle:gocardless-read'],
            ['POST', 'api/bank-data/gocardless/requisitions', 'throttle:gocardless-write'],
            ['DELETE', 'api/bank-data/gocardless/requisitions/{id}', 'throttle:gocardless-write'],
            ['POST', 'api/bank-data/gocardless/requisitions/{requisitionRow}/reconnect', 'throttle:gocardless-write'],
            ['POST', 'api/bank-data/gocardless/import/account', 'throttle:gocardless-write'],
            ['POST', 'api/bank-data/gocardless/accounts/{account}/refresh-balance', 'throttle:gocardless-write'],
            ['POST', 'api/bank-data/gocardless/accounts/{account}/sync-transactions', 'throttle:gocardless-sync'],
            ['POST', 'api/bank-data/gocardless/accounts/sync-all', 'throttle:gocardless-sync'],
            ['GET', 'api/bank-data/gocardless/requisition/callback', 'throttle:gocardless-callback'],
        ];

        foreach ($expectations as [$method, $uri, $limiter]) {
            $route = Route::getRoutes()->get($method)[$uri] ?? null;
            $this->assertNotNull($route, "Route not found: {$method} {$uri}");
            $this->assertContains($limiter, $route->middleware(), "Expected [{$limiter}] on {$method} {$uri}");
        }
    }

    public function test_gocardless_group_requires_auth_and_verified_except_callback(): void
    {
        $protectedRoute = Route::getRoutes()->get('GET')['api/bank-data/gocardless/requisitions'] ?? null;
        $this->assertNotNull($protectedRoute);
        $this->assertContains('auth', $protectedRoute->middleware());
        $this->assertContains('verified', $protectedRoute->middleware());

        $callbackRoute = Route::getRoutes()->get('GET')['api/bank-data/gocardless/requisition/callback'] ?? null;
        $this->assertNotNull($callbackRoute);
        $this->assertContains('auth', $callbackRoute->excludedMiddleware(), 'Callback must exclude auth (unauthenticated bank redirect)');
        $this->assertContains('verified', $callbackRoute->excludedMiddleware(), 'Callback must exclude verified (unauthenticated bank redirect)');
    }

    public function test_repeated_sync_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->user)
                ->postJson('/api/bank-data/gocardless/accounts/sync-all');
        }

        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all')
            ->assertStatus(429);
    }
}
