<?php

namespace Tests\Feature\Controllers\GoCardless;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class GoCardlessControllerTest extends TestCase
{
    use RefreshDatabase;

    private GoCardlessService $serviceMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the GoCardlessService binding in the container
        $this->serviceMock = Mockery::mock(GoCardlessService::class);
        $this->app->instance(GoCardlessService::class, $this->serviceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'gocardless_secret_id' => 'test_secret_id',
            'gocardless_secret_key' => 'test_secret_key',
        ]);
    }

    public function test_sync_transactions_success_default_params(): void
    {
        $user = $this->createUser();
        $accountId = 123;
        $syncResult = [
            'account_id' => $accountId,
            'stats' => [
                'created' => 5,
                'updated' => 2,
                'skipped' => 1,
                'total' => 8,
            ],
            'date_range' => [
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
            ],
        ];

        $this->serviceMock->shouldReceive('syncAccountTransactions')
            ->once()
            ->with($accountId, Mockery::on(fn ($u) => $u->id === $user->id), true, false)
            ->andReturn($syncResult);

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync', ['accountId' => $accountId]));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Transactions synced successfully',
                'data' => $syncResult,
            ]);
    }

    public function test_sync_transactions_with_custom_params(): void
    {
        $user = $this->createUser();
        $accountId = 456;
        $syncResult = ['account_id' => $accountId, 'stats' => ['created' => 0]];

        $this->serviceMock->shouldReceive('syncAccountTransactions')
            ->once()
            ->with($accountId, Mockery::on(fn ($u) => $u->id === $user->id), false, true)
            ->andReturn($syncResult);

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync', ['accountId' => $accountId]), [
                'update_existing' => false,
                'force_max_date_range' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => $syncResult,
            ]);
    }

    public function test_sync_transactions_unauthenticated(): void
    {
        $response = $this->postJson(route('gocardless.accounts.sync', ['accountId' => 1]));
        // Laravel auth middleware returns { message: "Unauthenticated." }
        $response->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_sync_transactions_service_exception(): void
    {
        $user = $this->createUser();
        $accountId = 789;
        $errorMessage = 'GoCardless API error';

        $this->serviceMock->shouldReceive('syncAccountTransactions')
            ->andThrow(new \Exception($errorMessage));

        Log::shouldReceive('error')
            ->once()
            ->with('Transaction sync error', Mockery::on(function ($context) use ($errorMessage, $accountId, $user) {
                return $context['message'] === $errorMessage
                    && $context['account_id'] === $accountId
                    && (int) $context['user_id'] === $user->id
                    && isset($context['trace']);
            }));

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync', ['accountId' => $accountId]));

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJson([
                'success' => false,
                'error' => 'Failed to sync transactions: '.$errorMessage,
            ]);
    }

    public function test_sync_all_accounts_success(): void
    {
        $user = $this->createUser();
        $syncResults = [
            ['account_id' => 1, 'status' => 'success', 'stats' => ['created' => 5]],
            ['account_id' => 2, 'status' => 'success', 'stats' => ['created' => 3]],
        ];

        $this->serviceMock->shouldReceive('syncAllAccounts')
            ->once()
            ->with(Mockery::on(fn ($u) => $u->id === $user->id), true, false)
            ->andReturn($syncResults);

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync-all'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'All accounts synced',
                'data' => $syncResults,
            ]);
    }

    public function test_sync_all_accounts_partial_failure(): void
    {
        $user = $this->createUser();
        $syncResults = [
            ['account_id' => 1, 'status' => 'success', 'stats' => ['created' => 5]],
            ['account_id' => 2, 'status' => 'error', 'error' => 'API Error'],
        ];

        $this->serviceMock->shouldReceive('syncAllAccounts')
            ->once()
            ->with(Mockery::on(fn ($u) => $u->id === $user->id), true, true)
            ->andReturn($syncResults);

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync-all'), [
                'update_existing' => true,
                'force_max_date_range' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => $syncResults,
            ]);
    }

    public function test_sync_all_accounts_unauthenticated(): void
    {
        $response = $this->postJson(route('gocardless.accounts.sync-all'));
        $response->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_sync_all_accounts_service_exception(): void
    {
        $user = $this->createUser();
        $errorMessage = 'Database connection failed';

        $this->serviceMock->shouldReceive('syncAllAccounts')
            ->andThrow(new \Exception($errorMessage));

        Log::shouldReceive('error')
            ->once()
            ->with('Sync all accounts error', Mockery::on(function ($context) use ($errorMessage, $user) {
                return $context['message'] === $errorMessage
                    && (int) $context['user_id'] === $user->id
                    && isset($context['trace']);
            }));

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync-all'));

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJson([
                'success' => false,
                'error' => 'Failed to sync accounts: '.$errorMessage,
            ]);
    }

    public static function booleanParameterProvider(): array
    {
        return [
            'both true' => [true, true],
            'update false, force true' => [false, true],
            'update true, force false' => [true, false],
            'both false' => [false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('booleanParameterProvider')]
    public function test_sync_transactions_parameter_combinations(bool $updateExisting, bool $forceMaxDateRange): void
    {
        $user = $this->createUser();
        $accountId = 321;
        $syncResult = ['account_id' => $accountId, 'stats' => ['created' => 0]];

        $this->serviceMock->shouldReceive('syncAccountTransactions')
            ->once()
            ->with($accountId, Mockery::on(fn ($u) => $u->id === $user->id), $updateExisting, $forceMaxDateRange)
            ->andReturn($syncResult);

        $payload = [
            'update_existing' => $updateExisting,
            'force_max_date_range' => $forceMaxDateRange,
        ];

        $response = $this->actingAs($user)
            ->postJson(route('gocardless.accounts.sync', ['accountId' => $accountId]), $payload);

        $response->assertOk()->assertJson([
            'success' => true,
        ]);
    }
}
