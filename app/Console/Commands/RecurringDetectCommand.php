<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RecurringDetectionSetting;
use App\Services\RecurringDetectionService;
use Illuminate\Console\Command;

class RecurringDetectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurring:detect
                            {--user= : Run for a specific user ID only}
                            {--account= : Run for a specific account ID only (implies user)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run recurring payment detection for users with scheduled detection enabled';

    public function handle(RecurringDetectionService $service): int
    {
        $userIdOpt = $this->option('user');
        $accountIdOpt = $this->option('account');
        $accountId = $accountIdOpt !== null ? (int) $accountIdOpt : null;
        $userId = $userIdOpt !== null ? (int) $userIdOpt : null;

        if ($accountId !== null && $userId === null) {
            $account = \App\Models\Account::find($accountId);
            if ($account === null) {
                $this->error("Account {$accountId} not found.");

                return self::FAILURE;
            }
            $userId = $account->user_id;
        }

        if ($userId !== null) {
            $this->runForUser($service, $userId, $accountId);

            return self::SUCCESS;
        }

        $userIds = RecurringDetectionSetting::where('scheduled_enabled', true)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        if (empty($userIds)) {
            $this->info('No users with scheduled recurring detection enabled.');

            return self::SUCCESS;
        }

        foreach ($userIds as $uid) {
            $this->runForUser($service, (int) $uid, null);
        }

        return self::SUCCESS;
    }

    private function runForUser(RecurringDetectionService $service, int $userId, ?int $accountId): void
    {
        $this->info("Running recurring detection for user {$userId}".($accountId !== null ? " account {$accountId}" : ''));
        $created = $service->runForUser($userId, $accountId);
        $this->info("  -> {$created} suggestion(s) created/updated.");
    }
}
