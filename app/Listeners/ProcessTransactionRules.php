<?php

namespace App\Listeners;

use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\Trigger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessTransactionRules implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The rule engine instance.
     */
    protected RuleEngineInterface $ruleEngine;

    /**
     * Create the event listener.
     */
    public function __construct(RuleEngineInterface $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    /**
     * Handle the TransactionCreated event.
     */
    public function handleTransactionCreated(TransactionCreated $event): void
    {
        if (! $event->shouldApplyRules()) {
            return;
        }

        Log::debug("TransactionCreatedEVENT - Processing rules for created transaction ID: {$event->getTransaction()->id}", ['event' => $event]);

        $transaction = $event->getTransaction();
        $user = $transaction->account->user;

        $this->ruleEngine
            ->setUser($user)
            ->processTransaction($transaction, Trigger::TRANSACTION_CREATED);

        Log::debug("TransactionCreatedEVENT - Finished processing rules for created transaction ID: {$event->getTransaction()->id}", ['event' => $event]);
    }

    /**
     * Handle the TransactionUpdated event.
     */
    public function handleTransactionUpdated(TransactionUpdated $event): void
    {
        if (! $event->applyRules) {
            return;
        }

        $transaction = $event->transaction;
        $user = $transaction->account->user;

        $this->ruleEngine
            ->setUser($user)
            ->processTransaction($transaction, Trigger::TRANSACTION_UPDATED);
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): array
    {
        return [
            TransactionCreated::class => 'handleTransactionCreated',
            TransactionUpdated::class => 'handleTransactionUpdated',
        ];
    }
}
