<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use Illuminate\Console\Command;

class GocardlessStatusCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:status
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Show GoCardless integration status for a user.';

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository
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

        $hasCredentials = $user->gocardless_secret_id !== null && $user->gocardless_secret_key !== null;
        $hasToken = $user->gocardless_access_token !== null;
        /** @var \Carbon\Carbon|null $tokenExpiry */
        $tokenExpiry = $user->gocardless_access_token_expires_at;
        $tokenValid = $hasToken && $tokenExpiry !== null && $tokenExpiry->isFuture();
        $mockMode = config('services.gocardless.use_mock', false);

        $accounts = $this->accountRepository->getGocardlessSyncedAccounts($user->id);

        $this->info("GoCardless Status (User #{$user->id}: {$user->email})");
        $this->line('');
        $this->line('  Credentials configured: '.($hasCredentials ? 'yes' : 'no'));
        $expiresStr = $tokenExpiry !== null ? $tokenExpiry->toDateTimeString() : '';
        $this->line('  Access token valid:     '.($tokenValid ? "yes (expires {$expiresStr})" : ($hasToken ? 'expired' : 'no')));
        $this->line('  Mock mode:              '.($mockMode ? 'yes' : 'no'));
        $this->line('  Linked accounts:        '.$accounts->count());

        if ($accounts->isNotEmpty()) {
            $this->line('');
            $rows = $accounts->map(fn ($a) => [
                $a->id,
                $a->name,
                $a->gocardless_last_synced_at?->toDateTimeString() ?? 'never',
            ])->toArray();
            $this->table(['ID', 'Name', 'Last Synced'], $rows);
        }

        return self::SUCCESS;
    }
}
