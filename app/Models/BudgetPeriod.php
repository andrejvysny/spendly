<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetPeriod extends BaseModel
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_UPCOMING = 'upcoming';

    protected $fillable = [
        'budget_id',
        'start_date',
        'end_date',
        'amount_budgeted',
        'rollover_amount',
        'status',
        'closed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_budgeted' => 'decimal:2',
        'rollover_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function getEffectiveAmount(): float
    {
        return (float) $this->amount_budgeted + (float) $this->rollover_amount;
    }
}
