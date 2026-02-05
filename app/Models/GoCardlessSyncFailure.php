<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoCardlessSyncFailure extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'gocardless_sync_failures';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'account_id',
        'user_id',
        'external_transaction_id',
        'error_type',
        'error_code',
        'error_message',
        'raw_data',
        'validation_errors',
        'retry_count',
        'last_retry_at',
        'resolved_at',
        'resolution',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'raw_data' => 'array',
        'validation_errors' => 'array',
        'last_retry_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Error type constants.
     */
    public const string ERROR_TYPE_VALIDATION = 'validation';

    public const string ERROR_TYPE_MAPPING = 'mapping';

    public const string ERROR_TYPE_PERSISTENCE = 'persistence';

    public const string ERROR_TYPE_API = 'api';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
