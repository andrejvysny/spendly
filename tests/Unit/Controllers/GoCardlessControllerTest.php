<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\BankProviders\GoCardlessController;
use App\Models\User;
use App\Services\GoCardlessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GoCardlessControllerTest extends UnitTestCase
{
    private GoCardlessController $controller;

    private GoCardlessService $gocardlessServiceMock;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->gocardlessServiceMock = Mockery::mock(GoCardlessService::class);

        // Create test user mock
        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->gocardless_secret_id = 'test_secret_id';
        $this->user->gocardless_secret_key = 'test_secret_key';
        $this->user->shouldReceive('setAttribute')->andReturnSelf();

        // Create controller instance
        $this->controller = new GoCardlessController($this->gocardlessServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test syncTransactions success with default parameters
     */
    public function test_sync_transactions_success_default_params()
    {
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

        // Create request mock
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')
            ->with('update_existing', true)
            ->andReturn(true);
        $request->shouldReceive('boolean')
            ->with('force_max_date_range', false)
            ->andReturn(false);

        // Mock service call
        $this->gocardlessServiceMock->shouldReceive('syncAccountTransactions')
            ->with($accountId, $this->user, true, false)
            ->once()
            ->andReturn($syncResult);

        // Act
        $response = $this->controller->syncTransactions($request, $accountId);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Transactions synced successfully', $data['message']);
        $this->assertEquals($syncResult, $data['data']);
        $this->assertEquals(200, $response->status());
    }

    /**
     * Test syncTransactions with custom parameters
     */
    public function test_sync_transactions_with_custom_params()
    {
        $accountId = 456;
        $syncResult = ['account_id' => $accountId, 'stats' => ['created' => 0]];

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')
            ->with('update_existing', true)
            ->andReturn(false);
        $request->shouldReceive('boolean')
            ->with('force_max_date_range', false)
            ->andReturn(true);

        $this->gocardlessServiceMock->shouldReceive('syncAccountTransactions')
            ->with($accountId, $this->user, false, true)
            ->once()
            ->andReturn($syncResult);

        $response = $this->controller->syncTransactions($request, $accountId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
    }

    /**
     * Test syncTransactions with unauthenticated user
     */
    public function test_sync_transactions_unauthenticated()
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn(null);

        $response = $this->controller->syncTransactions($request, 123);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('User not authenticated or invalid user type', $data['error']);
        $this->assertEquals(401, $response->status());
    }

    /**
     * Test syncTransactions with invalid user type
     */
    public function test_sync_transactions_invalid_user_type()
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn(new \stdClass); // Not a User instance

        $response = $this->controller->syncTransactions($request, 123);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('User not authenticated or invalid user type', $data['error']);
        $this->assertEquals(401, $response->status());
    }

    /**
     * Test syncTransactions with service exception
     */
    public function test_sync_transactions_service_exception()
    {
        $accountId = 789;
        $errorMessage = 'GoCardless API error';

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')->andReturn(true);

        $this->gocardlessServiceMock->shouldReceive('syncAccountTransactions')
            ->andThrow(new \Exception($errorMessage));

        // Prevent actual logging during test
        Log::shouldReceive('error')
            ->once()
            ->with('Transaction sync error', Mockery::on(function ($context) use ($accountId) {
                return $context['message'] === 'GoCardless API error' &&
                       $context['account_id'] === $accountId &&
                       $context['user_id'] === $this->user->id &&
                       isset($context['trace']);
            }));

        $response = $this->controller->syncTransactions($request, $accountId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals("Failed to sync transactions: $errorMessage", $data['error']);
        $this->assertEquals(500, $response->status());
    }

    /**
     * Test syncAllAccounts success
     */
    public function test_sync_all_accounts_success()
    {
        $syncResults = [
            ['account_id' => 1, 'status' => 'success', 'stats' => ['created' => 5]],
            ['account_id' => 2, 'status' => 'success', 'stats' => ['created' => 3]],
        ];

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')
            ->with('update_existing', true)
            ->andReturn(true);
        $request->shouldReceive('boolean')
            ->with('force_max_date_range', false)
            ->andReturn(false);

        $this->gocardlessServiceMock->shouldReceive('syncAllAccounts')
            ->with($this->user, true, false)
            ->once()
            ->andReturn($syncResults);

        $response = $this->controller->syncAllAccounts($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('All accounts synced', $data['message']);
        $this->assertEquals($syncResults, $data['data']);
        $this->assertEquals(200, $response->status());
    }

    /**
     * Test syncAllAccounts with partial failure
     */
    public function test_sync_all_accounts_partial_failure()
    {
        $syncResults = [
            ['account_id' => 1, 'status' => 'success', 'stats' => ['created' => 5]],
            ['account_id' => 2, 'status' => 'error', 'error' => 'API Error'],
        ];

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')
            ->with('update_existing', true)
            ->andReturn(true);
        $request->shouldReceive('boolean')
            ->with('force_max_date_range', false)
            ->andReturn(true);

        $this->gocardlessServiceMock->shouldReceive('syncAllAccounts')
            ->with($this->user, true, true)
            ->once()
            ->andReturn($syncResults);

        $response = $this->controller->syncAllAccounts($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals($syncResults, $data['data']);
    }

    /**
     * Test syncAllAccounts with unauthenticated user
     */
    public function test_sync_all_accounts_unauthenticated()
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn(null);

        $response = $this->controller->syncAllAccounts($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('User not authenticated or invalid user type', $data['error']);
        $this->assertEquals(401, $response->status());
    }

    /**
     * Test syncAllAccounts with service exception
     */
    public function test_sync_all_accounts_service_exception()
    {
        $errorMessage = 'Database connection failed';

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')->andReturn(true);

        $this->gocardlessServiceMock->shouldReceive('syncAllAccounts')
            ->andThrow(new \Exception($errorMessage));

        // Prevent actual logging during test
        Log::shouldReceive('error')
            ->once()
            ->with('Sync all accounts error', Mockery::on(function ($context) {
                return $context['message'] === 'Database connection failed' &&
                       $context['user_id'] === $this->user->id &&
                       isset($context['trace']);
            }));

        $response = $this->controller->syncAllAccounts($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals("Failed to sync accounts: $errorMessage", $data['error']);
        $this->assertEquals(500, $response->status());
    }

    /**
     * Data provider for boolean parameter combinations
     */
    public static function booleanParameterProvider(): array
    {
        return [
            'both true' => [true, true],
            'update false, force true' => [false, true],
            'update true, force false' => [true, false],
            'both false' => [false, false],
        ];
    }

    /**
     * Test syncTransactions with different parameter combinations
     */
    #[DataProvider('booleanParameterProvider')]
    public function test_sync_transactions_parameter_combinations($updateExisting, $forceMaxDateRange)
    {
        $accountId = 123;
        $syncResult = ['account_id' => $accountId, 'stats' => ['created' => 0]];

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')
            ->with('update_existing', true)
            ->andReturn($updateExisting);
        $request->shouldReceive('boolean')
            ->with('force_max_date_range', false)
            ->andReturn($forceMaxDateRange);

        $this->gocardlessServiceMock->shouldReceive('syncAccountTransactions')
            ->with($accountId, $this->user, $updateExisting, $forceMaxDateRange)
            ->once()
            ->andReturn($syncResult);

        $response = $this->controller->syncTransactions($request, $accountId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals(200, $response->status());
    }

    /**
     * Test that unknown user is logged on error
     */
    public function test_sync_transactions_logs_unknown_user_on_error()
    {
        $accountId = 123;
        $errorMessage = 'Service error';

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($this->user);
        $request->shouldReceive('boolean')->andReturn(true);

        $this->gocardlessServiceMock->shouldReceive('syncAccountTransactions')
            ->andThrow(new \Exception($errorMessage));

        // Prevent actual logging during test
        Log::shouldReceive('error')
            ->once()
            ->with('Transaction sync error', Mockery::on(function ($context) use ($accountId) {
                return $context['message'] === 'Service error' &&
                       $context['account_id'] === $accountId &&
                       $context['user_id'] === $this->user->id &&
                       isset($context['trace']);
            }));

        $response = $this->controller->syncTransactions($request, $accountId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals("Failed to sync transactions: $errorMessage", $data['error']);
    }
}
