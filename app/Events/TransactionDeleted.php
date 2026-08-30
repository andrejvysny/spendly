<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class TransactionDeleted
{
    // Deliberately NOT SerializesModels: the row is already gone by the time a queued
    // listener runs, so restoring the model by id throws ModelNotFoundException. The
    // in-memory snapshot is what a deletion listener actually needs.
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private readonly Transaction $transaction,
        private readonly bool $applyRules = true
    ) {}

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function shouldApplyRules(): bool
    {
        return $this->applyRules;
    }
}
