<?php

namespace Tests\Unit\Services;

use App\Services\GoCardlessBankData;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

class GoCardlessBankDataTest extends UnitTestCase
{
    private string $secretId = 'test_secret_id';

    private string $secretKey = 'test_secret_key';

    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    protected function setUp(): void
    {
        parent::setUp();

        // Use array cache driver for unit tests
        config(['cache.default' => 'array']);

        Cache::flush();
    }

    /**
     * Test successful token initialization with credentials
     */
    public function test_constructor_initializes_with_credentials()
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'new_access_token',
                'refresh' => 'new_refresh_token',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $tokens = $service->getSecretTokens();

        $this->assertEquals('new_access_token', $tokens['access']);
        $this->assertEquals('new_refresh_token', $tokens['refresh']);

        Http::assertSent(function (Request $request) {
            return $request->url() == $this->baseUrl.'/token/new/' &&
                   $request['secret_id'] == $this->secretId &&
                   $request['secret_key'] == $this->secretKey;
        });
    }

    /**
     * Test token refresh when access token is expired
     */
    public function test_token_refresh_when_expired()
    {
        Http::fake([
            '*/token/refresh/' => Http::response([
                'access' => 'refreshed_access_token',
                'refresh' => 'refreshed_refresh_token',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
            '*/accounts/*/details/' => Http::response(['account' => ['id' => 'test']], 200),
        ]);

        $expiredAccessToken = (new \DateTime)->sub(new \DateInterval('PT1H'));
        $validRefreshToken = (new \DateTime)->add(new \DateInterval('P1D'));

        $service = new GoCardlessBankData(
            $this->secretId,
            $this->secretKey,
            'expired_access_token',
            'valid_refresh_token',
            $validRefreshToken,
            $expiredAccessToken
        );

        // This should trigger a token refresh
        $service->getAccountDetails('test_account_id');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/token/refresh/') &&
                   $request['refresh_token'] == 'valid_refresh_token';
        });
    }

    /**
     * Test fallback to new token when refresh token is expired
     */
    public function test_fallback_to_new_token_when_refresh_expired()
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'brand_new_access_token',
                'refresh' => 'brand_new_refresh_token',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $expiredTokenTime = (new \DateTime)->sub(new \DateInterval('P1D'));

        $service = new GoCardlessBankData(
            $this->secretId,
            $this->secretKey,
            'expired_access_token',
            'expired_refresh_token',
            $expiredTokenTime,
            $expiredTokenTime
        );

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/token/new/');
        });
    }

    /**
     * Test invalid token response handling
     */
    public function test_invalid_token_response_throws_exception()
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'invalid' => 'response',
            ], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid token response: missing required fields');

        new GoCardlessBankData($this->secretId, $this->secretKey);
    }

    /**
     * Test token response with invalid data types
     */
    #[DataProvider('invalidTokenResponseProvider')]
    public function test_token_response_validation($response, $expectedMessage)
    {
        Http::fake([
            '*/token/new/' => Http::response($response, 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new GoCardlessBankData($this->secretId, $this->secretKey);
    }

    public static function invalidTokenResponseProvider(): array
    {
        return [
            'non-string access token' => [
                ['access' => 123, 'refresh' => 'token', 'access_expires' => 3600, 'refresh_expires' => 86400],
                'Invalid token response: access token must be a string',
            ],
            'non-string refresh token' => [
                ['access' => 'token', 'refresh' => ['array'], 'access_expires' => 3600, 'refresh_expires' => 86400],
                'Invalid token response: refresh token must be a string',
            ],
            'non-numeric access expires' => [
                ['access' => 'token', 'refresh' => 'token', 'access_expires' => 'invalid', 'refresh_expires' => 86400],
                'Invalid token response: access_expires must be numeric',
            ],
            'negative access expires' => [
                ['access' => 'token', 'refresh' => 'token', 'access_expires' => -100, 'refresh_expires' => 86400],
                'Invalid token response: access_expires must be positive',
            ],
        ];
    }

    /**
     * Test createEndUserAgreement
     */
    public function test_create_end_user_agreement_success()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/agreements/enduser/' => Http::response([
                'id' => 'agreement_123',
                'institution_id' => 'SANDBOXFINANCE_SFIN0000',
                'max_historical_days' => 90,
                'access_valid_for_days' => 90,
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->createEndUserAgreement('SANDBOXFINANCE_SFIN0000', ['user_id' => '123']);

        $this->assertEquals('agreement_123', $result['id']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/agreements/enduser/') &&
                   $request['institution_id'] == 'SANDBOXFINANCE_SFIN0000' &&
                   $request['max_historical_days'] == 90 &&
                   $request['access_valid_for_days'] == 90 &&
                   $request['access_scope'] == ['balances', 'details', 'transactions'];
        });
    }

    /**
     * Test getAccounts with caching
     */
    public function test_get_accounts_uses_cache()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/*' => Http::response([
                'id' => 'req_123',
                'accounts' => ['acc_1', 'acc_2'],
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);

        // First call should hit the API
        $accounts1 = $service->getAccounts('req_123');
        $this->assertEquals(['acc_1', 'acc_2'], $accounts1);

        // Second call should use cache (no additional HTTP call)
        $accounts2 = $service->getAccounts('req_123');
        $this->assertEquals(['acc_1', 'acc_2'], $accounts2);

        // Only one requisition request should be made (plus token request)
        Http::assertSentCount(2);
    }

    /**
     * Test getAccountDetails error handling
     */
    public function test_get_account_details_error_handling()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response(['error' => 'Account not found'], 404),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get account details');

        $service->getAccountDetails('invalid_account');
    }

    /**
     * Test getTransactions with pagination
     */
    public function test_get_transactions_handles_pagination()
    {
        $this->setupSuccessfulTokenResponse();

        // First page response
        Http::fake([
            $this->baseUrl.'/accounts/test_account/transactions/*' => Http::sequence([
                Http::response([
                    'transactions' => [
                        'booked' => [
                            ['transactionId' => 'tx1', 'bookingDate' => '2024-01-01'],
                            ['transactionId' => 'tx2', 'bookingDate' => '2024-01-02'],
                        ],
                        'pending' => [
                            ['transactionId' => 'tx3', 'valueDate' => '2024-01-03'],
                        ],
                    ],
                    'next' => $this->baseUrl.'/accounts/test_account/transactions/?page=2',
                ], 200),
                Http::response([
                    'transactions' => [
                        'booked' => [
                            ['transactionId' => 'tx4', 'bookingDate' => '2024-01-04'],
                        ],
                        'pending' => [],
                    ],
                    'next' => null,
                ], 200),
            ]),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account', '2024-01-01', '2024-01-31');

        $this->assertCount(3, $result['transactions']['booked']);
        $this->assertCount(1, $result['transactions']['pending']);
        $this->assertEquals('tx1', $result['transactions']['booked'][0]['transactionId']);
        $this->assertEquals('tx4', $result['transactions']['booked'][2]['transactionId']);
    }

    /**
     * Test getTransactions without pagination - empty result
     */
    public function test_get_transactions_empty_result()
    {
        $this->setupSuccessfulTokenResponse();

        Http::fake([
            '*/accounts/*/transactions/*' => Http::response([
                'transactions' => ['booked' => [], 'pending' => []],
                'next' => null,
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account');

        $this->assertArrayHasKey('transactions', $result);
        $this->assertEmpty($result['transactions']['booked']);
        $this->assertEmpty($result['transactions']['pending']);
    }

    /**
     * Test getBalances
     */
    public function test_get_balances_success()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/balances/' => Http::response([
                'balances' => [
                    [
                        'balanceType' => 'closingBooked',
                        'balanceAmount' => ['amount' => '1000.50', 'currency' => 'EUR'],
                    ],
                ],
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->getBalances('test_account');

        $this->assertEquals('1000.50', $result['balances'][0]['balanceAmount']['amount']);
    }

    /**
     * Test createRequisition
     */
    public function test_create_requisition_success()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/' => Http::response([
                'id' => 'req_new_123',
                'link' => 'https://ob.nordigen.com/psd2/start/req_new_123',
                'status' => 'CR',
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->createRequisition('SANDBOXFINANCE_SFIN0000', 'https://example.com/callback');

        $this->assertEquals('req_new_123', $result['id']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/requisitions/') &&
                   $request->method() === 'POST' &&
                   $request['institution_id'] == 'SANDBOXFINANCE_SFIN0000' &&
                   $request['redirect'] == 'https://example.com/callback' &&
                   $request['user_language'] == 'EN';
        });
    }

    /**
     * Test deleteRequisition
     */
    public function test_delete_requisition_clears_cache()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/*' => Http::response([], 204),
        ]);

        // Pre-populate cache
        Cache::put('gocardless_requisitions_req_123', ['id' => 'req_123'], 3600);
        Cache::put('gocardless_requisitions_all', ['results' => [['id' => 'req_123']]], 3600);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey);
        $result = $service->deleteRequisition('req_123');

        $this->assertTrue($result);
        $this->assertNull(Cache::get('gocardless_requisitions_req_123'));
        $this->assertNull(Cache::get('gocardless_requisitions_all'));
    }

    /**
     * Test getInstitutions with caching
     */
    public function test_get_institutions_caches_results()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/institutions*' => Http::response([
                [
                    'id' => 'SANDBOXFINANCE_SFIN0000',
                    'name' => 'Sandbox Finance',
                    'countries' => ['GB'],
                ],
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey, null, null, null, null, true, 3600);

        // First call
        $institutions1 = $service->getInstitutions('GB');
        $this->assertCount(1, $institutions1);

        // Second call should use cache
        $institutions2 = $service->getInstitutions('GB');
        $this->assertEquals($institutions1, $institutions2);

        // Only one institutions request should be made (plus token request)
        Http::assertSentCount(2);
    }

    /**
     * Test disabling cache
     */
    public function test_disable_cache_always_fetches_fresh_data()
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/institutions*' => Http::response([
                ['id' => 'INST_1', 'name' => 'Institution 1'],
            ], 200),
        ]);

        $service = new GoCardlessBankData($this->secretId, $this->secretKey, null, null, null, null, false);

        // Make two calls
        $service->getInstitutions('GB');
        $service->getInstitutions('GB');

        // Should make 2 institution requests (plus 1 token request)
        Http::assertSentCount(3);
    }

    /**
     * Helper method to setup successful token response
     */
    private function setupSuccessfulTokenResponse(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'test_access_token',
                'refresh' => 'test_refresh_token',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);
    }
}
