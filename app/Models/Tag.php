<?php

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'user_id',
    ];

    /**
     * Get the user that owns the tag.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions for the tag.
     */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class);
    }

    public function getUserId(): int
    {
        return $this->getAttribute('user_id');
    }
}
