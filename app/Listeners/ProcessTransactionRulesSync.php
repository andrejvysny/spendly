<?php

namespace App\Listeners;

use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Models\Rule;

class ProcessTransactionRulesSync
{
    protected RuleEngineInterface $ruleEngine;

    public function __construct(RuleEngineInterface $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    public function handleTransactionCreated(TransactionCreated $event): void
    {
        if (! $event->applyRules) {
            return;
        }

        $transaction = $event->transaction;
        $user = $transaction->account->user;

        $this->ruleEngine
            ->setUser($user)
            ->processTransaction($transaction, Rule::TRIGGER_TRANSACTION_CREATED);
    }

    public function handleTransactionUpdated(TransactionUpdated $event): void
    {
        if (! $event->applyRules) {
            return;
        }

        $transaction = $event->transaction;
        $user = $transaction->account->user;

        $this->ruleEngine
            ->setUser($user)
            ->processTransaction($transaction, Rule::TRIGGER_TRANSACTION_UPDATED);
    }

    public function subscribe($events): array
    {
        return [
            TransactionCreated::class => 'handleTransactionCreated',
            TransactionUpdated::class => 'handleTransactionUpdated',
        ];
    }
}
