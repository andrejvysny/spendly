<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Budget extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM = 'custom';

    public const MODE_LIMIT = 'limit';

    public const MODE_ENVELOPE = 'envelope';

    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'currency',
        'mode',
        'period_type',
        'name',
        'rollover_enabled',
        'include_subcategories',
        'auto_create_next',
        'overall_limit_mode',
        'is_active',
        'sort_order',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'rollover_enabled' => 'boolean',
        'include_subcategories' => 'boolean',
        'auto_create_next' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class)->orderBy('start_date', 'desc');
    }

    public function activePeriod(): HasOne
    {
        return $this->hasOne(BudgetPeriod::class)
            ->where('status', BudgetPeriod::STATUS_ACTIVE)
            ->latestOfMany('start_date');
    }

    public function getUserId(): int
    {
        return (int) $this->getAttribute('user_id');
    }

    public function isOverallBudget(): bool
    {
        return $this->category_id === null;
    }
}
