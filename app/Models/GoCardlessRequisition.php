<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\OwnedByUserContract;
use App\Enums\GoCardlessRequisitionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoCardlessRequisition extends BaseModel implements OwnedByUserContract
{
    /** @use HasFactory<\Database\Factories\GoCardlessRequisitionFactory> */
    use HasFactory;

    /**
     * Explicit table name: Eloquent's snake_case inference would otherwise
     * split "GoCardless" into "go_cardless" (see GoCardlessSyncFailure for
     * the same issue).
     *
     * @var string
     */
    protected $table = 'gocardless_requisitions';

    /**
     * The attributes that are mass assignable.
     *
     * user_id is deliberately excluded (same precedent as Account) so ownership
     * can never be set via mass assignment; repositories set it explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'requisition_id',
        'reference',
        'institution_id',
        'institution_name',
        'agreement_id',
        'status',
        'gocardless_status',
        'max_historical_days',
        'access_valid_for_days',
        'accepted_at',
        'access_valid_until',
        'access_valid_until_estimated',
        'accounts',
        'link',
        'return_to',
        'callback_completed_at',
        'last_checked_at',
        'expiry_warning_sent_at',
        'last_error',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => GoCardlessRequisitionStatus::class,
        'accounts' => 'array',
        'accepted_at' => 'datetime',
        'access_valid_until' => 'datetime',
        'access_valid_until_estimated' => 'boolean',
        'callback_completed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'expiry_warning_sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Locally imported accounts linked to this requisition.
     *
     * Named distinctly from the `accounts` JSON column (raw GoCardless
     * account IDs, see the cast above) so property access and relation
     * access can never shadow each other.
     *
     * @return HasMany<Account, $this>
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'gocardless_requisition_id');
    }

    public function getUserId(): int
    {
        return (int) $this->getAttribute('user_id');
    }

    /**
     * Signed number of days until access_valid_until. Negative when already
     * expired. Null when the requisition has no known expiry.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->access_valid_until === null) {
            return null;
        }

        return (int) now()->diffInDays($this->access_valid_until, false);
    }

    public function needsReconnect(): bool
    {
        if ($this->status->needsReconnect()) {
            return true;
        }

        return $this->access_valid_until !== null && $this->access_valid_until->isPast();
    }

    /**
     * Whether the given raw GoCardless account ID was returned for this requisition.
     */
    public function ownsGoCardlessAccount(string $id): bool
    {
        return in_array($id, $this->accounts ?? [], true);
    }
}
