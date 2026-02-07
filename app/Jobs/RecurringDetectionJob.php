<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RecurringDetectionSetting;
use App\Services\RecurringDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecurringDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        private readonly int $userId,
        private readonly ?int $accountId = null
    ) {}

    public function handle(RecurringDetectionService $service): void
    {
        $settings = RecurringDetectionSetting::forUser($this->userId);
        if ($this->accountId !== null && ! $settings->run_after_import) {
            return;
        }
        if ($this->accountId === null && ! $settings->scheduled_enabled) {
            return;
        }

        Log::info('Recurring detection job started', [
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
        ]);

        $created = $service->runForUser($this->userId, $this->accountId);

        Log::info('Recurring detection job completed', [
            'user_id' => $this->userId,
            'suggestions_created' => $created,
        ]);
    }
}
