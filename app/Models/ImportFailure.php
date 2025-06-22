<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFailure extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'import_id',
        'row_number',
        'raw_data',
        'error_type',
        'error_message',
        'error_details',
        'parsed_data',
        'metadata',
        'status',
        'review_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'raw_data' => 'json',
        'error_details' => 'json',
        'parsed_data' => 'json',
        'metadata' => 'json',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Error type constants
     */
    public const ERROR_TYPE_VALIDATION_FAILED = 'validation_failed';

    public const ERROR_TYPE_DUPLICATE = 'duplicate';

    public const ERROR_TYPE_PROCESSING_ERROR = 'processing_error';

    public const ERROR_TYPE_PARSING_ERROR = 'parsing_error';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    /**
     * Get the import that owns this failure.
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Get the user who reviewed this failure.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope: Get pending failures
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Get reviewed failures
     */
    public function scopeReviewed($query)
    {
        return $query->whereIn('status', [self::STATUS_REVIEWED, self::STATUS_RESOLVED, self::STATUS_IGNORED]);
    }

    /**
     * Scope: Get failures by error type
     */
    public function scopeByErrorType($query, string $errorType)
    {
        return $query->where('error_type', $errorType);
    }

    /**
     * Mark as reviewed
     */
    public function markAsReviewed(User $reviewer, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_REVIEWED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'review_notes' => $notes,
        ]);
    }

    /**
     * Mark as resolved
     */
    public function markAsResolved(User $reviewer, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_RESOLVED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'review_notes' => $notes,
        ]);
    }

    /**
     * Mark as ignored
     */
    public function markAsIgnored(User $reviewer, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_IGNORED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'review_notes' => $notes,
        ]);
    }
}
