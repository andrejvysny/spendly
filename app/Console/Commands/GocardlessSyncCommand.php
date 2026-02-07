<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessSyncCommand extends Command
{
    protected $signature = 'gocardless:sync
        {--account= : Local account ID to sync (required).}
        {--user= : User ID or email (default: first user)}
        {--no-update-existing : Do not update already imported transactions}
        {--force-max-date-range : Force sync from max days ago instead of last sync date}';

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

    private function resolveUser(?string $userInput): ?User
    {
        if ($userInput === null || $userInput === '') {
            return User::query()->orderBy('id')->first();
        }
        if (is_numeric($userInput)) {
            return User::find((int) $userInput);
        }

        return User::query()->where('email', $userInput)->first();
    }
}
