<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'currency',
        'period_type',
        'year',
        'month',
        'name',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'year' => 'integer',
        'month' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getUserId(): int
    {
        return (int) $this->getAttribute('user_id');
    }
}
