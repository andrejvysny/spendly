<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\User;
use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use App\Services\GoCardless\MockGoCardlessBankDataClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockGoCardlessBankDataClientTest extends TestCase
{
    use RefreshDatabase;

    private MockGoCardlessBankDataClient $client;

    private User $user;

    /** @var array<string> RequisitionDto field names required by frontend */
    private const REQUISITION_DTO_FIELDS = [
        'id', 'created', 'redirect', 'status', 'institution_id', 'agreement',
        'reference', 'accounts', 'user_language', 'link', 'ssn', 'account_selection', 'redirect_immediate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $fixtureRepository = new MockGoCardlessFixtureRepository(__DIR__.'/../../../nonexistent_fixture_path');
        $this->client = new MockGoCardlessBankDataClient($this->user, $fixtureRepository);
    }

    public function test_get_secret_tokens_returns_mock_data(): void
    {
        $tokens = $this->client->getSecretTokens();

        $this->assertIsArray($tokens);
        $this->assertArrayHasKey('access', $tokens);
        $this->assertArrayHasKey('refresh', $tokens);
        $this->assertEquals('mock_access_token', $tokens['access']);
    }

    public function test_create_end_user_agreement_returns_mock_data(): void
    {
        $result = $this->client->createEndUserAgreement('inst_id', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('inst_id', $result['institution_id']);
    }

    public function test_get_accounts_returns_mock_accounts(): void
    {
        $accounts = $this->client->getAccounts('req_id');

        $this->assertIsArray($accounts);
        $this->assertNotEmpty($accounts);
        $this->assertContains('mock_account_1', $accounts);
    }

    public function test_get_transactions_returns_mock_transactions(): void
    {
        $result = $this->client->getTransactions('acc_id');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertArrayHasKey('booked', $result['transactions']);
        $this->assertNotEmpty($result['transactions']['booked']);

        $transaction = $result['transactions']['booked'][0];
        $this->assertStringStartsWith('mock_tx_booked_', $transaction['transactionId']);
        $this->assertArrayHasKey('remittanceInformationUnstructuredArray', $transaction);
        $this->assertIsArray($transaction['remittanceInformationUnstructuredArray']);
    }

    public function test_get_balances_returns_mock_balance(): void
    {
        $result = $this->client->getBalances('acc_id');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('balances', $result);
        $this->assertEquals('1250.00', $result['balances'][0]['balanceAmount']['amount']);
    }

    public function test_get_requisitions_list_returns_paginated_shape_with_full_requisition_dto(): void
    {
        $redirectUrl = 'https://app.example.com/api/bank-data/gocardless/requisition/callback';
        $this->client->createRequisition('MOCK_INSTITUTION', $redirectUrl);

        $result = $this->client->getRequisitions(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('next', $result);
        $this->assertArrayHasKey('previous', $result);
        $this->assertSame(1, $result['count']);
        $this->assertIsArray($result['results']);
        $this->assertCount(1, $result['results']);

        foreach (self::REQUISITION_DTO_FIELDS as $field) {
            $this->assertArrayHasKey($field, $result['results'][0], "Requisition in list should have field: {$field}");
        }
    }

    public function test_get_requisitions_single_returns_one_requisition_not_wrapped_in_results(): void
    {
        $redirectUrl = 'https://app.example.com/callback';
        $created = $this->client->createRequisition('MOCK_INSTITUTION', $redirectUrl);
        $requisitionId = $created['id'];

        $result = $this->client->getRequisitions($requisitionId);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertSame($requisitionId, $result['id']);
        foreach (self::REQUISITION_DTO_FIELDS as $field) {
            $this->assertArrayHasKey($field, $result, "Single requisition should have field: {$field}");
        }
    }

    public function test_create_requisition_returns_link_pointing_to_redirect_url_for_mock_flow(): void
    {
        $redirectUrl = 'https://app.example.com/api/bank-data/gocardless/requisition/callback';
        $result = $this->client->createRequisition('MOCK_INSTITUTION', $redirectUrl);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('link', $result);
        $this->assertStringStartsWith($redirectUrl, $result['link']);
        $this->assertStringContainsString('mock=1', $result['link']);
        $this->assertStringContainsString('requisition_id='.$result['id'], $result['link']);
    }

    public function test_create_requisition_appends_ref_to_a_signed_redirect_url_without_breaking_it(): void
    {
        // A real redirect URL is a signed route: it already carries a query string, so the
        // mock must append with '&' — exactly like GoCardless appends `ref=` on redirect.
        $redirectUrl = 'https://app.example.com/api/bank-data/gocardless/requisition/callback'
            .'?reference=abc-123&expires=1700000000&signature=deadbeef';

        $result = $this->client->createRequisition('MOCK_INSTITUTION', $redirectUrl, null, 'abc-123');

        $this->assertStringStartsWith($redirectUrl.'&', $result['link']);
        $this->assertStringContainsString('&ref=abc-123', $result['link']);
        $this->assertSame('abc-123', $result['reference']);

        $query = [];
        parse_str((string) parse_url($result['link'], PHP_URL_QUERY), $query);
        $this->assertSame('deadbeef', $query['signature']);
        $this->assertSame('abc-123', $query['reference']);
        $this->assertSame('abc-123', $query['ref']);
    }

    public function test_create_requisition_omits_ref_when_no_reference_given(): void
    {
        $result = $this->client->createRequisition('MOCK_INSTITUTION', 'https://app.example.com/callback');

        $this->assertStringNotContainsString('ref=', $result['link']);
        $this->assertSame('', $result['reference']);
    }

    public function test_get_end_user_agreement_returns_an_access_window(): void
    {
        $agreement = $this->client->getEndUserAgreement('mock_agreement_1');

        $this->assertSame('mock_agreement_1', $agreement['id']);
        $this->assertSame(90, $agreement['access_valid_for_days']);
        $this->assertArrayHasKey('accepted', $agreement);
    }
}
