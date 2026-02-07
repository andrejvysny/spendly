<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessRequisitionsCommand extends Command
{
    protected $signature = 'gocardless:requisitions
        {--user= : User ID or email (default: first user)}';

    protected $description = 'List GoCardless requisitions for the user (for testing and AI agents).';

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

        try {
            $data = $this->gocardlessService->getRequisitionsList($user);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch requisitions: '.$e->getMessage());

            return self::FAILURE;
        }

        $results = $data['results'] ?? [];
        if (empty($results)) {
            $this->info('No requisitions found.');

            return self::SUCCESS;
        }

        foreach ($results as $req) {
            $accountIds = $req['accounts'] ?? [];
            $enriched = $accountIds !== [] ? $this->gocardlessService->getEnrichedAccountsForRequisition($accountIds, $user) : [];
            $this->line('Requisition: '.($req['id'] ?? ''));
            $this->line('  Institution: '.($req['institution_id'] ?? ''));
            $this->line('  Status: '.($req['status'] ?? ''));
            $this->line('  Accounts: '.implode(', ', $accountIds));
            foreach ($enriched as $acc) {
                $this->line('    - '.($acc['id'] ?? '').' | '.($acc['name'] ?? '').' | '.($acc['iban'] ?? '').' | '.($acc['status'] ?? ''));
            }
            $this->line('');
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
