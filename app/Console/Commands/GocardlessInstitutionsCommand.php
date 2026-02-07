<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;

class GocardlessInstitutionsCommand extends Command
{
    protected $signature = 'gocardless:institutions
        {--country= : Two-letter country code (e.g. gb, sk). Required.}
        {--user= : User ID or email (default: first user)}';

    protected $description = 'List GoCardless institutions for a country (for testing and AI agents). Uses mock when configured.';

    public function __construct(
        private readonly GoCardlessService $gocardlessService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $country = $this->option('country');
        if ($country === null || $country === '') {
            $this->error('Option --country= is required (e.g. --country=gb or --country=sk).');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        try {
            $institutions = $this->gocardlessService->getInstitutions($country, $user);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch institutions: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($institutions)) {
            $this->info('No institutions found.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $i): array => [
            $i['id'] ?? '',
            $i['name'] ?? '',
            $i['bic'] ?? '',
        ], $institutions);
        $this->table(['ID', 'Name', 'BIC'], $rows);

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
