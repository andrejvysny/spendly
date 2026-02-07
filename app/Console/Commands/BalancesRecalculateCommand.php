<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Services\AccountBalanceService;
use Illuminate\Console\Command;

class BalancesRecalculateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balances:recalculate
                            {--account= : Recalculate balance for a specific account ID}
                            {--user= : Recalculate balances for all accounts of a specific user ID}
                            {--use-api : Use GoCardless API to refresh balances for synced accounts}
                            {--all : Recalculate balances for all accounts in the system}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate account balances from transactions or GoCardless API';

    public function __construct(
        private readonly AccountBalanceService $balanceService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account');
        $userId = $this->option('user');
        $useApi = $this->option('use-api');
        $all = $this->option('all');

        if ($accountId) {
            return $this->recalculateSingleAccount((int) $accountId, $useApi);
        }

        if ($userId) {
            return $this->recalculateForUser((int) $userId, $useApi);
        }

        if ($all) {
            return $this->recalculateAll($useApi);
        }

        $this->error('Please specify --account=ID, --user=ID, or --all');

        return self::FAILURE;
    }

    /**
     * Recalculate balance for a single account.
     */
    private function recalculateSingleAccount(int $accountId, bool $useApi): int
    {
        $account = Account::find($accountId);

        if (! $account) {
            $this->error("Account with ID {$accountId} not found");

            return self::FAILURE;
        }

        $this->info("Recalculating balance for account: {$account->name} (ID: {$account->id})");

        try {
            $success = false;

            if ($useApi && $account->is_gocardless_synced && $account->gocardless_account_id) {
                $this->line('  Using GoCardless API...');
                $success = $this->balanceService->refreshAccountBalanceFromApi($account);
            } else {
                $this->line('  Using transaction data...');
                $success = $this->balanceService->recalculateForAccount($account);
            }

            if ($success) {
                $account->refresh();
                $this->info("  Balance updated: {$account->balance} {$account->currency}");

                return self::SUCCESS;
            }

            $this->warn('  Balance could not be updated (no data available)');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("  Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Recalculate balances for all accounts of a user.
     */
    private function recalculateForUser(int $userId, bool $useApi): int
    {
        $user = User::find($userId);

        if (! $user) {
            $this->error("User with ID {$userId} not found");

            return self::FAILURE;
        }

        $this->info("Recalculating balances for user: {$user->email} (ID: {$user->id})");

        $results = $this->balanceService->recalculateAllForUser($userId, $useApi);

        $this->displayResults($results);

        return $results['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Recalculate balances for all accounts in the system.
     */
    private function recalculateAll(bool $useApi): int
    {
        if (! $this->confirm('This will recalculate balances for ALL accounts. Continue?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $users = User::all();
        $totalSuccess = 0;
        $totalFailed = 0;
        $allErrors = [];

        $this->info('Recalculating balances for all users...');
        $this->newLine();

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            $results = $this->balanceService->recalculateAllForUser($user->id, $useApi);
            $totalSuccess += $results['success'];
            $totalFailed += $results['failed'];
            $allErrors = array_merge($allErrors, $results['errors']);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->displayResults([
            'success' => $totalSuccess,
            'failed' => $totalFailed,
            'errors' => $allErrors,
        ]);

        return $totalFailed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Display recalculation results.
     *
     * @param  array{success: int, failed: int, errors: array<string>}  $results
     */
    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->info("Results:");
        $this->line("  Success: {$results['success']}");
        $this->line("  Failed: {$results['failed']}");

        if (! empty($results['errors'])) {
            $this->newLine();
            $this->warn('Errors:');
            foreach ($results['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }
    }
}
