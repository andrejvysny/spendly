<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MlService;
use Illuminate\Console\Command;

class MlTrainCommand extends Command
{
    protected $signature = 'ml:train
        {task : Task to train (categorizer, merchant-detector)}
        {--user= : User ID to train for}';

    protected $description = 'Train ML models via the ML service';

    public function handle(MlService $ml): int
    {
        $task = $this->argument('task');
        $userId = (int) $this->option('user');

        if (! $userId) {
            $this->error('--user is required');
            return self::FAILURE;
        }

        if (! $ml->isAvailable()) {
            $this->error('ML service is not available. Check ML_ENABLED and ML_API_URL.');
            return self::FAILURE;
        }

        $this->info("Training {$task} for user #{$userId}...");

        $result = match ($task) {
            'categorizer' => $ml->trainCategorizer($userId),
            'merchant-detector' => $ml->trainMerchantDetector($userId),
            default => null,
        };

        if ($result === null) {
            $this->error("Unknown task: {$task}. Valid: categorizer, merchant-detector");
            return self::FAILURE;
        }

        if (empty($result)) {
            $this->error('Training failed — empty response from ML service.');
            return self::FAILURE;
        }

        $status = $result['status'] ?? 'unknown';
        $message = $result['message'] ?? '';

        if ($status === 'success') {
            $this->info("Training complete: {$message}");

            if (isset($result['metrics'])) {
                $this->table(
                    ['Metric', 'Value'],
                    collect($result['metrics'])->map(fn ($v, $k) => [$k, is_numeric($v) ? round($v, 4) : $v])->values()->all()
                );
            }

            return self::SUCCESS;
        }

        $this->error("Training failed: {$message}");
        return self::FAILURE;
    }
}
