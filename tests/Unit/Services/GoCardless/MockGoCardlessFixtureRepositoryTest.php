<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MockGoCardlessFixtureRepositoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/gocardless_fixture_test_' . uniqid();
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_has_fixture_data_returns_false_when_dir_empty(): void
    {
        $repo = new MockGoCardlessFixtureRepository($this->tempDir);

        $this->assertFalse($repo->hasFixtureData());
    }

    public function test_has_fixture_data_returns_false_when_dir_missing(): void
    {
        $repo = new MockGoCardlessFixtureRepository($this->tempDir . '/nonexistent');

        $this->assertFalse($repo->hasFixtureData());
    }

    public function test_discovers_institution_and_account_from_details_file(): void
    {
        File::makeDirectory($this->tempDir . '/TestBank', 0755, true);
        $details = [
            'account' => [
                'resourceId' => 'acc-123',
                'iban' => 'SK123',
                'currency' => 'EUR',
            ],
        ];
        File::put(
            $this->tempDir . '/TestBank/acc-123_details.json',
            json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        $repo = new MockGoCardlessFixtureRepository($this->tempDir);

        $this->assertTrue($repo->hasFixtureData());

        $institutions = $repo->getInstitutions('sk');
        $this->assertCount(1, $institutions);
        $this->assertSame('TestBank', $institutions[0]['id']);

        $accountIds = $repo->getAccountIdsForInstitution('TestBank');
        $this->assertSame(['acc-123'], $accountIds);

        $payload = $repo->getAccountDetailsPayload('acc-123');
        $this->assertNotNull($payload);
        $this->assertArrayHasKey('account', $payload);
        $this->assertSame('acc-123', $payload['account']['resourceId']);

        $this->assertNull($repo->getAccountDetailsPayload('unknown-account'));
    }

    public function test_get_balances_payload_returns_null_for_unknown_account(): void
    {
        $repo = new MockGoCardlessFixtureRepository($this->tempDir);

        $this->assertNull($repo->getBalancesPayload('unknown'));
    }

    public function test_get_balances_payload_normalizes_closing_booked(): void
    {
        File::makeDirectory($this->tempDir . '/Bank', 0755, true);
        File::put(
            $this->tempDir . '/Bank/acc_balances.json',
            json_encode([
                'balances' => [
                    [
                        'balanceType' => 'interimAvailable',
                        'balanceAmount' => ['amount' => '100.00', 'currency' => 'EUR'],
                        'referenceDate' => '2026-02-05',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
        File::put(
            $this->tempDir . '/Bank/acc_details.json',
            json_encode(['account' => ['resourceId' => 'acc', 'currency' => 'EUR']], JSON_THROW_ON_ERROR)
        );

        $repo = new MockGoCardlessFixtureRepository($this->tempDir);
        $payload = $repo->getBalancesPayload('acc');

        $this->assertNotNull($payload);
        $types = array_column($payload['balances'], 'balanceType');
        $this->assertContains('closingBooked', $types);
    }

    public function test_get_transactions_payload_returns_null_for_unknown_account(): void
    {
        $repo = new MockGoCardlessFixtureRepository($this->tempDir);

        $this->assertNull($repo->getTransactionsPayload('unknown'));
    }
}
