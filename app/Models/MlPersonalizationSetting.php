<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlPersonalizationSetting extends Model implements OwnedByUserContract
{
    protected $fillable = [
        'user_id',
        'auto_apply_suggestions',
        'auto_retrain',
        'retrain_threshold',
        'model_version',
        'last_trained_at',
        'personalization_vector',
    ];

    protected $casts = [
        'auto_apply_suggestions' => 'boolean',
        'auto_retrain' => 'boolean',
        'retrain_threshold' => 'integer',
        'last_trained_at' => 'datetime',
        'personalization_vector' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUserId(): int
    {
        return (int) $this->user_id;
    }

    public static function forUser(int $userId): self
    {
        $setting = self::where('user_id', $userId)->first();

        if ($setting !== null) {
            return $setting;
        }

        return self::create([
            'user_id' => $userId,
            'auto_retrain' => false,
            'retrain_threshold' => 10,
            'model_version' => null,
            'last_trained_at' => null,
            'personalization_vector' => null,
        ]);
    }
}
