<?php

declare(strict_types=1);

namespace Tests\Feature\GoCardless;

use App\Exceptions\GoCardlessRateLimitException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoCardless\BalanceResolver;
use App\Services\GoCardless\GoCardlessBankDataClient;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\TokenManager;
use App\Services\GoCardless\TransactionDataValidator;
use App\Services\GoCardless\TransactionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('sandbox')]
class GoCardlessSandboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string SANDBOX_INSTITUTION = 'SANDBOXFINANCE_SFIN0000';

    private static ?GoCardlessBankDataClient $client = null;

    private static ?string $agreementId = null;

    private static ?string $requisitionId = null;

    /** @var list<string> */
    private static array $accountIds = [];

    private static ?string $primaryAccountId = null;

    /** @var array<string, mixed>|null @phpstan-ignore property.onlyWritten */
    private static ?array $cachedDetails = null;

    /** @var array<string, mixed>|null */
    private static ?array $cachedBalances = null;

    /** @var array{transactions?: array{booked?: list<array<string, mixed>>}}|null */
    private static ?array $cachedTransactions = null;

    /** @var array<string, mixed>|null @phpstan-ignore property.onlyWritten */
    private static ?array $cachedMetadata = null;

    private static function sandboxSecretId(): string
    {
        return (string) getenv('GOCARDLESS_SANDBOX_SECRET_ID');
    }

    private static function sandboxSecretKey(): string
    {
        return (string) getenv('GOCARDLESS_SANDBOX_SECRET_KEY');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::sandboxSecretId() === '' || self::sandboxSecretKey() === '') {
            $this->markTestSkipped('Sandbox credentials not set (GOCARDLESS_SANDBOX_SECRET_ID / GOCARDLESS_SANDBOX_SECRET_KEY)');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$requisitionId !== null && self::$client !== null) {
            try {
                self::$client->deleteRequisition(self::$requisitionId);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        self::$client = null;
        self::$agreementId = null;
        self::$requisitionId = null;
        self::$accountIds = [];
        self::$primaryAccountId = null;
        self::$cachedDetails = null;
        self::$cachedBalances = null;
        self::$cachedTransactions = null;
        self::$cachedMetadata = null;

        parent::tearDownAfterClass();
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function ensureClient(): GoCardlessBankDataClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $secretId = self::sandboxSecretId();
        $secretKey = self::sandboxSecretKey();

        if ($secretId === '' || $secretKey === '') {
            $this->markTestSkipped('Sandbox credentials not available');
        }

        try {
            self::$client = $this->retryOnRateLimit(
                fn () => new GoCardlessBankDataClient(
                    secretId: $secretId,
                    secretKey: $secretKey,
                    useCache: false
                )
            );
        } catch (ConnectionException $e) {
            $this->markTestSkipped('Network error: '.$e->getMessage());
        }

        return self::$client;
    }

    private function ensureRequisitionLinked(): void
    {
        if (self::$requisitionId !== null && self::$accountIds !== []) {
            return;
        }

        $client = $this->ensureClient();

        if (self::$agreementId === null) {
            /** @var array{id: string} $agreement */
            $agreement = $this->retryOnRateLimit(
                fn () => $client->createEndUserAgreement(self::SANDBOX_INSTITUTION, [])
            );
            self::$agreementId = $agreement['id'];
        }

        if (self::$requisitionId === null) {
            /** @var array{id: string} $requisition */
            $requisition = $this->retryOnRateLimit(
                fn () => $client->createRequisition(self::SANDBOX_INSTITUTION, 'https://localhost/callback', self::$agreementId)
            );
            self::$requisitionId = $requisition['id'];
        }

        $requisitionId = self::$requisitionId;

        /** @var list<string> $accounts */
        $accounts = $this->retryOnRateLimit(
            fn () => $client->getAccounts($requisitionId)
        );
        self::$accountIds = $accounts;

        if (self::$accountIds === []) {
            $this->markTestSkipped('Sandbox returned no accounts');
        }

        self::$primaryAccountId = self::$accountIds[0];
    }

    private function ensureAccountData(): void
    {
        if (self::$cachedTransactions !== null) {
            return;
        }

        $this->ensureRequisitionLinked();
        $client = $this->ensureClient();
        $accountId = self::$primaryAccountId;

        if ($accountId === null) {
            $this->markTestSkipped('No primary account ID available');
        }

        self::$cachedMetadata = $this->retryOnRateLimit(
            fn () => $client->getAccountMetadata($accountId)
        );

        self::$cachedDetails = $this->retryOnRateLimit(
            fn () => $client->getAccountDetails($accountId)
        );

        self::$cachedBalances = $this->retryOnRateLimit(
            fn () => $client->getBalances($accountId)
        );

        self::$cachedTransactions = $this->retryOnRateLimit(
            fn () => $client->getTransactions($accountId)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getBookedTransactions(): array
    {
        $this->ensureAccountData();

        /** @var list<array<string, mixed>> $booked */
        $booked = self::$cachedTransactions['transactions']['booked'] ?? [];

        return $booked;
    }

    private function createTestUserWithSandboxCredentials(): User
    {
        return User::factory()->create([
            'gocardless_secret_id' => self::sandboxSecretId(),
            'gocardless_secret_key' => self::sandboxSecretKey(),
            'gocardless_country' => 'GB',
        ]);
    }

    private function createTestAccountForSync(User $user): Account
    {
        return Account::factory()->create([
            'user_id' => $user->id,
            'gocardless_account_id' => self::$primaryAccountId,
            'gocardless_institution_id' => self::SANDBOX_INSTITUTION,
            'is_gocardless_synced' => true,
            'currency' => 'EUR',
            'type' => 'checking',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function retryOnRateLimit(callable $callback, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (GoCardlessRateLimitException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    $this->markTestSkipped('Rate limited after '.$maxRetries.' retries');
                }
                sleep(min($e->retryAfterSeconds, 30));
            } catch (ConnectionException $e) {
                $this->markTestSkipped('Network error: '.$e->getMessage());
            }
        }
    }

    // ─── Tests ────────────────────────────────────────────────

    public function test_token_acquisition(): void
    {
        $client = $this->ensureClient();
        $tokens = $client->getSecretTokens();

        $this->assertNotEmpty($tokens['access']);
        $this->assertNotEmpty($tokens['refresh']);
    }

    #[Depends('test_token_acquisition')]
    public function test_token_refresh(): void
    {
        $user = $this->createTestUserWithSandboxCredentials();

        $tokenManager = new TokenManager($user);

        $accessToken = $this->retryOnRateLimit(fn () => $tokenManager->getAccessToken());
        $this->assertNotEmpty($accessToken);

        $user->refresh();
        $this->assertNotNull($user->gocardless_access_token);
        $this->assertNotNull($user->gocardless_refresh_token);
        $this->assertNotNull($user->gocardless_access_token_expires_at);
        $this->assertNotNull($user->gocardless_refresh_token_expires_at);

        // Expire access token, force refresh path
        $user->update([
            'gocardless_access_token_expires_at' => now()->subMinutes(10),
        ]);

        $refreshedToken = $this->retryOnRateLimit(fn () => $tokenManager->getAccessToken());
        $this->assertNotEmpty($refreshedToken);

        $user->refresh();
        $this->assertTrue(
            Carbon::parse($user->gocardless_access_token_expires_at)->isFuture(),
            'Access token expiry should be in the future after refresh'
        );
    }

    #[Depends('test_token_acquisition')]
    public function test_list_institutions(): void
    {
        $client = $this->ensureClient();

        /** @var list<array<string, mixed>> $institutions */
        $institutions = $this->retryOnRateLimit(fn () => $client->getInstitutions('GB'));

        $this->assertNotEmpty($institutions);

        $sandbox = collect($institutions)->firstWhere('id', self::SANDBOX_INSTITUTION);
        $this->assertNotNull($sandbox, 'SANDBOXFINANCE_SFIN0000 should be in GB institutions');
        $this->assertArrayHasKey('name', $sandbox);
        $this->assertArrayHasKey('countries', $sandbox);
    }

    #[Depends('test_token_acquisition')]
    public function test_create_end_user_agreement(): void
    {
        $client = $this->ensureClient();

        /** @var array{id: string, institution_id: string, access_scope: mixed, access_valid_for_days: mixed} $agreement */
        $agreement = $this->retryOnRateLimit(
            fn () => $client->createEndUserAgreement(self::SANDBOX_INSTITUTION, [])
        );

        $this->assertArrayHasKey('id', $agreement);
        self::$agreementId = $agreement['id'];

        $this->assertArrayHasKey('institution_id', $agreement);
        $this->assertArrayHasKey('access_scope', $agreement);
        $this->assertArrayHasKey('access_valid_for_days', $agreement);
        $this->assertEquals(self::SANDBOX_INSTITUTION, $agreement['institution_id']);
    }

    #[Depends('test_create_end_user_agreement')]
    public function test_create_requisition(): void
    {
        $client = $this->ensureClient();

        /** @var array{id: string, link: string, status: string, institution_id: string} $requisition */
        $requisition = $this->retryOnRateLimit(
            fn () => $client->createRequisition(self::SANDBOX_INSTITUTION, 'https://localhost/callback', self::$agreementId)
        );

        $this->assertArrayHasKey('id', $requisition);
        self::$requisitionId = $requisition['id'];
        $this->assertArrayHasKey('link', $requisition);
        $this->assertArrayHasKey('status', $requisition);
        $this->assertArrayHasKey('institution_id', $requisition);
        $this->assertEquals('CR', $requisition['status']);
        $this->assertEquals(self::SANDBOX_INSTITUTION, $requisition['institution_id']);
    }

    #[Depends('test_create_requisition')]
    public function test_get_requisition_status(): void
    {
        $client = $this->ensureClient();
        $requisitionId = self::$requisitionId;
        $this->assertNotNull($requisitionId);

        /** @var array<string, mixed> $requisition */
        $requisition = $this->retryOnRateLimit(
            fn () => $client->getRequisitions($requisitionId)
        );

        $this->assertContains($requisition['status'], ['CR', 'LN']);
    }

    #[Depends('test_create_requisition')]
    public function test_get_accounts_from_requisition(): void
    {
        $client = $this->ensureClient();
        $requisitionId = self::$requisitionId;
        $this->assertNotNull($requisitionId);

        /** @var list<string> $accounts */
        $accounts = $this->retryOnRateLimit(
            fn () => $client->getAccounts($requisitionId)
        );

        self::$accountIds = $accounts;
        $this->assertNotEmpty($accounts, 'Sandbox should auto-link and return accounts');

        foreach ($accounts as $accountId) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $accountId
            );
        }

        self::$primaryAccountId = $accounts[0];
    }

    #[Depends('test_get_accounts_from_requisition')]
    public function test_get_account_metadata(): void
    {
        $this->ensureRequisitionLinked();
        $client = $this->ensureClient();
        $accountId = self::$primaryAccountId;
        $this->assertNotNull($accountId);

        /** @var array<string, mixed> $metadata */
        $metadata = $this->retryOnRateLimit(
            fn () => $client->getAccountMetadata($accountId)
        );

        self::$cachedMetadata = $metadata;

        $this->assertArrayHasKey('id', $metadata);
        $this->assertArrayHasKey('status', $metadata);
        $this->assertArrayHasKey('institution_id', $metadata);
        $this->assertEquals(self::$primaryAccountId, $metadata['id']);
    }

    #[Depends('test_get_accounts_from_requisition')]
    public function test_get_account_details(): void
    {
        $this->ensureRequisitionLinked();
        $client = $this->ensureClient();
        $accountId = self::$primaryAccountId;
        $this->assertNotNull($accountId);

        /** @var array<string, mixed> $details */
        $details = $this->retryOnRateLimit(
            fn () => $client->getAccountDetails($accountId)
        );

        self::$cachedDetails = $details;

        $this->assertArrayHasKey('account', $details);
        $this->assertIsArray($details['account']);
        $this->assertArrayHasKey('iban', $details['account']);
        $this->assertArrayHasKey('currency', $details['account']);
    }

    #[Depends('test_get_accounts_from_requisition')]
    public function test_get_account_balances(): void
    {
        $this->ensureRequisitionLinked();
        $client = $this->ensureClient();
        $accountId = self::$primaryAccountId;
        $this->assertNotNull($accountId);

        /** @var array<string, mixed> $response */
        $response = $this->retryOnRateLimit(
            fn () => $client->getBalances($accountId)
        );

        self::$cachedBalances = $response;

        $this->assertArrayHasKey('balances', $response);
        $this->assertIsArray($response['balances']);

        /** @var list<array{balanceType: string, balanceAmount: array{amount: string, currency: string}}> $balances */
        $balances = $response['balances'];
        $this->assertNotEmpty($balances);

        foreach ($balances as $balance) {
            $this->assertArrayHasKey('balanceType', $balance);
            $this->assertArrayHasKey('balanceAmount', $balance);
            $this->assertArrayHasKey('amount', $balance['balanceAmount']);
            $this->assertArrayHasKey('currency', $balance['balanceAmount']);
        }

        $resolved = BalanceResolver::resolve($balances);
        $this->assertNotNull($resolved, 'BalanceResolver should resolve a balance from sandbox data');
    }

    #[Depends('test_get_accounts_from_requisition')]
    public function test_get_transactions(): void
    {
        $this->ensureRequisitionLinked();
        $client = $this->ensureClient();
        $accountId = self::$primaryAccountId;
        $this->assertNotNull($accountId);

        /** @var array{transactions?: array{booked?: list<array<string, mixed>>}} $response */
        $response = $this->retryOnRateLimit(
            fn () => $client->getTransactions($accountId)
        );

        self::$cachedTransactions = $response;

        $this->assertArrayHasKey('transactions', $response);

        $booked = $this->getBookedTransactions();

        if ($booked === []) {
            $this->markTestIncomplete('Sandbox returned no booked transactions');
        }

        foreach ($booked as $tx) {
            $this->assertArrayHasKey('transactionId', $tx);
            $this->assertArrayHasKey('bookingDate', $tx);
            $this->assertArrayHasKey('transactionAmount', $tx);
            $this->assertIsArray($tx['transactionAmount']);
            $this->assertArrayHasKey('amount', $tx['transactionAmount']);
            $this->assertArrayHasKey('currency', $tx['transactionAmount']);
        }
    }

    #[Depends('test_get_transactions')]
    public function test_mapper_produces_valid_data(): void
    {
        $booked = $this->getBookedTransactions();
        if ($booked === []) {
            $this->markTestIncomplete('No transactions to map');
        }

        $user = $this->createTestUserWithSandboxCredentials();
        $account = $this->createTestAccountForSync($user);

        /** @var GocardlessMapper $mapper */
        $mapper = app(GocardlessMapper::class);
        $syncDate = Carbon::now();

        foreach ($booked as $tx) {
            $mapped = $mapper->mapTransactionData($tx, $account, $syncDate);

            $this->assertArrayHasKey('transaction_id', $mapped);
            $this->assertArrayHasKey('account_id', $mapped);
            $this->assertArrayHasKey('amount', $mapped);
            $this->assertArrayHasKey('currency', $mapped);
            $this->assertArrayHasKey('booked_date', $mapped);
            $this->assertArrayHasKey('description', $mapped);
            $this->assertArrayHasKey('type', $mapped);

            $this->assertIsNumeric($mapped['amount']);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) $mapped['currency']);
            $this->assertInstanceOf(Carbon::class, $mapped['booked_date']);
            $this->assertNotEmpty($mapped['description']);
            $this->assertContains($mapped['type'], [Transaction::TYPE_DEPOSIT, Transaction::TYPE_PAYMENT, Transaction::TYPE_TRANSFER]);
        }
    }

    #[Depends('test_mapper_produces_valid_data')]
    public function test_validator_accepts_mapped_data(): void
    {
        $booked = $this->getBookedTransactions();
        if ($booked === []) {
            $this->markTestIncomplete('No transactions to validate');
        }

        $user = $this->createTestUserWithSandboxCredentials();
        $account = $this->createTestAccountForSync($user);

        /** @var GocardlessMapper $mapper */
        $mapper = app(GocardlessMapper::class);
        $validator = new TransactionDataValidator;
        $syncDate = Carbon::now();

        foreach ($booked as $tx) {
            $mapped = $mapper->mapTransactionData($tx, $account, $syncDate);
            $result = $validator->validate($mapped, $syncDate);

            $this->assertFalse(
                $result->hasErrors(),
                'Validation errors for tx '.(is_string($tx['transactionId'] ?? null) ? $tx['transactionId'] : 'unknown').': '.implode(', ', $result->errors)
            );
        }
    }

    #[Depends('test_get_transactions')]
    public function test_full_sync_pipeline(): void
    {
        $booked = $this->getBookedTransactions();
        if ($booked === []) {
            $this->markTestIncomplete('No transactions to sync');
        }

        $user = $this->createTestUserWithSandboxCredentials();
        $account = $this->createTestAccountForSync($user);

        /** @var TransactionSyncService $syncService */
        $syncService = app(TransactionSyncService::class);

        $stats = $syncService->syncTransactions($booked, $account);

        $this->assertGreaterThan(0, $stats['created'], 'Should create transactions on first sync');

        $dbCount = Transaction::where('account_id', $account->id)->count();
        $this->assertGreaterThan(0, $dbCount);

        $withFingerprints = Transaction::where('account_id', $account->id)
            ->whereNotNull('fingerprint')
            ->count();
        $this->assertEquals($dbCount, $withFingerprints, 'All synced transactions should have fingerprints');

        if (self::$cachedBalances !== null) {
            /** @var list<array{balanceType: string, balanceAmount: array{amount: string}}> $balances */
            $balances = self::$cachedBalances['balances'] ?? [];
            if ($balances !== []) {
                $resolved = BalanceResolver::resolve($balances);
                $this->assertNotNull($resolved);
            }
        }
    }

    #[Depends('test_full_sync_pipeline')]
    public function test_deduplication_on_resync(): void
    {
        $booked = $this->getBookedTransactions();
        if ($booked === []) {
            $this->markTestIncomplete('No transactions for dedup test');
        }

        $user = $this->createTestUserWithSandboxCredentials();
        $account = $this->createTestAccountForSync($user);

        /** @var TransactionSyncService $syncService */
        $syncService = app(TransactionSyncService::class);

        // First sync
        $syncService->syncTransactions($booked, $account);
        $countAfterFirst = Transaction::where('account_id', $account->id)->count();

        // Second sync (same data)
        $stats = $syncService->syncTransactions($booked, $account);

        $this->assertEquals(0, $stats['created'], 'Re-sync should not create duplicates');
        $countAfterSecond = Transaction::where('account_id', $account->id)->count();
        $this->assertEquals($countAfterFirst, $countAfterSecond, 'DB record count should not change on re-sync');
    }
}
