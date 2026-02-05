<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TransferDetectionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TransferDetectCommand extends Command
{
    protected $signature = 'transfers:detect
                            {--user= : Run for a specific user ID only}
                            {--from= : Start date (Y-m-d) for transaction range}
                            {--to= : End date (Y-m-d) for transaction range}';

    protected $description = 'Detect same-day transfer pairs across user accounts and mark them as TRANSFER';

    public function handle(TransferDetectionService $service): int
    {
        $userIdOpt = $this->option('user');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');

        $from = $fromOpt !== null ? Carbon::parse($fromOpt)->startOfDay() : null;
        $to = $toOpt !== null ? Carbon::parse($toOpt)->endOfDay() : null;

        if ($userIdOpt !== null) {
            $userId = (int) $userIdOpt;
            $updated = $service->detectAndMarkTransfersForUser($userId, $from, $to);
            $this->info("User {$userId}: {$updated} transaction(s) marked as transfer.");

            return self::SUCCESS;
        }

        $userIds = User::query()->pluck('id')->all();

        if (empty($userIds)) {
            $this->info('No users found.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($userIds as $uid) {
            $updated = $service->detectAndMarkTransfersForUser((int) $uid, $from, $to);
            if ($updated > 0) {
                $this->info("User {$uid}: {$updated} transaction(s) marked as transfer.");
                $total += $updated;
            }
        }

        $this->info("Total: {$total} transaction(s) marked as transfer.");

        return self::SUCCESS;
    }
}
