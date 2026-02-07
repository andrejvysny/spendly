<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessRefreshBalanceCommand extends Command
{
    protected $signature = 'gocardless:refresh-balance
        {--account= : Local account ID to refresh balance for (required).}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Refresh account balance from GoCardless API for a single linked account. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService,
        private readonly AccountRepositoryInterface $accountRepository
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

        $account = $this->accountRepository->findByIdForUser($accountId, $user->id);
        if ($account === null) {
            $this->error('Account not found or does not belong to user.');

            return self::FAILURE;
        }

        $updated = $this->gocardlessService->refreshAccountBalance($account);
        if (! $updated) {
            $this->warn('Balance could not be updated (account may not be GoCardless-linked or no closing balance in API).');

            return self::SUCCESS;
        }

        $account->refresh();
        $this->info('Balance refreshed successfully.');
        $this->line('  Account ID: '.$account->id);
        $this->line('  Balance: '.$account->balance.' '.($account->currency ?? ''));

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
