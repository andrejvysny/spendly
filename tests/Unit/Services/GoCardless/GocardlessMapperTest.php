<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\Account;
use App\Services\GoCardless\FieldExtractors\FieldExtractorFactory;
use App\Services\GoCardless\GocardlessMapper;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures\GoCardlessFixtureLoader;
use Tests\TestCase;

#[Group('gocardless')]
#[Group('fixtures')]
class GocardlessMapperTest extends TestCase
{
    private GocardlessMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GocardlessMapper(new FieldExtractorFactory);
        if (! GoCardlessFixtureLoader::fixturesAvailable()) {
            $this->markTestSkipped('GoCardless fixture directory not available.');
        }
    }

    private function revolutAccount(): Account
    {
        $account = new Account;
        $account->id = 1;
        $account->user_id = 1;
        $account->gocardless_account_id = 'LT683250013083708433';
        $account->gocardless_institution_id = 'REVOLUT_REVOGB21';
        $account->bank_name = 'Revolut';
        $account->iban = 'LT683250013083708433';

        return $account;
    }

    private function slspAccount(): Account
    {
        $account = new Account;
        $account->id = 2;
        $account->user_id = 1;
        $account->gocardless_account_id = 'SK6809000000005183172536';
        $account->gocardless_institution_id = 'SLSP_GIBASKBX';
        $account->bank_name = 'Slovenská sporiteľňa';
        $account->iban = 'SK6809000000005183172536';

        return $account;
    }

    public function test_map_transaction_data_never_returns_empty_description_revolut(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $this->assertNotEmpty($transactions);
        $syncDate = Carbon::now();
        $account = $this->revolutAccount();

        foreach ($transactions as $tx) {
            $mapped = $this->mapper->mapTransactionData($tx, $account, $syncDate);
            $this->assertArrayHasKey('description', $mapped);
            $this->assertNotEmpty(trim((string) $mapped['description']), 'Description must not be empty for Revolut transaction');
        }
    }

    public function test_map_transaction_data_never_returns_empty_description_slsp(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $this->assertNotEmpty($transactions);
        $syncDate = Carbon::now();
        $account = $this->slspAccount();

        foreach ($transactions as $tx) {
            $mapped = $this->mapper->mapTransactionData($tx, $account, $syncDate);
            $this->assertArrayHasKey('description', $mapped);
            $this->assertNotEmpty(trim((string) $mapped['description']), 'Description must not be empty for SLSP transaction');
        }
    }

    public function test_map_transaction_data_includes_required_fields(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $this->assertNotEmpty($transactions);
        $tx = $transactions[0];
        $syncDate = Carbon::now();
        $account = $this->revolutAccount();

        $mapped = $this->mapper->mapTransactionData($tx, $account, $syncDate);

        $this->assertArrayHasKey('transaction_id', $mapped);
        $this->assertArrayHasKey('account_id', $mapped);
        $this->assertArrayHasKey('amount', $mapped);
        $this->assertArrayHasKey('currency', $mapped);
        $this->assertArrayHasKey('booked_date', $mapped);
        $this->assertArrayHasKey('description', $mapped);
        $this->assertArrayHasKey('type', $mapped);
        $this->assertSame($account->id, $mapped['account_id']);
    }

    public function test_map_transaction_data_slsp_includes_mcc_in_metadata_when_present(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $mccTx = null;
        foreach ($transactions as $tx) {
            if (isset($tx['remittanceInformationUnstructured']) && preg_match('/^MCC-\d{4}$/', $tx['remittanceInformationUnstructured'])) {
                $mccTx = $tx;
                break;
            }
        }
        $this->assertNotNull($mccTx);
        $syncDate = Carbon::now();
        $account = $this->slspAccount();
        $mapped = $this->mapper->mapTransactionData($mccTx, $account, $syncDate);
        $this->assertArrayHasKey('metadata', $mapped);
        $this->assertIsArray($mapped['metadata']);
        $this->assertArrayHasKey('mcc', $mapped['metadata']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $mapped['metadata']['mcc']);
    }
}
