<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransactionUpdated;
use App\Jobs\RetrainMlModelJob;
use App\Models\MlPersonalizationSetting;
use Illuminate\Support\Facades\Cache;

class TrackManualCategorization
{
    public function handle(TransactionUpdated $event): void
    {
        if (! array_key_exists('category_id', $event->changedAttributes)) {
            return;
        }

        $userId = (int) $event->transaction->account->user_id;
        if ($userId <= 0) {
            return;
        }

        $settings = MlPersonalizationSetting::forUser($userId);
        $counterKey = self::counterKey($userId);
        $count = Cache::increment($counterKey);

        if ($count >= $settings->retrain_threshold && $settings->auto_retrain) {
            RetrainMlModelJob::dispatch($userId);
        }
    }

    public static function counterKey(int $userId): string
    {
        return sprintf('ml:manual_categorization_count:%d', $userId);
    }
}
