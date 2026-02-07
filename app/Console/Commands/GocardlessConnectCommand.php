<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessConnectCommand extends Command
{
    protected $signature = 'gocardless:connect
        {--institution= : Institution ID (e.g. Revolut, SLSP). Required.}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Create a requisition and import all linked accounts (simulates callback flow for CLI/mock). For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $institutionId = $this->option('institution');
        if ($institutionId === null || $institutionId === '') {
            $this->error('Option --institution= is required (e.g. --institution=Revolut or --institution=SLSP).');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        $baseUrl = config('app.url');
        $redirectUrl = (is_string($baseUrl) ? $baseUrl : 'https://example.com').'/api/bank-data/gocardless/requisition/callback';

        try {
            $requisition = $this->gocardlessService->createRequisition($institutionId, $redirectUrl, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to create requisition: '.$e->getMessage());

            return self::FAILURE;
        }

        $requisitionId = $requisition['id'] ?? null;
        if ($requisitionId === null) {
            $this->error('Requisition created but no ID returned.');

            return self::FAILURE;
        }

        $this->info('Requisition created: '.$requisitionId);

        try {
            $accountIds = $this->gocardlessService->getAccounts($requisitionId, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to get accounts for requisition: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($accountIds)) {
            $this->info('No accounts linked to this requisition.');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;
        foreach ($accountIds as $goCardlessAccountId) {
            try {
                $this->gocardlessService->importAccount($goCardlessAccountId, $user);
                $this->line("  Imported: {$goCardlessAccountId}");
                $imported++;
            } catch (AccountAlreadyExistsException) {
                $this->line("  Skipped (already exists): {$goCardlessAccountId}");
                $skipped++;
            } catch (\Throwable $e) {
                $this->warn("  Failed to import {$goCardlessAccountId}: ".$e->getMessage());
            }
        }

        $this->info("Done. Imported: {$imported}, skipped: {$skipped}.");

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
