<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Transaction $transaction;

    public bool $applyRules;

    /**
     * @var array<string, mixed>
     */
    public array $changedAttributes;

    /**
     * Create a new event instance.
     *
     * @param array<string, mixed> $changedAttributes
     */
    public function __construct(Transaction $transaction, array $changedAttributes = [], bool $applyRules = true)
    {
        $this->transaction = $transaction;
        $this->changedAttributes = $changedAttributes;
        $this->applyRules = $applyRules;
    }
}
