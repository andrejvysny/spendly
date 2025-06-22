<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Transaction $transaction;

    public bool $applyRules;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction, bool $applyRules = true)
    {
        $this->transaction = $transaction;
        $this->applyRules = $applyRules;
    }
}
