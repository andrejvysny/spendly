<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessDeleteRequisitionCommand extends Command
{
    protected $signature = 'gocardless:delete-requisition
        {requisition_id : Requisition ID to delete.}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'Delete a GoCardless requisition by ID. For testing and AI agents.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
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
            $this->gocardlessService->deleteRequisition($requisitionId, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to delete requisition: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Requisition deleted successfully.');

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
