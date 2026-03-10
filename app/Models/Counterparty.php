<?php

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use App\Enums\CounterpartyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Counterparty extends BaseModel implements OwnedByUserContract
{
    /** @use HasFactory<\Database\Factories\CounterpartyFactory> */
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'logo',
        'type',
        'user_id',
    ];

    protected $casts = [
        'type' => CounterpartyType::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getUserId(): int
    {
        return $this->getAttribute('user_id');
    }
}
