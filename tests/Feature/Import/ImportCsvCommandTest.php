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
        ])->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, Transaction::where('account_id', $account->id)->count());
    }

    public function test_import_csv_command_fails_without_account(): void
    {
        $this->artisan('import:csv', [
            'file' => 'tests/fixtures/minimal_import.csv',
        ])->assertFailed();
    }
}
