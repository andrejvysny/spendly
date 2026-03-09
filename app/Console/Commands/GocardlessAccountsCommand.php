<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use Illuminate\Console\Command;

class GocardlessAccountsCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:accounts
        {--user= : User ID or email (default: first user)}';

    protected $description = 'List all GoCardless-linked accounts for a user.';

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

        $accounts = $this->accountRepository->getGocardlessSyncedAccounts($user->id);

        if ($accounts->isEmpty()) {
            $this->info('No GoCardless-linked accounts found.');

            return self::SUCCESS;
        }

        $rows = $accounts->map(fn ($a) => [
            $a->id,
            $a->name,
            $a->iban ?? '',
            $a->currency ?? '',
            $a->balance ?? '',
            $a->gocardless_last_synced_at?->toDateTimeString() ?? 'never',
        ])->toArray();

        $this->table(['ID', 'Name', 'IBAN', 'Currency', 'Balance', 'Last Synced'], $rows);

        return self::SUCCESS;
    }
}
