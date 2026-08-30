<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessBankDataClient;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\GoCardlessService;
use App\Services\GoCardless\TransactionSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Unit\UnitTestCase;

class GoCardlessServiceTest extends UnitTestCase
{
    private GoCardlessService $service;

    private AccountRepository $accountRepository;

    private TransactionSyncService $transactionSyncService;

    private GocardlessMapper $mapper;

    private GoCardlessBankDataClient $bankDataMock;

    private GoCardlessClientFactoryInterface $clientFactoryMock;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress all logging during tests
        config(['logging.default' => 'null']);
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();

        // Disable cache for unit tests
        Cache::shouldReceive('has')->andReturn(false);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andReturn(true);

        // Create mocks
        $this->accountRepository = Mockery::mock(AccountRepository::class);
        $this->transactionSyncService = Mockery::mock(TransactionSyncService::class);
        $this->mapper = Mockery::mock(GocardlessMapper::class);
        $this->bankDataMock = Mockery::mock(GoCardlessBankDataClient::class);
        $this->clientFactoryMock = Mockery::mock(GoCardlessClientFactoryInterface::class);

        // Create test user mock
        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->gocardless_secret_id = 'test_secret_id';
        $this->user->gocardless_secret_key = 'test_secret_key';
        $this->user->gocardless_access_token = 'test_access_token';
        $this->user->gocardless_refresh_token = 'test_refresh_token';
        $this->user->gocardless_access_token_expires_at = now()->addHour();
        $this->user->gocardless_refresh_token_expires_at = now()->addDay();
        $this->user->shouldReceive('setAttribute')->andReturnSelf();
        $this->user->shouldReceive('update')->andReturnSelf();

        $this->service = new GoCardlessService(
            $this->accountRepository,
            $this->transactionSyncService,
            $this->mapper,
            $this->clientFactoryMock
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper method to inject mocked client and token manager
     */
    /**
     * Helper method to setup factory mock
     */
    private function setupFactoryMock(): void
    {
        $this->clientFactoryMock->shouldReceive('make')
            ->with($this->user)
            ->andReturn($this->bankDataMock);
    }

    /**
     * Test syncAccountTransactions with successful sync
     */
    public function test_sync_account_transactions_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $account = Mockery::mock(Account::class)->makePartial();
        $account->id = 1;
        $account->user_id = $this->user->id;
        $account->is_gocardless_synced = true;
        $account->gocardless_account_id = 'gocardless_acc_123';

        // Allow setAttribute calls on the account mock
        $account->shouldReceive('setAttribute')->andReturnSelf();

        $dateRange = [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
        ];

        $gocardlessResponse = [
            'transactions' => [
                'booked' => [
                    ['transactionId' => 'tx1', 'bookingDate' => '2024-01-15'],
                    ['transactionId' => 'tx2', 'bookingDate' => '2024-01-16'],
                ],
                'pending' => [],
            ],
        ];

        $syncStats = [
            'created' => 2,
            'updated' => 0,
            'skipped' => 0,
            'total' => 2,
        ];

        // Setup mocks - no need for getAccessToken when mocks are injected
        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with($account->id, $this->user->id)
            ->once()
            ->andReturn($account);

        $this->transactionSyncService->shouldReceive('calculateDateRange')
            ->with($account, 90, false)
            ->once()
            ->andReturn($dateRange);

        $this->bankDataMock->shouldReceive('getTransactions')
            ->with('gocardless_acc_123', '2024-01-01', '2024-01-31')
            ->once()
            ->andReturn($gocardlessResponse);

        $this->transactionSyncService->shouldReceive('syncTransactions')
            ->with($gocardlessResponse['transactions']['booked'], Mockery::on(function ($arg) use ($account) {
                return $arg->id === $account->id;
            }), true)
            ->once()
            ->andReturn($syncStats);

        $this->accountRepository->shouldReceive('updateSyncTimestamp')
            ->with($account, Mockery::any())
            ->once();

        // Act
        $result = $this->service->syncAccountTransactions($account->id, $this->user);

        // Assert
        $this->assertEquals($account->id, $result['account_id']);
        $this->assertEquals($syncStats, $result['stats']);
        $this->assertEquals($dateRange, $result['date_range']);
    }

