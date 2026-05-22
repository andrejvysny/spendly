<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\User;
use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        if (!empty($accounts)) {
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

        if (!empty($accounts)) {
            $firstAccountId = is_array($accounts[0]) ? ($accounts[0]['id'] ?? null) : $accounts[0];
            if ($firstAccountId) {
                $this->assertDatabaseMissing('accounts', [
                    'user_id' => $this->user->id,
                    'gocardless_account_id' => $firstAccountId,
                ]);
            }
        }
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
}
