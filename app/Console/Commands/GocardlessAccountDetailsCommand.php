<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessAccountDetailsCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:account-details
        {gocardless_account_id : GoCardless account ID to fetch details for.}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Fetch raw account details and balances from GoCardless API. For debugging.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accountId = $this->argument('gocardless_account_id');
        if (! is_string($accountId) || $accountId === '') {
            $this->error('Argument gocardless_account_id is required.');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        try {
            $details = $this->gocardlessService->getAccountDetailsRaw($accountId, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch account details: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Account Details:');
        $this->line(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        try {
            $balances = $this->gocardlessService->getAccountBalancesRaw($accountId, $user);
        } catch (\Throwable $e) {
            $this->warn('Failed to fetch balances: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Balances:');
        $this->line(json_encode($balances, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