    /**
     * Test syncAccountTransactions with missing user credentials
     */
    public function test_sync_account_transactions_missing_credentials(): void
    {
        $userWithoutCredentials = Mockery::mock(User::class)->makePartial();
        $userWithoutCredentials->id = 2;
        $userWithoutCredentials->gocardless_secret_id = null;
        $userWithoutCredentials->gocardless_secret_key = null;
        $userWithoutCredentials->shouldReceive('setAttribute')->andReturnSelf();

        $this->clientFactoryMock->shouldReceive('make')
            ->with($userWithoutCredentials)
            ->once()
            ->andThrow(new \InvalidArgumentException('GoCardless credentials not configured for user'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GoCardless credentials not configured for user');

        $this->service->syncAccountTransactions(1, $userWithoutCredentials);
    }

    /**
     * Test syncAccountTransactions with account not found
     */
    public function test_sync_account_transactions_account_not_found(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with(999, $this->user->id)
            ->once()
            ->andReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Account not found');

        $this->service->syncAccountTransactions(999, $this->user);
    }

    /**
     * Test syncAccountTransactions with non-synced account
     */
    public function test_sync_account_transactions_non_synced_account(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $account = Mockery::mock(Account::class)->makePartial();
        $account->id = 1;
        $account->user_id = $this->user->id;
        $account->is_gocardless_synced = false;
        $account->gocardless_account_id = 'gocardless_acc_123';

        // Allow setAttribute calls on the account mock
        $account->shouldReceive('setAttribute')->andReturnSelf();

        // Allow setAttribute calls on the account mock
        $account->shouldReceive('setAttribute')->andReturnSelf();

        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with($account->id, $this->user->id)
            ->once()
            ->andReturn($account);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Account is not synced with GoCardless');

        $this->service->syncAccountTransactions($account->id, $this->user);
    }

    /**
     * Test syncAccountTransactions with force max date range
     */
    public function test_sync_account_transactions_force_max_date_range(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $account = Mockery::mock(Account::class)->makePartial();
        $account->id = 1;
        $account->user_id = $this->user->id;
        $account->is_gocardless_synced = true;
        $account->gocardless_account_id = 'gocardless_acc_123';

        // Allow setAttribute calls on the account mock
        $account->shouldReceive('setAttribute')->andReturnSelf();

        $dateRange = [
            'date_from' => '2023-01-01',
            'date_to' => '2024-01-31',
        ];

        $gocardlessResponse = [
            'transactions' => [
                'booked' => [],
                'pending' => [],
            ],
        ];

        $syncStats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with($account->id, $this->user->id)
            ->once()
            ->andReturn($account);

        $this->transactionSyncService->shouldReceive('calculateDateRange')
            ->with($account, 90, true)
            ->once()
            ->andReturn($dateRange);

        $this->bankDataMock->shouldReceive('getTransactions')
            ->with('gocardless_acc_123', '2023-01-01', '2024-01-31')
            ->once()
            ->andReturn($gocardlessResponse);

        $this->transactionSyncService->shouldReceive('syncTransactions')
            ->with($gocardlessResponse['transactions']['booked'], Mockery::on(function ($arg) use ($account) {
                return $arg->id === $account->id;
            }), true)
            ->once()
            ->andReturn($syncStats);

        $this->accountRepository->shouldReceive('updateSyncTimestamp')
            ->with($account, Mockery::any())
            ->once();

        $result = $this->service->syncAccountTransactions($account->id, $this->user, true, true);

        $this->assertEquals($account->id, $result['account_id']);
        $this->assertEquals($syncStats, $result['stats']);
        $this->assertEquals($dateRange, $result['date_range']);
    }

    /**
     * Build the account mock shared by the watermark tests below.
     */
    private function watermarkAccount(): Account
    {
        $account = Mockery::mock(Account::class)->makePartial();
        $account->id = 1;
        $account->user_id = $this->user->id;
        $account->is_gocardless_synced = true;
        $account->gocardless_account_id = 'gocardless_acc_123';
        $account->shouldReceive('setAttribute')->andReturnSelf();

        return $account;
    }

    /**
     * Stub the calculateDateRange/getTransactions/syncTransactions chain shared by
     * the watermark tests, isolating only the two things each test varies: the
     * GoCardless response's `partial` flag and the sync stats.
     *
     * @param  array{date_from:string,date_to:string}  $dateRange
     * @param  array<string,mixed>  $syncStats
     */
    private function stubSyncFlow(Account $account, array $dateRange, array $syncStats, bool $partial = false): void
    {
        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with($account->id, $this->user->id)
            ->once()
            ->andReturn($account);

        $this->transactionSyncService->shouldReceive('calculateDateRange')
            ->with($account, 90, false)
            ->once()
            ->andReturn($dateRange);

        $this->bankDataMock->shouldReceive('getTransactions')
            ->once()
            ->andReturn([
                'transactions' => ['booked' => [], 'pending' => []],
                'partial' => $partial,
            ]);

        $this->transactionSyncService->shouldReceive('syncTransactions')
            ->once()
            ->andReturn($syncStats);
    }

    /**
     * Stub updateSyncTimestamp and capture the Carbon value it was called with (or
     * confirm it was never called), so tests get a real PHPUnit assertion on the exact
     * watermark instead of relying solely on a Mockery argument matcher.
     *
     * @return object{called: bool, value: mixed}
     */
    private function captureWatermark(Account $account, bool $expectCall): object
    {
        $capture = new class
        {
            public bool $called = false;

            public mixed $value = null;
        };

        $expectation = $this->accountRepository->shouldReceive('updateSyncTimestamp')
            ->with($account, Mockery::on(function ($arg) use ($capture) {
                $capture->called = true;
                $capture->value = $arg;

                return true;
            }));

        $expectCall ? $expectation->once() : $expectation->never();

        return $capture;
    }

    public function test_watermark_advances_to_date_to_on_clean_sync(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-05-16', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 3, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => 3,
            ]);

            $capture = $this->captureWatermark($account, expectCall: true);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertInstanceOf(Carbon::class, $capture->value);
            $this->assertSame('2024-06-15', $capture->value->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A row silently rejected by the unique index carries no date we could anchor on, so the
     * watermark must not move at all — advancing past it would mean no later run ever refetches it.
     */
    public function test_watermark_not_advanced_when_rows_were_dropped_on_insert(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-05-16', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 2, 'updated' => 0, 'skipped' => 0, 'dropped' => 1,
                'errors' => 0, 'total' => 3,
            ]);

            $capture = $this->captureWatermark($account, expectCall: false);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertFalse($capture->called);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_watermark_not_advanced_on_partial_fetch(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-05-16', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => 0,
            ], partial: true);

