<?php

namespace Tests\Unit\Services\GoCardless;

use App\Models\User;
use App\Services\GoCardless\MockGoCardlessBankDataClient;
use Tests\TestCase;

class MockGoCardlessBankDataClientTest extends TestCase
{
    private MockGoCardlessBankDataClient $client;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User();
        $this->client = new MockGoCardlessBankDataClient($this->user);
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
    }

    public function test_get_balances_returns_mock_balance(): void
    {
        $result = $this->client->getBalances('acc_id');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('balances', $result);
        $this->assertEquals('1250.00', $result['balances'][0]['balanceAmount']['amount']);
    }
}
