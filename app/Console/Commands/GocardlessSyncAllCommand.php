<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessSyncAllCommand extends Command
{
    protected $signature = 'gocardless:sync-all
        {--user= : User ID or email (default: first user)}
        {--no-update-existing : Do not update already imported transactions}
        {--force-max-date-range : Force sync from max days ago instead of last sync date}';

    protected $description = 'Sync transactions for all GoCardless-linked accounts of the user. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        $updateExisting = ! $this->option('no-update-existing');
        $forceMaxDateRange = $this->option('force-max-date-range');

        try {
            $results = $this->gocardlessService->syncAllAccounts($user, $updateExisting, $forceMaxDateRange);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($results)) {
            $this->info('No GoCardless-linked accounts to sync.');

            return self::SUCCESS;
        }

        foreach ($results as $r) {
            $status = $r['status'] ?? 'unknown';
            $aid = $r['account_id'] ?? '';
            if ($status === 'success') {
                $stats = $r['stats'] ?? [];
                $this->line("Account {$aid}: created ".($stats['created'] ?? 0).', updated '.($stats['updated'] ?? 0));
            } else {
                $this->warn("Account {$aid}: ".($r['error'] ?? 'error'));
            }
        }

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
