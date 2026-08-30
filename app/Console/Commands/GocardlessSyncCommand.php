<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessSyncCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:sync
        {--account= : Local account ID to sync (required).}
        {--user= : User ID or email (default: first user)}
        {--no-update-existing : Do not update already imported transactions}
        {--force-max-date-range : Force sync from max days ago instead of last sync date}
        {--queue : Queue the sync instead of running it inline}';

    protected $description = 'Sync transactions for a single GoCardless-linked account. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accountIdInput = $this->option('account');
        if ($accountIdInput === null || $accountIdInput === '') {
            $this->error('Option --account= is required (local account ID).');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        $accountId = is_numeric($accountIdInput) ? (int) $accountIdInput : null;
        if ($accountId === null) {
            $this->error('Option --account= must be a numeric account ID.');

            return self::FAILURE;
        }

        $updateExisting = ! $this->option('no-update-existing');
        $forceMaxDateRange = $this->option('force-max-date-range');

        // Inline stays the default: the CLI contract is "run it and show me what happened",
        // which a queued dispatch cannot answer.
        if ($this->option('queue')) {
            SyncGoCardlessAccountJob::dispatch($accountId, (int) $user->id, $updateExisting, (bool) $forceMaxDateRange);
            $this->info("Queued sync for account {$accountId}.");

            return self::SUCCESS;
        }

        try {
            $result = $this->gocardlessService->syncAccountTransactions($accountId, $user, $updateExisting, $forceMaxDateRange);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $stats = $result['stats'] ?? [];
        $this->info('Sync completed.');
        $this->line('  Account ID: '.$result['account_id']);
        $this->line('  Created: '.($stats['created'] ?? 0));
        $this->line('  Updated: '.($stats['updated'] ?? 0));
        $this->line('  Skipped: '.($stats['skipped'] ?? 0));
        $this->line('  Balance updated: '.($result['balance_updated'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
