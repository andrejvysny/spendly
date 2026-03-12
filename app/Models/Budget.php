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

    /** Reserved for future: user-defined date ranges. Requires custom period start/end UX. */
    public const PERIOD_CUSTOM = 'custom';

    public const MODE_LIMIT = 'limit';

    /** Reserved for future: zero-based envelope budgeting. Requires income allocation UX. */
    public const MODE_ENVELOPE = 'envelope';

    public const TARGET_CATEGORY = 'category';

    public const TARGET_TAG = 'tag';

    public const TARGET_COUNTERPARTY = 'counterparty';

    public const TARGET_SUBSCRIPTION = 'subscription';

    public const TARGET_ACCOUNT = 'account';

    public const TARGET_OVERALL = 'overall';

    public const TARGET_ALL_SUBSCRIPTIONS = 'all_subscriptions';

    public const ALL_TARGET_TYPES = [
        self::TARGET_CATEGORY,
        self::TARGET_TAG,
        self::TARGET_COUNTERPARTY,
        self::TARGET_SUBSCRIPTION,
        self::TARGET_ACCOUNT,
        self::TARGET_OVERALL,
        self::TARGET_ALL_SUBSCRIPTIONS,
    ];

    protected $fillable = [
        'user_id',
        'category_id',
        'tag_id',
        'counterparty_id',
        'recurring_group_id',
        'account_id',
        'target_type',
        'target_key',
        'amount',
        'currency',
        'mode',
        'period_type',
        'name',
        'rollover_enabled',
        'rollover_cap',
        'include_subcategories',
        'include_transfers',
        'auto_create_next',
        'overall_limit_mode',
        'is_active',
        'sort_order',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'target_type' => self::TARGET_CATEGORY,
        'include_transfers' => false,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'rollover_enabled' => 'boolean',
        'rollover_cap' => 'decimal:2',
        'include_subcategories' => 'boolean',
        'include_transfers' => 'boolean',
        'auto_create_next' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Budget $budget) {
            if ($budget->target_key === null) {
                $targetId = match ($budget->target_type) {
                    self::TARGET_CATEGORY => $budget->category_id,
                    self::TARGET_TAG => $budget->tag_id,
                    self::TARGET_COUNTERPARTY => $budget->counterparty_id,
                    self::TARGET_SUBSCRIPTION => $budget->recurring_group_id,
                    self::TARGET_ACCOUNT => $budget->account_id,
                    default => null,
                };
                $budget->target_key = self::computeTargetKey(
                    $budget->target_type ?? self::TARGET_CATEGORY,
                    $targetId !== null ? (int) $targetId : null
                );
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function recurringGroup(): BelongsTo
    {
        return $this->belongsTo(RecurringGroup::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
        return $this->target_type === self::TARGET_OVERALL;
    }

    public function resolveTargetLabel(): string
    {
        return match ($this->target_type) {
            self::TARGET_CATEGORY => $this->category?->name ?? 'Unknown Category',
            self::TARGET_TAG => $this->tag?->name ?? 'Unknown Tag',
            self::TARGET_COUNTERPARTY => $this->counterparty?->name ?? 'Unknown Counterparty',
            self::TARGET_SUBSCRIPTION => $this->recurringGroup?->name ?? 'Unknown Subscription',
            self::TARGET_ACCOUNT => $this->account?->name ?? 'Unknown Account',
            self::TARGET_ALL_SUBSCRIPTIONS => 'All Subscriptions',
            self::TARGET_OVERALL => 'Overall',
            default => 'Unknown',
        };
    }

    public static function computeTargetKey(string $targetType, ?int $targetId): string
    {
        return match ($targetType) {
            self::TARGET_CATEGORY => 'cat:'.$targetId,
            self::TARGET_TAG => 'tag:'.$targetId,
            self::TARGET_COUNTERPARTY => 'cp:'.$targetId,
            self::TARGET_SUBSCRIPTION => 'rg:'.$targetId,
            self::TARGET_ACCOUNT => 'acc:'.$targetId,
            self::TARGET_ALL_SUBSCRIPTIONS => 'all_subs',
            self::TARGET_OVERALL => 'overall',
            default => 'unknown',
        };
    }
}
