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
                            {--to= : End date (Y-m-d) for transaction range}
                            {--ml : Use ML fallback after rule-based detection}';

    protected $description = 'Detect same-day transfer pairs across user accounts and mark them as TRANSFER';

    public function handle(TransferDetectionService $service): int
    {
        $userIdOpt = $this->option('user');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $useMl = (bool) $this->option('ml');

        $from = $fromOpt !== null ? Carbon::parse($fromOpt)->startOfDay() : null;
        $to = $toOpt !== null ? Carbon::parse($toOpt)->endOfDay() : null;

        if ($userIdOpt !== null) {
            $userId = (int) $userIdOpt;

            if ($useMl) {
                $result = $service->detectTransfersWithMlFallback($userId, $from, $to);
                $this->info("User {$userId}: {$result['rule_matched']} rule-matched, {$result['ml_matched']} ML-matched.");
            } else {
                $updated = $service->detectAndMarkTransfersForUser($userId, $from, $to);
                $this->info("User {$userId}: {$updated} transaction(s) marked as transfer.");
            }

            return self::SUCCESS;
        }

        $userIds = User::query()->pluck('id')->all();

        if (empty($userIds)) {
            $this->info('No users found.');

            return self::SUCCESS;
        }

        $totalRule = 0;
        $totalMl = 0;
        foreach ($userIds as $uid) {
            if ($useMl) {
                $result = $service->detectTransfersWithMlFallback((int) $uid, $from, $to);
                if ($result['rule_matched'] > 0 || $result['ml_matched'] > 0) {
                    $this->info("User {$uid}: {$result['rule_matched']} rule-matched, {$result['ml_matched']} ML-matched.");
                    $totalRule += $result['rule_matched'];
                    $totalMl += $result['ml_matched'];
                }
            } else {
                $updated = $service->detectAndMarkTransfersForUser((int) $uid, $from, $to);
                if ($updated > 0) {
                    $this->info("User {$uid}: {$updated} transaction(s) marked as transfer.");
                    $totalRule += $updated;
                }
            }
        }

        if ($useMl) {
            $this->info("Total: {$totalRule} rule-matched, {$totalMl} ML-matched.");
        } else {
            $this->info("Total: {$totalRule} transaction(s) marked as transfer.");
        }

        return self::SUCCESS;
    }
}
