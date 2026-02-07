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
        $fixtureRepository = new MockGoCardlessFixtureRepository(__DIR__ . '/../../../nonexistent_fixture_path');
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
        $this->assertStringContainsString('requisition_id=' . $result['id'], $result['link']);
    }
}
