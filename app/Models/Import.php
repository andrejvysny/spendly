<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    /** @use HasFactory<\Database\Factories\ImportFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'filename',
        'original_filename',
        'status',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'column_mapping',
        'date_format',
        'amount_format',
        'amount_type_strategy',
        'currency',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'column_mapping' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPLETED_SKIPPED_DUPLICATES = 'completed_skipped_duplicates';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    /**
     * Get the user that owns the import.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
