<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GocardlessSyncAllCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:sync-all
        {--user= : User ID or email (default: first user)}
        {--all : Sync all users with GoCardless-linked accounts (for scheduled runs)}
        {--no-update-existing : Do not update already imported transactions}
        {--force-max-date-range : Force sync from max days ago instead of last sync date}
        {--queue : Queue one job per account instead of syncing inline}';

    protected $description = 'Sync transactions for all GoCardless-linked accounts of the user. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService,
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $updateExisting = ! $this->option('no-update-existing');
        $forceMaxDateRange = (bool) $this->option('force-max-date-range');

        if ($this->option('all')) {
            return $this->syncAllUsers($updateExisting, $forceMaxDateRange);
        }

        $userOption = $this->option('user');
        $user = $this->resolveUser(is_string($userOption) ? $userOption : null);
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        if ($userOption === null || $userOption === '') {
            $this->info("No --user given; defaulting to first user (id={$user->id}). Use --user= or --all.");
        }

        return $this->syncUser($user, $updateExisting, $forceMaxDateRange);
    }

    /**
     * Sync a single user's GoCardless-linked accounts and print the results.
     */
    private function syncUser(User $user, bool $updateExisting, bool $forceMaxDateRange): int
    {
        if ($this->option('queue')) {
            $queued = $this->queueUser($user, $updateExisting, $forceMaxDateRange);
            $this->info("Queued {$queued} account sync job(s) for user {$user->id}.");

            return self::SUCCESS;
        }

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

        $this->printResults($results);

        return self::SUCCESS;
    }

    /**
     * Sync every user that has at least one GoCardless-linked account.
     * One user's failure must not stop the others.
     */
    private function syncAllUsers(bool $updateExisting, bool $forceMaxDateRange): int
    {
        $usersProcessed = 0;
        $accountsSynced = 0;
        $failures = 0;
        $queueOnly = (bool) $this->option('queue');

        $users = User::query()
            ->orderBy('id')
            ->whereHas('accounts', fn ($query) => $query->where('is_gocardless_synced', true))
            ->cursor();

        foreach ($users as $user) {
            $usersProcessed++;

            if ($queueOnly) {
                $accountsSynced += $this->queueUser($user, $updateExisting, $forceMaxDateRange);

                continue;
            }

            try {
                $results = $this->gocardlessService->syncAllAccounts($user, $updateExisting, $forceMaxDateRange);
            } catch (\Throwable $e) {
                $failures++;
                Log::error('gocardless:sync-all --all: sync failed for user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("User {$user->id}: sync failed - {$e->getMessage()}");

                continue;
            }

            if (empty($results)) {
                continue;
            }

            $this->line("User {$user->id}:");
            $this->printResults($results);

            foreach ($results as $r) {
                $accountsSynced++;
                if (($r['status'] ?? null) !== 'success') {
                    $failures++;
                }
            }
        }

        if ($queueOnly) {
            $this->info("Queued {$accountsSynced} account sync job(s) across {$usersProcessed} users.");

            return self::SUCCESS;
        }

        $this->info("Synced {$usersProcessed} users, {$accountsSynced} accounts, {$failures} failures.");

        return self::SUCCESS;
    }

    /**
     * Push one job per GoCardless-linked account the user owns.
     *
     * No stagger and no due-date filtering here: this is the explicit "sync my accounts now"
     * path. The scheduled, installation-wide equivalent is gocardless:dispatch-sync, which does
     * both. The job's unique lock still keeps repeat invocations from piling up.
     *
     * @return int Jobs pushed.
     */
    private function queueUser(User $user, bool $updateExisting, bool $forceMaxDateRange): int
    {
        $queued = 0;

        foreach ($this->accountRepository->getGocardlessSyncedAccounts((int) $user->id) as $account) {
            SyncGoCardlessAccountJob::dispatch((int) $account->id, (int) $user->id, $updateExisting, $forceMaxDateRange);
            $this->accountRepository->markSyncQueued($account);
            $queued++;
        }

        return $queued;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function printResults(array $results): void
    {
        foreach ($results as $r) {
            $status = $r['status'] ?? 'unknown';
            $aid = $this->toDisplayString($r['account_id'] ?? '');

            if ($status === 'success') {
                $statsRaw = $r['stats'] ?? [];
                $stats = is_array($statsRaw) ? $statsRaw : [];
                $created = $this->toDisplayString($stats['created'] ?? 0);
                $updated = $this->toDisplayString($stats['updated'] ?? 0);
                $this->line("Account {$aid}: created {$created}, updated {$updated}");
            } else {
                $error = $this->toDisplayString($r['error'] ?? 'error');
                $this->warn("Account {$aid}: {$error}");
            }
        }
    }

    /**
     * Safely stringify a value of unknown (mixed) shape for CLI output.
     */
    private function toDisplayString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
