<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringGroup extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    /**
     * @var array<int, int> interval => payments per year
     */
    private const INTERVAL_PAYMENTS_PER_YEAR = [
        self::INTERVAL_WEEKLY => 52,
        self::INTERVAL_MONTHLY => 12,
        self::INTERVAL_QUARTERLY => 4,
        self::INTERVAL_YEARLY => 1,
    ];

    /**
     * @var array<int, int> interval => typical days between payments
     */
    private const INTERVAL_DAYS_DEFAULT = [
        self::INTERVAL_WEEKLY => 7,
        self::INTERVAL_MONTHLY => 30,
        self::INTERVAL_QUARTERLY => 91,
        self::INTERVAL_YEARLY => 365,
    ];

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

    /**
     * @var array<string>
     */
    protected $appends = ['stats'];

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
     * Statistics derived from linked transactions (only when status is confirmed and aggregates are loaded).
     *
     * @return array{first_payment_date: string|null, last_payment_date: string|null, transactions_count: int, total_paid: float, average_amount: float|null, projected_yearly_cost: float, next_expected_payment: string|null}|null
     */
    public function getStatsAttribute(): ?array
    {
        if ($this->status !== self::STATUS_CONFIRMED) {
            return null;
        }
        $count = (int) ($this->getAttribute('transactions_count') ?? 0);
        $sum = (float) (string) ($this->getAttribute('transactions_sum_amount') ?? 0);
        $minDate = $this->getAttribute('transactions_min_booked_date');
        $maxDate = $this->getAttribute('transactions_max_booked_date');

        $firstPaymentDate = $this->normalizeStatsDate($minDate);
        $lastPaymentDate = $this->normalizeStatsDate($maxDate);

        $averageAmount = $count > 0 ? round($sum / $count, 2) : null;
        $interval = $this->interval ?? self::INTERVAL_MONTHLY;
        $paymentsPerYear = self::INTERVAL_PAYMENTS_PER_YEAR[$interval] ?? 12;
        $projectedYearlyCost = $averageAmount !== null ? round($averageAmount * $paymentsPerYear, 2) : 0.0;

        $nextExpectedPayment = null;
        if ($lastPaymentDate !== null) {
            $days = $this->interval_days ?? (self::INTERVAL_DAYS_DEFAULT[$interval] ?? 30);
            $next = Carbon::parse($lastPaymentDate)->addDays((int) $days);
            $nextExpectedPayment = $next->format('Y-m-d');
        }

        return [
            'first_payment_date' => $firstPaymentDate,
            'last_payment_date' => $lastPaymentDate,
            'transactions_count' => $count,
            'total_paid' => round($sum, 2),
            'average_amount' => $averageAmount,
            'projected_yearly_cost' => $projectedYearlyCost,
            'next_expected_payment' => $nextExpectedPayment,
        ];
    }

    /**
     * @param  \DateTimeInterface|string|null  $value
     */
    private function normalizeStatsDate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                return strlen($value) >= 10 ? substr($value, 0, 10) : $value;
            }
        }

        return null;
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
