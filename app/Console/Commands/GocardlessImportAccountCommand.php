<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessImportAccountCommand extends Command
{
    protected $signature = 'gocardless:import-account
        {gocardless_account_id : GoCardless account ID (e.g. from fixtures: LT683250013083708433).}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Import a single GoCardless account by its API account ID. For testing and AI agents.';

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
            $account = $this->gocardlessService->importAccount($accountId, $user);
        } catch (AccountAlreadyExistsException) {
            $this->warn('Account already exists for this user.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to import account: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Account imported successfully.');
        $this->line('  Local account ID: '.$account->id);
        $this->line('  Name: '.$account->name);
        $this->line('  IBAN: '.($account->iban ?? ''));
        $this->line('  Currency: '.($account->currency ?? ''));

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
