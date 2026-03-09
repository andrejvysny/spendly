<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessDeleteRequisitionCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:delete-requisition
        {requisition_id : Requisition ID to delete.}
        {--user= : User ID or email (default: first user)}
        {--delete-imported-accounts : Also delete local accounts and transactions imported from this requisition}';

    protected $description = 'Delete a GoCardless requisition by ID. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService,
        private readonly AccountRepositoryInterface $accountRepository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requisitionId = $this->argument('requisition_id');
        if (! is_string($requisitionId) || $requisitionId === '') {
            $this->error('Argument requisition_id is required.');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        try {
            if ($this->option('delete-imported-accounts')) {
                $goCardlessAccountIds = $this->gocardlessService->getAccounts($requisitionId, $user);
                if ($goCardlessAccountIds !== []) {
                    $accounts = Account::where('user_id', $user->id)
                        ->whereIn('gocardless_account_id', $goCardlessAccountIds)
                        ->get();

                    foreach ($accounts as $account) {
                        $account->transactions()->delete();
                        $this->accountRepository->delete($account->id);
                        $this->line("  Deleted account: {$account->name} ({$account->id})");
                    }
                    $this->info("Deleted {$accounts->count()} imported account(s).");
                }
            }

            $this->gocardlessService->deleteRequisition($requisitionId, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to delete requisition: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Requisition deleted successfully.');

        return self::SUCCESS;
    }
}
