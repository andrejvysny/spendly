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

    protected static function booted(): void
    {
        // Keep normalized_name in sync so (user_id, normalized_name) dedups
        // case- and whitespace-insensitively.
        static::saving(function (Counterparty $counterparty): void {
            $counterparty->normalized_name = self::normalizeName((string) $counterparty->name);
        });
    }

    /**
     * Canonical form used for duplicate detection (trim, collapse whitespace, lowercase).
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

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
