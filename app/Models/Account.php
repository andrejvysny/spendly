<?php

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends BaseModel implements OwnedByUserContract
{
    use HasFactory;

    /**
     * Lifecycle of the queued per-account GoCardless sync.
     *
     * Distinct from gocardless_last_synced_at, which is the *data* watermark (how far
     * transactions are known good) and is owned by GoCardlessService, not by these states.
     */
    public const SYNC_STATUS_IDLE = 'idle';

    public const SYNC_STATUS_QUEUED = 'queued';

    public const SYNC_STATUS_SYNCING = 'syncing';

    public const SYNC_STATUS_SUCCESS = 'success';

    /**
     * The run finished and wrote what it could, but some rows did not make it — a validation or
     * mapping failure, or a row the unique index rejected. Deliberately not `success`: the ledger
     * is incomplete and the watermark was held back so a later run refetches the gap.
     */
    public const SYNC_STATUS_INCOMPLETE = 'incomplete';

    public const SYNC_STATUS_FAILED = 'failed';

    public const SYNC_STATUS_RATE_LIMITED = 'rate_limited';

    public const SYNC_STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    /**
     * States where a job is already booked for this account, so dispatching another
     * would either be collapsed by the unique lock or pile up behind it.
     *
     * @var list<string>
     */
    public const SYNC_STATUSES_IN_PROGRESS = [
        self::SYNC_STATUS_QUEUED,
        self::SYNC_STATUS_SYNCING,
    ];

    /**
     * How long an account may sit in `syncing` before it is assumed dead.
     *
     * A worker killed mid-run (OOM, container eviction) never reaches failed(), so the row keeps
     * saying `syncing` forever — and every dispatch path skips an in-progress account, which makes
     * it permanently unsyncable. Generous against the job's own 280s timeout.
     */
    public const SYNC_STALE_SYNCING_SECONDS = 900;

    /**
     * How long an account may sit in `queued` before it is assumed lost.
     *
     * Much longer than the syncing threshold because a queued job may legitimately be waiting:
     * gocardless:dispatch-sync staggers dispatches up to 1800s, and a rate-limited job may be
     * released for up to SyncGoCardlessAccountJob::MAX_RELEASE_SECONDS on top of that.
     */
    public const SYNC_STALE_QUEUED_SECONDS = 3600;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'bank_name',
        'iban',
        'type',
        'currency',
        'balance',
        'opening_balance',
        'gocardless_account_id',
        'gocardless_institution_id',
        'bic',
        'is_gocardless_synced',
        'gocardless_last_synced_at',
        'import_data',
        'sync_options',
        'gocardless_requisition_id',
        'gocardless_needs_reconnect',
        'gocardless_sync_status',
        'gocardless_sync_queued_at',
        'gocardless_sync_started_at',
        'gocardless_sync_finished_at',
        'gocardless_sync_error',
        'gocardless_sync_stats',
        'gocardless_sync_retry_after',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_gocardless_synced' => 'boolean',
        'gocardless_last_synced_at' => 'datetime',
        'import_data' => 'json',
        'sync_options' => 'json',
        'gocardless_needs_reconnect' => 'boolean',
        'gocardless_sync_queued_at' => 'datetime',
        'gocardless_sync_started_at' => 'datetime',
        'gocardless_sync_finished_at' => 'datetime',
        'gocardless_sync_retry_after' => 'datetime',
        'gocardless_sync_stats' => 'array',
    ];

    /**
     * Get the user that owns the account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions for the account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getUserId(): int
    {
        return $this->getAttribute('user_id');
    }
}
