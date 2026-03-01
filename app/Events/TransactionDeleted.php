<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
