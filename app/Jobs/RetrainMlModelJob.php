<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Listeners\TrackManualCategorization;
use App\Models\MlPersonalizationSetting;
use App\Services\MlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RetrainMlModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly int $userId) {}

    public function handle(MlService $mlService): void
    {
        if (! $mlService->isAvailable()) {
            throw new \RuntimeException('ML service is unavailable');
        }

        $response = $mlService->trainCategorizer($this->userId);

        if (($response['status'] ?? null) !== 'success') {
            throw new \RuntimeException((string) ($response['message'] ?? 'ML retraining failed'));
        }

        $settings = MlPersonalizationSetting::forUser($this->userId);
        $settings->update([
            'last_trained_at' => now(),
            'model_version' => now()->format('YmdHis'),
        ]);

        Cache::forget(TrackManualCategorization::counterKey($this->userId));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ML retraining job failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
