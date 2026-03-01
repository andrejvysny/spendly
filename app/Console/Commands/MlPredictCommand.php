<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\MlService;
use Illuminate\Console\Command;

class MlPredictCommand extends Command
{
    protected $signature = 'ml:predict
        {task : Prediction task (categories, merchants, recurring, transfers)}
        {--user= : User ID}
        {--apply : Apply predictions to database}
        {--limit=100 : Max transactions to process}
        {--min-confidence=0.7 : Minimum confidence to apply}';

    protected $description = 'Run ML predictions via the ML service';

    public function handle(MlService $ml): int
    {
        $task = $this->argument('task');
        $userId = (int) $this->option('user');
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $minConfidence = (float) $this->option('min-confidence');

        if (! $userId) {
            $this->error('--user is required');
            return self::FAILURE;
        }

        if (! $ml->isAvailable()) {
            $this->error('ML service is not available. Check ML_ENABLED and ML_API_URL.');
            return self::FAILURE;
        }

        return match ($task) {
            'categories' => $this->predictCategories($ml, $userId, $apply, $limit, $minConfidence),
            'merchants' => $this->predictMerchants($ml, $userId, $apply, $limit, $minConfidence),
            'recurring' => $this->predictRecurring($ml, $userId),
            'transfers' => $this->predictTransfers($ml, $userId, $apply, $limit, $minConfidence),
            default => $this->invalidTask($task),
        };
    }

    private function predictCategories(MlService $ml, int $userId, bool $apply, int $limit, float $minConfidence): int
    {
        $this->info("Predicting categories for user #{$userId}...");
        $predictions = $ml->categorize($userId, limit: $limit);

        if (empty($predictions)) {
            $this->warn('No predictions returned.');
            return self::SUCCESS;
        }

        $this->displayPredictions($predictions, ['transaction_id', 'predicted_category_id', 'confidence', 'method', 'needs_review']);

        if ($apply) {
            $applied = 0;
            foreach ($predictions as $p) {
                if (($p['confidence'] ?? 0) >= $minConfidence && ! ($p['needs_review'] ?? false)) {
                    Transaction::where('id', $p['transaction_id'])
                        ->whereNull('category_id')
                        ->update(['category_id' => $p['predicted_category_id']]);
                    $applied++;
                }
            }
            $this->info("Applied {$applied}/" . count($predictions) . " predictions (min confidence: {$minConfidence}).");
        } else {
            $this->info('Dry run. Use --apply to write predictions to DB.');
        }

        return self::SUCCESS;
    }

    private function predictMerchants(MlService $ml, int $userId, bool $apply, int $limit, float $minConfidence): int
    {
        $this->info("Detecting merchants for user #{$userId}...");
        $predictions = $ml->detectMerchants($userId, limit: $limit);

        if (empty($predictions)) {
            $this->warn('No predictions returned.');
            return self::SUCCESS;
        }

        $this->displayPredictions($predictions, ['transaction_id', 'predicted_merchant_id', 'suggested_merchant_name', 'confidence', 'method']);

        if ($apply) {
            $applied = 0;
            foreach ($predictions as $p) {
                $merchantId = $p['predicted_merchant_id'] ?? null;
                if ($merchantId && ($p['confidence'] ?? 0) >= $minConfidence) {
                    Transaction::where('id', $p['transaction_id'])
                        ->whereNull('merchant_id')
                        ->update(['merchant_id' => $merchantId]);
                    $applied++;
                }
            }
            $this->info("Applied {$applied}/" . count($predictions) . " predictions (min confidence: {$minConfidence}).");
        } else {
            $this->info('Dry run. Use --apply to write predictions to DB.');
        }

        return self::SUCCESS;
    }

    private function predictRecurring(MlService $ml, int $userId): int
    {
        $this->info("Detecting recurring patterns for user #{$userId}...");
        $groups = $ml->detectRecurring($userId);

        if (empty($groups)) {
            $this->warn('No recurring patterns detected.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                $g['group_key'] ?? '',
                $g['frequency'] ?? '',
                round($g['interval_days'] ?? 0, 1),
                round($g['confidence'] ?? 0, 2),
                count($g['transaction_ids'] ?? []),
                isset($g['amount_stats']['mean']) ? round($g['amount_stats']['mean'], 2) : '-',
                $g['next_expected'] ?? '-',
            ];
        }

        $this->table(
            ['Group', 'Frequency', 'Interval (days)', 'Confidence', 'Transactions', 'Avg Amount', 'Next Expected'],
            $rows
        );

        $this->info(count($groups) . ' recurring pattern(s) detected.');
        return self::SUCCESS;
    }

    private function displayPredictions(array $predictions, array $columns): void
    {
        $rows = array_map(function (array $p) use ($columns) {
            return array_map(function (string $col) use ($p) {
                $val = $p[$col] ?? '-';
                if (is_bool($val)) {
                    return $val ? 'yes' : 'no';
                }
                if (is_float($val)) {
                    return round($val, 3);
                }
                return $val;
            }, $columns);
        }, array_slice($predictions, 0, 50));

        $this->table($columns, $rows);
        if (count($predictions) > 50) {
            $this->info('... and ' . (count($predictions) - 50) . ' more.');
        }
    }

    private function predictTransfers(MlService $ml, int $userId, bool $apply, int $limit, float $minConfidence): int
    {
        $this->info("Detecting transfers for user #{$userId}...");
        $predictions = $ml->detectTransfers($userId, $limit);

        if (empty($predictions)) {
            $this->warn('No transfer predictions returned.');
            return self::SUCCESS;
        }

        $this->displayPredictions($predictions, ['transaction_id', 'is_transfer', 'confidence', 'method', 'suggested_pair_id']);

        if ($apply) {
            $applied = 0;
            foreach ($predictions as $p) {
                if (($p['is_transfer'] ?? false) && ($p['confidence'] ?? 0) >= $minConfidence) {
                    Transaction::where('id', $p['transaction_id'])
                        ->where('type', '!=', 'TRANSFER')
                        ->update(['type' => 'TRANSFER']);
                    $applied++;

                    $pairId = $p['suggested_pair_id'] ?? null;
                    if ($pairId !== null) {
                        Transaction::where('id', $pairId)
                            ->where('type', '!=', 'TRANSFER')
                            ->update([
                                'type' => 'TRANSFER',
                                'transfer_pair_transaction_id' => $p['transaction_id'],
                            ]);
                        Transaction::where('id', $p['transaction_id'])
                            ->update(['transfer_pair_transaction_id' => $pairId]);
                        $applied++;
                    }
                }
            }
            $this->info("Applied {$applied} transfer marking(s) (min confidence: {$minConfidence}).");
        } else {
            $this->info('Dry run. Use --apply to write predictions to DB.');
        }

        return self::SUCCESS;
    }

    private function invalidTask(string $task): int
    {
        $this->error("Unknown task: {$task}. Valid: categories, merchants, recurring, transfers");
        return self::FAILURE;
    }
}