            $capture = $this->captureWatermark($account, expectCall: false);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertFalse($capture->called, 'watermark must not advance on a partial fetch');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_watermark_pulled_back_to_earliest_failure_minus_one_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-05-16', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'total' => 1,
                // Well within the 90-day floor, so the clamp does not kick in here.
                'earliest_unsynced_date' => '2024-06-01',
            ]);

            $capture = $this->captureWatermark($account, expectCall: true);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertInstanceOf(Carbon::class, $capture->value);
            $this->assertSame('2024-05-31', $capture->value->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_watermark_not_advanced_when_failure_date_unknown(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-05-16', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'total' => 1,
                'earliest_unsynced_date' => null,
            ]);

            $capture = $this->captureWatermark($account, expectCall: false);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertFalse($capture->called, 'watermark must not advance when the failure date is unknown');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_watermark_clamped_to_90_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        try {
            $this->setupFactoryMock();
            $account = $this->watermarkAccount();
            $dateRange = ['date_from' => '2024-03-17', 'date_to' => '2024-06-15'];

            $this->stubSyncFlow($account, $dateRange, [
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'total' => 1,
                // Far more than 90 days before "now": earliest - 1 day would fall below the floor.
                'earliest_unsynced_date' => '2023-01-01',
            ]);

            $expectedFloor = Carbon::now()->subDays(90)->format('Y-m-d');

            $capture = $this->captureWatermark($account, expectCall: true);

            $this->service->syncAccountTransactions($account->id, $this->user);

            $this->assertInstanceOf(Carbon::class, $capture->value);
            $this->assertSame($expectedFloor, $capture->value->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test syncAllAccounts with successful sync
     */
    public function test_sync_all_accounts_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $account1 = Mockery::mock(Account::class)->makePartial();
        $account1->id = 1;
        $account1->user_id = $this->user->id;
        $account1->is_gocardless_synced = true;
        $account1->gocardless_account_id = 'gocardless_acc_1';
        $account1->shouldReceive('setAttribute')->andReturnSelf();

        $account2 = Mockery::mock(Account::class)->makePartial();
        $account2->id = 2;
        $account2->user_id = $this->user->id;
        $account2->is_gocardless_synced = true;
        $account2->gocardless_account_id = 'gocardless_acc_2';
        $account2->shouldReceive('setAttribute')->andReturnSelf();

        $accounts = new Collection([$account1, $account2]);

        $this->accountRepository->shouldReceive('getGocardlessSyncedAccounts')
            ->with($this->user->id)
            ->once()
            ->andReturn($accounts);

        // Mock syncAccountTransactions for both accounts
        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with(1, $this->user->id)
            ->once()
            ->andReturn($account1);

        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with(2, $this->user->id)
            ->once()
            ->andReturn($account2);

        $this->transactionSyncService->shouldReceive('calculateDateRange')
            ->twice()
            ->andReturn(['date_from' => '2024-01-01', 'date_to' => '2024-01-31']);

        $this->bankDataMock->shouldReceive('getTransactions')
            ->twice()
            ->andReturn(['transactions' => ['booked' => [], 'pending' => []]]);

        $this->transactionSyncService->shouldReceive('syncTransactions')
            ->twice()
            ->andReturn(['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0]);

        $this->accountRepository->shouldReceive('updateSyncTimestamp')
            ->with(Mockery::any(), Mockery::any())
            ->twice();

        $results = $this->service->syncAllAccounts($this->user);

        $this->assertCount(2, $results);
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('success', $results[1]['status']);
    }

    /**
     * Test syncAllAccounts with partial failure
     */
    public function test_sync_all_accounts_partial_failure(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $account1 = Mockery::mock(Account::class)->makePartial();
        $account1->id = 1;
        $account1->user_id = $this->user->id;
        $account1->is_gocardless_synced = true;
        $account1->gocardless_account_id = 'gocardless_acc_1';
        $account1->shouldReceive('setAttribute')->andReturnSelf();

        $account2 = Mockery::mock(Account::class)->makePartial();
        $account2->id = 2;
        $account2->user_id = $this->user->id;
        $account2->is_gocardless_synced = true;
        $account2->gocardless_account_id = 'gocardless_acc_2';
        $account2->shouldReceive('setAttribute')->andReturnSelf();

        $accounts = new Collection([$account1, $account2]);

        $this->accountRepository->shouldReceive('getGocardlessSyncedAccounts')
            ->with($this->user->id)
            ->once()
            ->andReturn($accounts);

        // First account succeeds
        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with(1, $this->user->id)
            ->once()
            ->andReturn($account1);

        $this->transactionSyncService->shouldReceive('calculateDateRange')
            ->with($account1, 90, false)
            ->once()
            ->andReturn(['date_from' => '2024-01-01', 'date_to' => '2024-01-31']);

        $this->bankDataMock->shouldReceive('getTransactions')
            ->with('gocardless_acc_1', '2024-01-01', '2024-01-31')
            ->once()
            ->andReturn(['transactions' => ['booked' => [], 'pending' => []]]);

        $this->transactionSyncService->shouldReceive('syncTransactions')
            ->with([], $account1, true)
            ->once()
            ->andReturn(['created' => 5, 'updated' => 0, 'skipped' => 0, 'total' => 5]);

        $this->accountRepository->shouldReceive('updateSyncTimestamp')
            ->with($account1, Mockery::any())
            ->once();

        // Second account fails
        $this->accountRepository->shouldReceive('findByIdForUser')
            ->with(2, $this->user->id)
            ->once()
            ->andThrow(new \Exception('API Error'));

        $results = $this->service->syncAllAccounts($this->user);

        $this->assertCount(2, $results);
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('error', $results[1]['status']);
        $this->assertEquals('API Error', $results[1]['error']);
    }

    /**
     * Test getInstitutions success
     */
    public function test_get_institutions_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $countryCode = 'GB';
        $institutions = [
            ['id' => 'inst1', 'name' => 'Bank 1'],
            ['id' => 'inst2', 'name' => 'Bank 2'],
        ];

        $this->bankDataMock->shouldReceive('getInstitutions')
            ->with($countryCode)
            ->once()
            ->andReturn($institutions);

        $result = $this->service->getInstitutions($countryCode, $this->user);

        $this->assertEquals($institutions, $result);
    }

    /**
     * Test createRequisition success
     */
    public function test_create_requisition_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $institutionId = 'inst_123';
        $redirectUrl = 'https://example.com/callback';
        $agreementData = ['id' => 'agr_789'];
        $requisitionData = [
            'id' => 'req_456',
            'status' => 'CREATED',
        ];

        $this->bankDataMock->shouldReceive('createEndUserAgreement')
            ->with($institutionId, [])
            ->once()
            ->andReturn($agreementData);

        $this->bankDataMock->shouldReceive('createRequisition')
            ->with($institutionId, $redirectUrl, 'agr_789', 'ref-abc')
            ->once()
            ->andReturn($requisitionData);

        $result = $this->service->createRequisition($institutionId, $redirectUrl, $this->user, 'ref-abc');

        $this->assertEquals($requisitionData, $result['requisition']);
        $this->assertEquals($agreementData, $result['agreement']);
    }

    public function test_create_requisition_proceeds_without_agreement_on_failure(): void
    {
        $this->setupFactoryMock();

        $institutionId = 'inst_123';
        $redirectUrl = 'https://example.com/callback';
        $requisitionData = ['id' => 'req_456', 'status' => 'CREATED'];

        $this->bankDataMock->shouldReceive('createEndUserAgreement')
            ->with($institutionId, [])
            ->once()
            ->andThrow(new \Exception('EUA creation failed'));

        $this->bankDataMock->shouldReceive('createRequisition')
            ->with($institutionId, $redirectUrl, null, null)
            ->once()
            ->andReturn($requisitionData);

        $result = $this->service->createRequisition($institutionId, $redirectUrl, $this->user);

        $this->assertEquals($requisitionData, $result['requisition']);
        $this->assertNull($result['agreement'], 'A failed EUA must not be reported as an agreement');
    }

    /**
     * Test getRequisition success
     */
    public function test_get_requisition_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $requisitionId = 'req_456';
        $requisitionData = [
            'id' => 'req_456',
            'status' => 'LINKED',
            'accounts' => ['acc_1', 'acc_2'],
        ];

        $this->bankDataMock->shouldReceive('getRequisitions')
            ->with($requisitionId)
            ->once()
            ->andReturn($requisitionData);

        $result = $this->service->getRequisition($requisitionId, $this->user);

        $this->assertEquals($requisitionData, $result);
    }

    /**
     * Test importAccount success
     */
    public function test_import_account_success(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $gocardlessAccountId = 'gocardless_acc_123';
        $accountDetails = [
            'account' => [
                'id' => $gocardlessAccountId,
                'name' => 'Test Account',
                'currency' => 'GBP',
            ],
        ];
        $balances = [
            'balances' => [
                [
                    'balanceType' => 'closingBooked',
                    'balanceAmount' => ['amount' => '1000.50'],
                ],
            ],
        ];
        $mappedData = [
            'name' => 'Test Account',
            'currency' => 'GBP',
            'balance' => 1000.50,
            'user_id' => $this->user->id,
        ];

        $importedAccount = Mockery::mock(Account::class)->makePartial();
        $importedAccount->id = 1;
        $importedAccount->name = 'Test Account';
        $importedAccount->shouldReceive('setAttribute')->andReturnSelf();

        $this->accountRepository->shouldReceive('gocardlessAccountExists')
            ->with($gocardlessAccountId, $this->user->id)
            ->once()
            ->andReturn(false);

        $this->bankDataMock->shouldReceive('getAccountDetails')
            ->with($gocardlessAccountId)
            ->once()
            ->andReturn($accountDetails);

        $this->bankDataMock->shouldReceive('getBalances')
            ->with($gocardlessAccountId)
            ->once()
            ->andReturn($balances);

        $this->bankDataMock->shouldReceive('getAccountMetadata')
            ->with($gocardlessAccountId)
            ->once()
            ->andReturn([
                'id' => $gocardlessAccountId,
                'institution_id' => null,
                'status' => 'READY',
            ]);

        $this->mapper->shouldReceive('mapAccountData')
            ->with(\Mockery::on(function ($arg) use ($gocardlessAccountId) {
                return $arg['id'] === $gocardlessAccountId
                    && $arg['name'] === 'Test Account'
                    && $arg['currency'] === 'GBP'
                    && $arg['institution_id'] === null;
            }))
            ->once()
            ->andReturn($mappedData);

        $this->accountRepository->shouldReceive('createForUser')
            ->with($this->user->id, $mappedData)
            ->once()
            ->andReturn($importedAccount);

        $result = $this->service->importAccount($gocardlessAccountId, $this->user);

        $this->assertEquals($importedAccount, $result);
    }

    /**
     * Test importAccount with already existing account
     */
    public function test_import_account_already_exists(): void
    {
        // Setup factory
        $this->setupFactoryMock();

        $gocardlessAccountId = 'gocardless_acc_123';

        $this->accountRepository->shouldReceive('gocardlessAccountExists')
            ->with($gocardlessAccountId, $this->user->id)
            ->once()
            ->andReturn(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Account already exists');

        $this->service->importAccount($gocardlessAccountId, $this->user);
    }

    /**
     * Test client initialization failure
     */
    public function test_client_initialization_failure(): void
    {
        $countryCode = 'GB';

        $this->clientFactoryMock->shouldReceive('make')
            ->with($this->user)
            ->once()
            ->andThrow(new \RuntimeException('Token error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to initialize GoCardless client: Token error');

        $this->service->getInstitutions($countryCode, $this->user);
    }
}
