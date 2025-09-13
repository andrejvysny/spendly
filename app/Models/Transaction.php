<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Transaction extends BaseModel
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
     * Transaction type constants
     */
    public const TYPE_TRANSFER = 'TRANSFER';

    public const TYPE_CARD_PAYMENT = 'CARD_PAYMENT';

    public const TYPE_EXCHANGE = 'EXCHANGE';

    public const TYPE_PAYMENT = 'PAYMENT';

    public const TYPE_WITHDRAWAL = 'WITHDRAWAL';

    public const TYPE_DEPOSIT = 'DEPOSIT';

    /**
     * Get the account that owns the transaction.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
}
