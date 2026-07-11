<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportCsvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_csv_command_imports_file_with_auto_detect(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'CLI Test Account',
        ]);

        $fixturePath = 'tests/fixtures/minimal_import.csv';
        $this->assertFileExists(base_path($fixturePath), 'Fixture CSV must exist');

        $this->artisan('import:csv', [
            'file' => $fixturePath,
            '--account' => (string) $account->id,
            '--date-format' => 'd.m.Y',
        ])->assertSuccessful();

        $this->assertDatabaseCount('transactions', 1);
        $tx = Transaction::where('account_id', $account->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame('-50.00', (string) $tx->amount);
        $this->assertStringContainsString('Test Partner', $tx->partner);
    }

    public function test_import_csv_command_with_account_name(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'ByName Account',
        ]);

        $fixturePath = 'tests/fixtures/minimal_import.csv';

        $this->artisan('import:csv', [
            'file' => $fixturePath,
            '--account' => 'ByName Account',
            '--date-format' => 'd.m.Y',
        ])->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, Transaction::where('account_id', $account->id)->count());
    }

    public function test_import_csv_command_fails_without_account(): void
    {
        $this->artisan('import:csv', [
            'file' => 'tests/fixtures/minimal_import.csv',
        ])->assertFailed();
    }

    public function test_import_csv_command_detects_processed_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Processed Date Test',
        ]);

        $this->artisan('import:csv', [
            'file' => 'tests/fixtures/import_with_processed_date.csv',
            '--account' => (string) $account->id,
            '--date-format' => 'd.m.Y',
        ])->assertSuccessful();

        $tx = Transaction::where('account_id', $account->id)->first();
        $this->assertNotNull($tx);
        // processed_date should differ from booked_date (17.01 vs 15.01)
        $this->assertNotEquals($tx->booked_date->format('Y-m-d'), $tx->processed_date->format('Y-m-d'));
        $this->assertSame('2025-01-17', $tx->processed_date->format('Y-m-d'));
    }

    public function test_import_csv_command_tiebreak_prefers_first_column(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Tiebreak Test',
        ]);

        // Both "Date" and "Booking Date" match booked_date — first column (index 0) should win
        $this->artisan('import:csv', [
            'file' => 'tests/fixtures/import_tiebreak.csv',
            '--account' => (string) $account->id,
            '--date-format' => 'd.m.Y',
        ])->assertSuccessful();

        $tx = Transaction::where('account_id', $account->id)->first();
        $this->assertNotNull($tx);
        // Column 0 "Date" = 15.01.2025 should be the booked_date (first column wins tie)
        $this->assertSame('2025-01-15', $tx->booked_date->format('Y-m-d'));
    }

    public function test_import_csv_command_maps_partner_iban_and_survives_mixed_optional_columns(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Partner IBAN Test',
        ]);

        // Rows alternate between filled and empty "IBAN partnera" - the batch
        // insert must tolerate the differing optional keys, and metadata must
        // survive as a proper json object (no double encoding via any fallback).
        $result = $this->artisan('import:csv', [
            'file' => 'tests/fixtures/import_partner_iban_mixed.csv',
            '--account' => (string) $account->id,
            '--delimiter' => ';',
            '--date-format' => 'd.m.Y',
        ]);
        assert($result instanceof \Illuminate\Testing\PendingCommand);
        // PendingCommand executes lazily (on destruct) - run explicitly before
        // asserting against the database.
        $result->assertSuccessful()->run();

        $txs = Transaction::where('account_id', $account->id)->orderBy('booked_date')->get();
        $this->assertCount(3, $txs);

        $outgoing = $txs[0];
        $this->assertInstanceOf(Transaction::class, $outgoing);
        $this->assertSame('DE89370400440532013000', $outgoing->target_iban);
        $this->assertNull($outgoing->source_iban);
        $this->assertIsArray($outgoing->metadata);
        $this->assertTrue((bool) ($outgoing->metadata['transfer_candidate'] ?? false));

        $card = $txs[1];
        $this->assertInstanceOf(Transaction::class, $card);
        $this->assertNull($card->target_iban);
        $this->assertNull($card->source_iban);
        $metadata = $card->metadata;
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('import_id', $metadata);

        $incoming = $txs[2];
        $this->assertInstanceOf(Transaction::class, $incoming);
        $this->assertSame('GB29NWBK60161331926819', $incoming->source_iban);
        $this->assertNull($incoming->target_iban);
        $this->assertIsArray($incoming->metadata);
    }
}
