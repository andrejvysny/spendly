<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Import\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferDetectionService;
use Illuminate\Console\Command;

class RepairFinanceDataCommand extends Command
{
    protected $signature = 'transactions:repair-finance-data
                            {--user= : Run for a specific user ID only}
                            {--dry-run : Show what would change without persisting}';

    protected $description = 'Repair finance data by recomputing fingerprints, clearing synthetic balances, fixing old single-leg transfers, and rerunning transfer pairing';

    public function handle(TransferDetectionService $transferDetectionService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userOption = $this->option('user');

        $users = $userOption !== null
            ? User::query()->whereKey((int) $userOption)->get()
            : User::query()->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->warn('No users found to repair.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $stats = $this->repairUser((int) $user->id, $transferDetectionService, $dryRun);

            $this->info(sprintf(
                'User %d: %d fingerprints %s, %d balances %s, %d transfers %s, %d transfers re-paired.',
                $user->id,
                $stats['fingerprints'],
                $dryRun ? 'would be updated' : 'updated',
                $stats['synthetic_balances'],
                $dryRun ? 'would be nulled' : 'nulled',
                $stats['single_leg_transfers'],
                $dryRun ? 'would be reset' : 'reset',
                $stats['repaired_pairs']
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{fingerprints:int, synthetic_balances:int, single_leg_transfers:int, repaired_pairs:int}
     */
    private function repairUser(int $userId, TransferDetectionService $transferDetectionService, bool $dryRun): array
    {
        $importsWithoutBalanceColumn = Import::query()
            ->where('user_id', $userId)
            ->get()
            ->filter(function (Import $import) {
                $mapping = $import->column_mapping ?? [];

                return ! array_key_exists('balance_after_transaction', $mapping)
                    || $mapping['balance_after_transaction'] === null;
            })
            ->pluck('id')
            ->flip();

        $stats = [
            'fingerprints' => 0,
            'synthetic_balances' => 0,
            'single_leg_transfers' => 0,
            'repaired_pairs' => 0,
        ];

        Transaction::query()
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->chunkById(200, function ($transactions) use (&$stats, $importsWithoutBalanceColumn, $dryRun) {
                foreach ($transactions as $transaction) {
                    $updates = [];

                    $newFingerprint = Transaction::generateFingerprint($transaction->toArray());
                    if ($transaction->fingerprint !== $newFingerprint) {
                        $updates['fingerprint'] = $newFingerprint;
                        $stats['fingerprints']++;
                    }

                    $importId = is_array($transaction->metadata ?? null)
                        ? ($transaction->metadata['import_id'] ?? null)
                        : null;
                    $isSyntheticCsvBalance = $transaction->balance_after_transaction !== null
                        && (float) $transaction->balance_after_transaction === 0.0
                        && $importId !== null
                        && $importsWithoutBalanceColumn->has((int) $importId);

                    if ($isSyntheticCsvBalance) {
                        $updates['balance_after_transaction'] = null;
                        $stats['synthetic_balances']++;
                    }

                    if ($transaction->type === Transaction::TYPE_TRANSFER && $transaction->transfer_pair_transaction_id === null) {
                        $updates['type'] = (float) $transaction->amount < 0
                            ? Transaction::TYPE_PAYMENT
                            : Transaction::TYPE_DEPOSIT;
                        $stats['single_leg_transfers']++;
                    }

                    if (! $dryRun && $updates !== []) {
                        $transaction->update($updates);
                    }
                }
            });

        if (! $dryRun) {
            $stats['repaired_pairs'] = $transferDetectionService->detectAndMarkTransfersForUser($userId);
        }

        return $stats;
    }
}
