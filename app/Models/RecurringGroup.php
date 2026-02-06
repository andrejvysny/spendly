<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringGroup extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    public const string STATUS_SUGGESTED = 'suggested';

    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_DISMISSED = 'dismissed';

    public const string SCOPE_PER_ACCOUNT = 'per_account';

    public const string SCOPE_PER_USER = 'per_user';

    public const string INTERVAL_WEEKLY = 'weekly';

    public const string INTERVAL_MONTHLY = 'monthly';

    public const string INTERVAL_QUARTERLY = 'quarterly';

    public const string INTERVAL_YEARLY = 'yearly';

    /**
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'interval',
        'interval_days',
        'amount_min',
        'amount_max',
        'scope',
        'account_id',
        'merchant_id',
        'normalized_description',
        'status',
        'detection_config_snapshot',
        'first_date',
        'last_date',
        'dismissal_fingerprint',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_min' => 'decimal:2',
        'amount_max' => 'decimal:2',
        'first_date' => 'date',
        'last_date' => 'date',
        'detection_config_snapshot' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Transactions linked to this group (only set when status = confirmed).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getUserId(): int
    {
        return (int) $this->getAttribute('user_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<RecurringGroup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<RecurringGroup>
     */
    public function scopeSuggested($query)
    {
        return $query->where('status', self::STATUS_SUGGESTED);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<RecurringGroup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<RecurringGroup>
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<RecurringGroup>  $query
     * @return \Illuminate\Database\Eloquent\Builder<RecurringGroup>
     */
    public function scopeDismissed($query)
    {
        return $query->where('status', self::STATUS_DISMISSED);
    }
}
