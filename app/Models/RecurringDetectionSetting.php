<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringDetectionSetting extends Model implements OwnedByUserContract
{
    public const string SCOPE_PER_ACCOUNT = 'per_account';

    public const string SCOPE_PER_USER = 'per_user';

    public const string GROUP_BY_MERCHANT_ONLY = 'merchant_only';

    public const string GROUP_BY_MERCHANT_AND_DESCRIPTION = 'merchant_and_description';

    public const string AMOUNT_VARIANCE_PERCENT = 'percent';

    public const string AMOUNT_VARIANCE_FIXED = 'fixed';

    /**
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'scope',
        'group_by',
        'amount_variance_type',
        'amount_variance_value',
        'min_occurrences',
        'run_after_import',
        'scheduled_enabled',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_variance_value' => 'decimal:2',
        'min_occurrences' => 'integer',
        'run_after_import' => 'boolean',
        'scheduled_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUserId(): int
    {
        return (int) $this->user_id;
    }

    /**
     * Get or create settings for a user with defaults.
     */
    public static function forUser(int $userId): self
    {
        $setting = self::where('user_id', $userId)->first();

        if ($setting !== null) {
            return $setting;
        }

        return self::create([
            'user_id' => $userId,
            'scope' => self::SCOPE_PER_ACCOUNT,
            'group_by' => self::GROUP_BY_MERCHANT_AND_DESCRIPTION,
            'amount_variance_type' => self::AMOUNT_VARIANCE_PERCENT,
            'amount_variance_value' => 5.00,
            'min_occurrences' => 3,
            'run_after_import' => true,
            'scheduled_enabled' => true,
        ]);
    }
}
