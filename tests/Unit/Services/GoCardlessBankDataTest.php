<?php

namespace Tests\Unit\Services;

use App\Exceptions\GoCardlessApiException;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Services\GoCardless\GoCardlessBankDataClient;
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
    public function test_constructor_initializes_with_credentials(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'new_access_token',
                'refresh' => 'new_refresh_token',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
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
    public function test_token_refresh_when_expired(): void
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

        $service = new GoCardlessBankDataClient(
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
                   $request['refresh'] == 'valid_refresh_token';
        });
    }

    /**
     * Test fallback to new token when refresh token is expired
     */
    public function test_fallback_to_new_token_when_refresh_expired(): void
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

        $service = new GoCardlessBankDataClient(
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
    public function test_invalid_token_response_throws_exception(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'invalid' => 'response',
            ], 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid token response: missing required fields');

        new GoCardlessBankDataClient($this->secretId, $this->secretKey);
    }

    /**
     * Test token response with invalid data types
     */
    #[DataProvider('invalidTokenResponseProvider')]
    public function test_token_response_validation(array $response, string $expectedMessage): void
    {
        Http::fake([
            '*/token/new/' => Http::response($response, 200),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new GoCardlessBankDataClient($this->secretId, $this->secretKey);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
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
    public function test_create_end_user_agreement_success(): void
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

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
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
     * Test getAccounts does not cache — per-user data must never be cached in the shared store
     */
    public function test_get_accounts_does_not_cache(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/*' => Http::response([
                'id' => 'req_123',
                'accounts' => ['acc_1', 'acc_2'],
            ], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $accounts1 = $service->getAccounts('req_123');
        $this->assertEquals(['acc_1', 'acc_2'], $accounts1);

        $accounts2 = $service->getAccounts('req_123');
        $this->assertEquals(['acc_1', 'acc_2'], $accounts2);

        // Both calls must hit the API — no caching of per-user data (plus token request)
        Http::assertSentCount(3);
    }

    /**
     * Test getAccountDetails error handling
     */
    public function test_get_account_details_error_handling(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response(['error' => 'Account not found'], 404),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get account details');

        $service->getAccountDetails('invalid_account');
    }

    /**
     * Test getTransactions with pagination
     */
    public function test_get_transactions_handles_pagination(): void
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

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account', '2024-01-01', '2024-01-31');

        $this->assertArrayNotHasKey('pending', $result['transactions']);
        $this->assertSame([
            ['transactionId' => 'tx1', 'bookingDate' => '2024-01-01'],
            ['transactionId' => 'tx2', 'bookingDate' => '2024-01-02'],
            ['transactionId' => 'tx4', 'bookingDate' => '2024-01-04'],
        ], $result['transactions']['booked']);
    }

    /**
     * The `next` URL carries the page cursor in its query string. Sending the date filters again
     * alongside it would replace that query string and re-fetch page one forever.
     */
    public function test_next_page_is_followed_without_dropping_the_cursor(): void
    {
        $secondPageUrl = $this->baseUrl.'/accounts/test_account/transactions/?page=2';

        Http::fake(function (Request $request) use ($secondPageUrl) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('test_access_token');
            }

            if (str_contains($request->url(), 'page=2')) {
                return Http::response([
                    'transactions' => ['booked' => [['transactionId' => 'tx2']]],
                    'next' => null,
                ], 200);
            }

            return Http::response([
                'transactions' => ['booked' => [['transactionId' => 'tx1']]],
                'next' => $secondPageUrl,
            ], 200);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account', '2024-01-01', '2024-01-31');

        $this->assertSame(2, $result['pages_fetched']);
        $this->assertFalse($result['partial']);
        $this->assertCount(2, $result['transactions']['booked']);

        Http::assertSent(fn (Request $request) => $request->url() === $secondPageUrl);
    }

    /**
     * A cursor that never terminates must not spin forever.
     */
    public function test_pagination_stops_at_max_pages(): void
    {
        $page = 0;

        Http::fake(function (Request $request) use (&$page) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('test_access_token');
            }

            $page++;

            return Http::response([
                'transactions' => ['booked' => [['transactionId' => 'tx'.$page]]],
                'next' => $this->baseUrl.'/accounts/test_account/transactions/?page='.($page + 1),
            ], 200);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account');

        $this->assertTrue($result['partial']);
        $this->assertSame('max_pages', $result['partial_reason']);
        $this->assertSame(50, $result['pages_fetched']);
        $this->assertCount(50, $result['transactions']['booked']);
    }

    /**
     * A `next` URL pointing off the API host is never requested.
     */
    public function test_next_url_outside_base_url_is_not_followed(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('test_access_token');
            }

            return Http::response([
                'transactions' => ['booked' => [['transactionId' => 'tx1']]],
                'next' => 'https://evil.example.com/api/v2/accounts/test_account/transactions/?page=2',
            ], 200);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account');

        $this->assertTrue($result['partial']);
        $this->assertSame('invalid_next_url', $result['partial_reason']);
        $this->assertSame(1, $result['pages_fetched']);
        $this->assertCount(1, $result['transactions']['booked']);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    }

    /**
     * A rate limit after the first page keeps the pages already read instead of throwing them away.
     */
    public function test_rate_limit_on_second_page_returns_partial_results(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('test_access_token');
            }

            if (str_contains($request->url(), 'page=2')) {
                return Http::response(['detail' => 'Rate limited'], 429, ['X-Ratelimit-Account-Success-Reset' => '120']);
            }

            return Http::response([
                'transactions' => ['booked' => [['transactionId' => 'tx1']]],
                'next' => $this->baseUrl.'/accounts/test_account/transactions/?page=2',
            ], 200);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account');

        $this->assertTrue($result['partial']);
        $this->assertSame('rate_limited', $result['partial_reason']);
        $this->assertSame(1, $result['pages_fetched']);
        $this->assertSame([['transactionId' => 'tx1']], $result['transactions']['booked']);
    }

    /**
     * With nothing fetched yet there is no partial result worth returning — the caller must know.
     */
    public function test_rate_limit_on_first_page_throws(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/transactions/*' => Http::response(['detail' => 'Rate limited'], 429, [
                'X-Ratelimit-Account-Success-Reset' => '90',
            ]),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getTransactions('test_account');
            $this->fail('Expected GoCardlessRateLimitException');
        } catch (GoCardlessRateLimitException $e) {
            $this->assertSame(90, $e->retryAfterSeconds);
        }
    }

    /**
     * A 401 means the token was rejected before the request took effect, so one replay is safe.
     */
    public function test_401_triggers_token_refresh_and_single_retry(): void
    {
        $detailsCalls = 0;

        Http::fake(function (Request $request) use (&$detailsCalls) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('token_a', 'refresh_a');
            }

            if (str_contains($request->url(), '/token/refresh/')) {
                return $this->tokenResponse('token_b', 'refresh_b');
            }

            $detailsCalls++;

            return $detailsCalls === 1
                ? Http::response(['detail' => 'Invalid token'], 401)
                : Http::response(['account' => ['id' => 'acc_1']], 200);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getAccountDetails('acc_1');

        $this->assertSame('acc_1', $result['account']['id']);
        $this->assertSame(2, $detailsCalls);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/refresh/'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/details/')
            && $request->hasHeader('Authorization', 'Bearer token_b'));
    }

    /**
     * If the fresh token is rejected too, the failure is real — do not keep replaying.
     */
    public function test_second_401_is_not_retried_again(): void
    {
        $detailsCalls = 0;

        Http::fake(function (Request $request) use (&$detailsCalls) {
            if (str_contains($request->url(), '/token/new/')) {
                return $this->tokenResponse('token_a', 'refresh_a');
            }

            if (str_contains($request->url(), '/token/refresh/')) {
                return $this->tokenResponse('token_b', 'refresh_b');
            }

            $detailsCalls++;

            return Http::response(['detail' => 'Invalid token'], 401);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getAccountDetails('acc_1');
            $this->fail('Expected authentication failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to get account details', $e->getMessage());
        }

        $this->assertSame(2, $detailsCalls);
    }

    /**
     * The raw response body must never leak into the exception message, even for the 401 path —
     * it is redacted and logged separately under a correlation id instead.
     */
    public function test_401_after_failed_refresh_throws_without_body(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/token/new/')) {
                // Every token request returns the identical token, so the "refresh" attempt never
                // actually rotates it and the original 401 is left to surface as-is.
                return $this->tokenResponse('same_token');
            }

            return Http::response(['detail' => 'sk_live_topsecret_leak'], 401);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getAccountDetails('acc_1');
            $this->fail('Expected GoCardlessApiException');
        } catch (GoCardlessApiException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringStartsWith('Failed to get account details', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_topsecret_leak', $e->getMessage());
        }
    }

    /**
     * A non-auth API error must also never echo the response body back in the exception message.
     */
    public function test_api_error_message_excludes_response_body(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response(['error' => 'sk_live_topsecret'], 500),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getAccountDetails('acc_1');
            $this->fail('Expected GoCardlessApiException');
        } catch (GoCardlessApiException $e) {
            $this->assertStringStartsWith('Failed to get account details', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_topsecret', $e->getMessage());
            $this->assertSame(500, $e->status);
        }
    }

    /**
     * A 403 whose body names an expired End User Agreement is not a transient API failure —
     * it means the 90-day consent lapsed, and only the user re-authorizing can fix it.
     */
    public function test_403_naming_an_expired_consent_throws_consent_expired(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response([
                'summary' => 'Access expired',
                'detail' => 'Access to account has expired. Please re-authorize.',
                'type' => 'AccessExpiredError',
            ], 403),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $this->expectException(GoCardlessConsentExpiredException::class);
        $service->getAccountDetails('acc_1');
    }

    /**
     * Markers are matched case-insensitively — GoCardless is not consistent about casing
     * between the `type`, `summary` and `detail` fields.
     */
    public function test_consent_markers_are_matched_case_insensitively(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/balances/' => Http::response(['detail' => 'The ACCOUNT IS SUSPENDED by the institution'], 403),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $this->expectException(GoCardlessConsentExpiredException::class);
        $service->getBalances('acc_1');
    }

    /**
     * A 401 only reaches the consent check after send() already replayed the call with a fresh
     * token, so a consent marker there is about consent, not credentials.
     */
    public function test_401_naming_an_expired_consent_throws_consent_expired(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/token/new/')) {
                // Identical token every time, so the post-401 refresh never rotates it and the
                // original response is left to be classified.
                return $this->tokenResponse('same_token');
            }

            return Http::response(['detail' => 'EUA has expired'], 401);
        });

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $this->expectException(GoCardlessConsentExpiredException::class);
        $service->getAccountDetails('acc_1');
    }

    /**
     * The consent classification must stay narrow: an ordinary auth failure is still a plain
     * API error, or every credential problem would tell the user to reconnect their bank.
     */
    public function test_403_without_a_consent_marker_is_a_plain_api_exception(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response(['detail' => 'You do not have permission'], 403),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getAccountDetails('acc_1');
            $this->fail('Expected GoCardlessApiException');
        } catch (GoCardlessApiException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    /**
     * The consent exception is safe to surface: the body that identified it stays in the log.
     */
    public function test_consent_exception_message_excludes_the_response_body(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response([
                'type' => 'AccessExpiredError',
                'detail' => 'sk_live_topsecret_leak',
            ], 403),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        try {
            $service->getAccountDetails('acc_1');
            $this->fail('Expected GoCardlessConsentExpiredException');
        } catch (GoCardlessConsentExpiredException $e) {
            $this->assertSame('GoCardless consent expired or access suspended', $e->getMessage());
            $this->assertStringNotContainsString('sk_live_topsecret_leak', $e->getMessage());
        }
    }

    /**
     * A working token is never rotated needlessly.
     */
    public function test_successful_call_does_not_refresh(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/accounts/*/details/' => Http::response(['account' => ['id' => 'acc_1']], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $service->getAccountDetails('acc_1');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/token/refresh/'));
    }

    /**
     * Test getTransactions without pagination - empty result
     */
    public function test_get_transactions_empty_result(): void
    {
        $this->setupSuccessfulTokenResponse();

        Http::fake([
            '*/accounts/*/transactions/*' => Http::response([
                'transactions' => ['booked' => [], 'pending' => []],
                'next' => null,
            ], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getTransactions('test_account');

        $this->assertArrayHasKey('transactions', $result);
        $this->assertEmpty($result['transactions']['booked']);
    }

    /**
     * Test getBalances
     */
    public function test_get_balances_success(): void
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

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->getBalances('test_account');

        $this->assertEquals('1000.50', $result['balances'][0]['balanceAmount']['amount']);
    }

    /**
     * Test createRequisition
     */
    public function test_create_requisition_success(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/' => Http::response([
                'id' => 'req_new_123',
                'link' => 'https://ob.nordigen.com/psd2/start/req_new_123',
                'status' => 'CR',
            ], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
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
    public function test_delete_requisition_succeeds(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/*' => Http::response([], 204),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);
        $result = $service->deleteRequisition('req_123');

        $this->assertTrue($result);
    }

    /**
     * Per-user financial data (accounts, account metadata/details, balances, transactions,
     * requisitions) must never be written to the shared cache store — only tenant-independent
     * data (institutions) may be cached.
     */
    public function test_per_user_endpoints_never_write_to_cache(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/requisitions/*' => Http::response([
                'id' => 'req_123',
                'accounts' => ['acc_1'],
                'status' => 'LN',
            ], 200),
            '*/accounts/*/details/' => Http::response(['account' => ['id' => 'acc_1']], 200),
            '*/accounts/*/balances/' => Http::response(['balances' => []], 200),
            '*/accounts/*/transactions/*' => Http::response([
                'transactions' => ['booked' => []],
                'next' => null,
            ], 200),
            '*/accounts/*' => Http::response(['id' => 'acc_1', 'institution_id' => 'INST_1'], 200),
        ]);

        Cache::spy();

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey);

        $service->getAccounts('req_123');
        $service->getAccountDetails('acc_1');
        $service->getAccountMetadata('acc_1');
        $service->getBalances('acc_1');
        $service->getTransactions('acc_1');
        $service->getRequisitions('req_123');

        Cache::shouldNotHaveReceived('put');
    }

    /**
     * Test getInstitutions with caching
     */
    public function test_get_institutions_caches_results(): void
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

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey, null, null, null, null, true, 3600);

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
    public function test_disable_cache_always_fetches_fresh_data(): void
    {
        $this->setupSuccessfulTokenResponse();
        Http::fake([
            '*/institutions*' => Http::response([
                ['id' => 'INST_1', 'name' => 'Institution 1'],
            ], 200),
        ]);

        $service = new GoCardlessBankDataClient($this->secretId, $this->secretKey, null, null, null, null, false);

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
            '*/token/new/' => $this->tokenResponse('test_access_token'),
        ]);
    }

    /**
     * Build a well-formed token endpoint response.
     */
    private function tokenResponse(string $access, string $refresh = 'test_refresh_token'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'access' => $access,
            'refresh' => $refresh,
            'access_expires' => 3600,
            'refresh_expires' => 86400,
        ], 200);
    }
}
