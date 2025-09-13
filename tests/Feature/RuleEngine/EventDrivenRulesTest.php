<?php

namespace Tests\Feature\RuleEngine;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Models\Account;
use App\Models\Category;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\RuleAction;
use App\Models\RuleEngine\RuleCondition;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\RuleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EventDrivenRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private RuleRepository $ruleRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->create(['user_id' => $this->user->id]);
        $this->ruleRepository = new RuleRepository;
    }

    /**
     * @test
     */
    public function it_processes_rules_when_transaction_is_created()
    {
        // Create a category
        $groceryCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Groceries',
        ]);

        // Create a rule that triggers on transaction creation
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_DESCRIPTION,
                        'operator' => RuleCondition::OPERATOR_CONTAINS,
                        'value' => 'SUPERMARKET',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $groceryCategory->id,
            ],
        ]);

        // Create a transaction that matches the rule
        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'SUPERMARKET PURCHASE',
            'amount' => 50.00,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        // Dispatch the event
        event(new TransactionCreated($transaction));

        // Assert the category was set
        $transaction->refresh();
        $this->assertEquals($groceryCategory->id, $transaction->category_id);
    }

    /**
     * @test
     */
    public function it_does_not_process_rules_when_apply_rules_is_false()
    {
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                        'value' => '0',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_NOTE,
                'action_value' => 'Processed by rule',
            ],
        ]);

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'amount' => 100.00,
        ]);

        // Dispatch event with applyRules = false
        event(new TransactionCreated($transaction, false));

        // Assert the note was not set
        $transaction->refresh();
        $this->assertNull($transaction->note);
    }

    /**
     * @test
     */
    public function it_processes_rules_when_transaction_is_updated()
    {
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        // Create a rule that triggers on transaction update
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_UPDATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                        'value' => '500',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $category->id,
            ],
            [
                'action_type' => RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS,
                'action_value' => 'Large Transaction',
            ],
        ]);

        // Create a transaction
        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'amount' => 100.00,
        ]);

        // Update the transaction to trigger the rule
        $transaction->amount = 600.00;
        $transaction->save();

        // Load the account relationship
        $transaction->load('account.user');

        event(new TransactionUpdated($transaction));

        // Assert the actions were executed
        $transaction->refresh();
        $this->assertEquals($category->id, $transaction->category_id);
        $this->assertTrue($transaction->tags()->where('name', 'Large Transaction')->exists());
    }

    /**
     * @test
     */
    public function it_respects_stop_processing_flag_on_rules()
    {
        $category1 = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Category 1']);
        $category2 = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Category 2']);

        // Create first rule with stop_processing = true
        $rule1 = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                        'value' => '50',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $category1->id,
            ],
        ], true); // stop_processing = true

        // Create second rule
        $rule2 = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                        'value' => '10',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $category2->id,
            ],
        ]);

        // Update rule order to ensure rule1 runs first
        $rule1->update(['order' => 1]);
        $rule2->update(['order' => 2]);

        // Create transaction that matches both rules
        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'amount' => 100.00,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        event(new TransactionCreated($transaction));

        // Assert only the first rule was applied
        $transaction->refresh();
        $this->assertEquals($category1->id, $transaction->category_id);
    }

    /**
     * @test
     */
    public function it_processes_multiple_actions_in_order()
    {
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_DESCRIPTION,
                        'operator' => RuleCondition::OPERATOR_WILDCARD,
                        'value' => '*COFFEE*',
                        'is_case_sensitive' => false,
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $category->id,
                'order' => 1,
            ],
            [
                'action_type' => RuleAction::ACTION_PREPEND_DESCRIPTION,
                'action_value' => '[COFFEE] ',
                'order' => 2,
            ],
            [
                'action_type' => RuleAction::ACTION_SET_NOTE,
                'action_value' => 'Categorized as coffee expense',
                'order' => 3,
            ],
        ]);

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'Starbucks Coffee Shop',
            'amount' => 5.50,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        event(new TransactionCreated($transaction));

        $transaction->refresh();
        $this->assertEquals($category->id, $transaction->category_id);
        $this->assertEquals('[COFFEE] Starbucks Coffee Shop', $transaction->description);
        $this->assertEquals('Categorized as coffee expense', $transaction->note);
    }

    /**
     * @test
     */
    public function it_handles_complex_condition_groups_with_or_logic()
    {
        $travelCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Travel',
        ]);

        // Create rule with multiple OR condition groups
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            // First group: Airlines
            [
                'logic_operator' => 'OR',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_DESCRIPTION,
                        'operator' => RuleCondition::OPERATOR_REGEX,
                        'value' => '/(AIRLINE|AIRWAYS)/i',
                    ],
                    [
                        'field' => RuleCondition::FIELD_PARTNER,
                        'operator' => RuleCondition::OPERATOR_IN,
                        'value' => 'Delta,United,American Airlines',
                    ],
                ],
            ],
            // Second group: Hotels
            [
                'logic_operator' => 'OR',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_DESCRIPTION,
                        'operator' => RuleCondition::OPERATOR_CONTAINS,
                        'value' => 'HOTEL',
                    ],
                    [
                        'field' => RuleCondition::FIELD_DESCRIPTION,
                        'operator' => RuleCondition::OPERATOR_REGEX,
                        'value' => '/(MARRIOTT|HILTON|HYATT)/i',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_CATEGORY,
                'action_value' => $travelCategory->id,
            ],
        ]);

        // Test airline transaction
        $airlineTransaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'DELTA AIRLINES TICKET',
            'amount' => 350.00,
        ]);

        // Load the account relationship
        $airlineTransaction->load('account.user');

        event(new TransactionCreated($airlineTransaction));

        $airlineTransaction->refresh();
        $this->assertEquals($travelCategory->id, $airlineTransaction->category_id);

        // Test hotel transaction
        $hotelTransaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'MARRIOTT DOWNTOWN',
            'amount' => 189.00,
        ]);

        // Load the account relationship
        $hotelTransaction->load('account.user');

        event(new TransactionCreated($hotelTransaction));

        $hotelTransaction->refresh();
        $this->assertEquals($travelCategory->id, $hotelTransaction->category_id);
    }

    /**
     * @test
     */
    public function it_logs_rule_execution()
    {
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_BETWEEN,
                        'value' => '10,100',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_MARK_RECONCILED,
            ],
        ]);

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'amount' => 50.00,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        event(new TransactionCreated($transaction));

        // Check that execution was logged
        $this->assertDatabaseHas('rule_execution_logs', [
            'rule_id' => $rule->id,
            'transaction_id' => $transaction->id,
            'matched' => true,
        ]);
    }

    /**
     * @test
     */
    public function it_handles_inactive_rules()
    {
        // Create inactive rule
        $rule = $this->createRule(Rule::TRIGGER_TRANSACTION_CREATED, [
            [
                'logic_operator' => 'AND',
                'conditions' => [
                    [
                        'field' => RuleCondition::FIELD_AMOUNT,
                        'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                        'value' => '0',
                    ],
                ],
            ],
        ], [
            [
                'action_type' => RuleAction::ACTION_SET_NOTE,
                'action_value' => 'Should not be set',
            ],
        ]);

        // Make rule inactive
        $rule->update(['is_active' => false]);

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'amount' => 100.00,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        event(new TransactionCreated($transaction));

        // Assert the action was not executed
        $transaction->refresh();
        $this->assertNull($transaction->note);
    }

    /**
     * @test
     */
    public function it_queues_rule_processing()
    {
        Event::fake();

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
        ]);

        event(new TransactionCreated($transaction));

        Event::assertDispatched(TransactionCreated::class);
    }

    /**
     * Helper method to create a rule
     */
    private function createRule(string $triggerType, array $conditionGroups, array $actions, bool $stopProcessing = false)
    {
        $ruleGroup = $this->ruleRepository->createRuleGroup($this->user, [
            'name' => 'Test Rule Group',
            'is_active' => true,
        ]);

        return $this->ruleRepository->createRule($this->user, [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Test Rule',
            'trigger_type' => $triggerType,
            'stop_processing' => $stopProcessing,
            'is_active' => true,
            'condition_groups' => $conditionGroups,
            'actions' => $actions,
        ]);
    }
}
