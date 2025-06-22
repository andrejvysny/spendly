<?php

namespace App\Models;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'amount',
        'currency',
        'booked_date',
        'processed_date',
        'description',
        'target_iban',
        'source_iban',
        'partner',
        'type',
        'metadata',
        'balance_after_transaction',
        'account_id',
        'import_data',
        'merchant_id',
        'category_id',
        'note',
        'recipient_note',
        'place',
        'is_gocardless_synced',
        'gocardless_synced_at',
        'gocardless_account_id',
        'is_reconciled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'booked_date' => 'datetime',
        'processed_date' => 'datetime',
        'amount' => 'decimal:2',
        'balance_after_transaction' => 'decimal:2',
        'metadata' => 'json',
        'import_data' => 'json',
        'currency' => 'string',
        'type' => 'string',
        'is_gocardless_synced' => 'boolean',
        'gocardless_synced_at' => 'datetime',
        'gocardless_account_id' => 'string',
        'is_reconciled' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        '_apply_rules',
    ];

    /**
     * Transaction type constants
     */
    public const TYPE_TRANSFER = 'TRANSFER';

    public const TYPE_CARD_PAYMENT = 'CARD_PAYMENT';

    public const TYPE_EXCHANGE = 'EXCHANGE';

    public const TYPE_PAYMENT = 'PAYMENT';

    public const TYPE_WITHDRAWAL = 'WITHDRAWAL';

    public const TYPE_DEPOSIT = 'DEPOSIT';

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function (Transaction $transaction) {
            // Skip rule processing for bulk imports - handled at service level
            if ($transaction->wasCreatedViaBulkImport()) {
                return;
            }

            // Check if rules should be applied (can be controlled via attributes or context)
            $applyRules = $transaction->getAttribute('_apply_rules') ?? true;
            event(new TransactionCreated($transaction, $applyRules));
        });

        static::updated(function (Transaction $transaction) {
            // Skip rule processing for bulk imports
            if ($transaction->wasCreatedViaBulkImport()) {
                return;
            }

            // Get changed attributes for rule processing
            $changedAttributes = $transaction->getChanges();
            $applyRules = $transaction->getAttribute('_apply_rules') ?? true;

            event(new TransactionUpdated($transaction, $changedAttributes, $applyRules));
        });
    }

    /**
     * Get the account that owns the transaction.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function disableRules(): void
    {
        $this->setAttribute('_apply_rules', false);
    }

    /**
     * Get the merchant associated with the transaction.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the category associated with the transaction.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the tags associated with the transaction.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Scope a query to search for transactions based on a search term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($query) use ($term) {
            $query->where('description', 'LIKE', "%{$term}%")
                ->orWhere('partner', 'LIKE', "%{$term}%")
                ->orWhere('note', 'LIKE', "%{$term}%")
                ->orWhere('recipient_note', 'LIKE', "%{$term}%")
                ->orWhere('place', 'LIKE', "%{$term}%")
                ->orWhere('transaction_id', 'LIKE', "%{$term}%")
                ->orWhere('target_iban', 'LIKE', "%{$term}%")
                ->orWhere('source_iban', 'LIKE', "%{$term}%")
                ->orWhere('amount', 'LIKE', "%{$term}%")
                ->orWhereHas('category', function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%");
                })
                ->orWhereHas('merchant', function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%");
                })
                ->orWhereHas('account', function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%");
                })
                ->orWhereHas('tags', function ($q) use ($term) {
                    $q->where('name', 'LIKE', "%{$term}%");
                });
        });
    }

    /**
     * Create a transaction without applying rules.
     */
    public static function createWithoutRules(array $attributes = []): self
    {
        $attributes['_apply_rules'] = false;
        return static::create($attributes);
    }

    /**
     * Update a transaction without applying rules.
     */
    public function updateWithoutRules(array $attributes = []): bool
    {
        $this->setAttribute('_apply_rules', false);
        return $this->update($attributes);
    }

    /**
     * Check if transaction was created via bulk import.
     */
    public function wasCreatedViaBulkImport(): bool
    {
        $metadata = $this->metadata ?? [];

        // Check if this transaction has import metadata
        return isset($metadata['processing_metadata']) ||
               isset($metadata['import_id']) ||
               isset($metadata['needs_rule_processing']);
    }
}
