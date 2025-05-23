<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMapping extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'bank_name',
        'column_mapping',
        'date_format',
        'amount_format',
        'amount_type_strategy',
        'currency',
        'last_used_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'column_mapping' => 'array',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the user that owns the import mapping.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
