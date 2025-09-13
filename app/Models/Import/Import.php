<?php

namespace App\Models\Import;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Get the failures for this import.
     */
    public function failures(): HasMany
    {
        return $this->hasMany(ImportFailure::class);
    }

    /**
     * Get pending failures for this import.
     */
    public function pendingFailures(): HasMany
    {
        return $this->failures()->pending();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Get failure statistics for this import.
     */
    public function getFailureStats(): array
    {
        return [
            'total' => $this->failures()->count(),
            'pending' => $this->failures()->pending()->count(),
            'reviewed' => $this->failures()->reviewed()->count(),
            'by_type' => $this->failures()
                ->select('error_type', DB::raw('count(*) as count'))
                ->groupBy('error_type')
                ->pluck('count', 'error_type')
                ->toArray(),
        ];
    }
}
