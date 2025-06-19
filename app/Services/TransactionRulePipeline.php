<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionRule;
use Illuminate\Pipeline\Pipeline;

class TransactionRulePipeline
{
    /**
     * The user ID to process rules for.
     */
    private int $userId;

    /**
     * Create a new transaction rule pipeline instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Process a transaction through all active rules.
     */
    public function process(Transaction $transaction): Transaction
    {
        $rules = TransactionRule::where('user_id', $this->userId)
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(function (TransactionRule $rule) {
                return $this->createRuleInstance($rule);
            })
            ->toArray();

        $result = app(Pipeline::class)
            ->send($transaction)
            ->through($rules)
            ->then(function (Transaction $transaction) {
                return $transaction;
            });
        $result->save();
        return $result;
    }

    /**
     * Create a rule instance for processing.
     *
     * @return object
     */
    private function createRuleInstance(TransactionRule $rule)
    {
        return new class($rule)
        {
            /**
             * The transaction rule instance.
             */
            private TransactionRule $rule;

            /**
             * Create a new rule instance.
             */
            public function __construct(TransactionRule $rule)
            {
                $this->rule = $rule;
            }

            /**
             * Process the transaction through this rule.
             */
            public function __invoke(Transaction $transaction): Transaction
            {
                if ($this->matchesCondition($transaction)) {
                    $this->applyAction($transaction);
                }
                return $transaction;
            }

            /**
             * Check if the transaction matches the rule condition.
             */
            private function matchesCondition(Transaction $transaction): bool
            {
                return match ($this->rule->condition_type) {
                    'amount' => $this->matchesAmount($transaction),
                    'iban' => $this->matchesIban($transaction),
                    'description' => $this->matchesDescription($transaction),
                    default => false,
                };
            }

            /**
             * Check if the transaction amount matches the rule condition.
             */
            private function matchesAmount(Transaction $transaction): bool
            {
                $amount = (float) $transaction->amount;
                $value = (float) $this->rule->condition_value;

                return match ($this->rule->condition_operator) {
                    'greater_than' => $amount > $value,
                    'less_than' => $amount < $value,
                    'equals' => $amount === $value,
                    default => false,
                };
            }

            /**
             * Check if the transaction IBAN matches the rule condition.
             */
            private function matchesIban(Transaction $transaction): bool
            {
                return match ($this->rule->condition_operator) {
                    'contains' => str_contains($transaction->iban, $this->rule->condition_value),
                    'equals' => $transaction->iban === $this->rule->condition_value,
                    default => false,
                };
            }

            /**
             * Check if the transaction description matches the rule condition.
             */
            private function matchesDescription(Transaction $transaction): bool
            {
                return match ($this->rule->condition_operator) {
                    'contains' => str_contains($transaction->description, $this->rule->condition_value),
                    'equals' => $transaction->description === $this->rule->condition_value,
                    default => false,
                };
            }

            private function applyAction(Transaction $transaction): void
            {
                match ($this->rule->action_type) {
                    'add_tag' => $transaction->tags()->attach($this->rule->action_value),
                    'set_category' => $transaction->category_id = $this->rule->action_value,
                    'set_type' => $transaction->type = $this->rule->action_value,
                    default => null,
                };
            }
        };
    }
}
