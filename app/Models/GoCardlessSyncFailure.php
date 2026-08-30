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
        // Encrypted: this column holds the provider's full transaction payload — amounts, IBANs,
        // counterparty names — for rows that failed to import. It was the only unencrypted copy of
        // bank data in the schema, unlike the GoCardless credential columns on users.
        'raw_data' => 'encrypted:array',
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

    /**
     * Retries a failure gets before it is parked as terminal.
     *
     * Owned by the model rather than the retry command so the command and any reader agree on when
     * a row stops being "pending" — previously an exhausted row sat with resolved_at NULL forever,
     * indistinguishable by any query from one that had just been recorded.
     */
    public const int MAX_RETRIES = 5;

    /**
     * Resolution written when a row is deterministically unfixable (same payload, same failure,
     * MAX_RETRIES times). Terminal: retry stops looking at it, pruning may collect it.
     */
    public const string RESOLUTION_EXHAUSTED = 'exhausted';

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

    public function isExhausted(): bool
    {
        return $this->resolution === self::RESOLUTION_EXHAUSTED;
    }
}
