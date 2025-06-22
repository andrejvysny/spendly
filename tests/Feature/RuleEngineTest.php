<?php

namespace Tests\Feature;

use App\Events\TransactionCreated;
use App\Models\Account;
use App\Models\Category;
use App\Models\Rule;
use App\Models\RuleAction;
use App\Models\RuleCondition;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\RuleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineTest extends TestCase
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

    public function test_create_rule_with_conditions_and_actions()
    {
        // Create categories and tags
        $groceryCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Groceries',
        ]);

        $shoppingTag = Tag::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Shopping',
        ]);

        // Create a rule group
        $ruleGroup = $this->ruleRepository->createRuleGroup($this->user, [
            'name' => 'Shopping Rules',
            'description' => 'Rules for categorizing shopping transactions',
        ]);

        // Create a rule with multiple condition groups
        $rule = $this->ruleRepository->createRule($this->user, [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Grocery Store Rule',
            'description' => 'Categorize grocery store transactions',
            'trigger_type' => Rule::TRIGGER_TRANSACTION_CREATED,
            'stop_processing' => false,
            'is_active' => true,
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_CONTAINS,
                            'value' => 'WALMART',
                            'is_case_sensitive' => false,
                        ],
                        [
                            'field' => RuleCondition::FIELD_AMOUNT,
                            'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                            'value' => '10',
                        ],
                    ],
                ],
                [
                    'logic_operator' => 'OR',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_REGEX,
                            'value' => '/GROCERY|SUPERMARKET/i',
                        ],
                        [
                            'field' => RuleCondition::FIELD_PARTNER,
                            'operator' => RuleCondition::OPERATOR_WILDCARD,
                            'value' => '*MARKET*',
                            'is_case_sensitive' => false,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_SET_CATEGORY,
                    'action_value' => $groceryCategory->id,
                ],
                [
                    'action_type' => RuleAction::ACTION_ADD_TAG,
                    'action_value' => $shoppingTag->id,
                ],
                [
                    'action_type' => RuleAction::ACTION_APPEND_NOTE,
                    'action_value' => ' [Auto-categorized as grocery]',
                ],
            ],
        ]);

        $this->assertNotNull($rule);
        $this->assertEquals('Grocery Store Rule', $rule->name);
        $this->assertCount(2, $rule->conditionGroups);
        $this->assertCount(3, $rule->actions);
    }

    public function test_rule_engine_processes_transaction_on_creation()
    {
        // Create a category
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Restaurants',
        ]);

        // Create a rule
        $rule = $this->ruleRepository->createRule($this->user, [
            'rule_group_id' => $this->createRuleGroup()->id,
            'name' => 'Restaurant Rule',
            'trigger_type' => Rule::TRIGGER_TRANSACTION_CREATED,
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_CONTAINS,
                            'value' => 'RESTAURANT',
                            'is_case_sensitive' => false,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_SET_CATEGORY,
                    'action_value' => $category->id,
                ],
            ],
        ]);

        // Create a transaction that should match the rule
        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'PIZZA RESTAURANT PAYMENT',
            'amount' => 25.50,
        ]);

        // Load the account relationship
        $transaction->load('account.user');

        // Dispatch the event
        event(new TransactionCreated($transaction));

        // Refresh the transaction and check if the category was set
        $transaction->refresh();
        $this->assertEquals($category->id, $transaction->category_id);
    }

    public function test_rule_with_regex_and_wildcard_conditions()
    {
        $rule = $this->ruleRepository->createRule($this->user, [
            'rule_group_id' => $this->createRuleGroup()->id,
            'name' => 'Pattern Matching Rule',
            'trigger_type' => Rule::TRIGGER_MANUAL,
            'condition_groups' => [
                [
                    'logic_operator' => 'OR',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_REGEX,
                            'value' => '/^ATM\s+\d{4}/',
                        ],
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_WILDCARD,
                            'value' => 'CASH*WITHDRAWAL',
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_CREATE_TAG_IF_NOT_EXISTS,
                    'action_value' => 'Cash',
                ],
            ],
        ]);

        $this->assertNotNull($rule);
    }

    public function test_api_create_rule()
    {
        $this->actingAs($this->user);

        $ruleGroup = $this->createRuleGroup();
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/rules', [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'API Test Rule',
            'trigger_type' => Rule::TRIGGER_TRANSACTION_CREATED,
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_AMOUNT,
                            'operator' => RuleCondition::OPERATOR_BETWEEN,
                            'value' => '100,500',
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_SET_CATEGORY,
                    'action_value' => $category->id,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Rule created successfully',
            ]);
    }

    public function test_api_execute_rules_on_transactions()
    {
        $this->actingAs($this->user);

        // Create transactions
        $transactions = Transaction::factory()->count(3)->create([
            'account_id' => $this->account->id,
        ]);

        // Create a rule
        $rule = $this->createSampleRule();

        $response = $this->postJson('/api/rules/execute/transactions', [
            'transaction_ids' => $transactions->pluck('id')->toArray(),
            'rule_ids' => [$rule->id],
            'dry_run' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'total_transactions',
                    'total_rules_matched',
                    'results',
                ],
            ]);
    }

    public function test_api_test_rule_without_saving()
    {
        $this->actingAs($this->user);

        $transaction = Transaction::factory()->create([
            'account_id' => $this->account->id,
            'description' => 'UBER RIDE',
            'amount' => 15.00,
        ]);

        $response = $this->postJson('/api/rules/test', [
            'transaction_ids' => [$transaction->id],
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_CONTAINS,
                            'value' => 'UBER',
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_CREATE_CATEGORY_IF_NOT_EXISTS,
                    'action_value' => 'Transportation',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.total_matched', 1);
    }

    private function createRuleGroup(): \App\Models\RuleGroup
    {
        return $this->ruleRepository->createRuleGroup($this->user, [
            'name' => 'Test Rule Group',
            'description' => 'Test rule group for unit tests',
        ]);
    }

    private function createSampleRule(): Rule
    {
        return $this->ruleRepository->createRule($this->user, [
            'rule_group_id' => $this->createRuleGroup()->id,
            'name' => 'Sample Rule',
            'trigger_type' => Rule::TRIGGER_MANUAL,
            'condition_groups' => [
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
            ],
            'actions' => [
                [
                    'action_type' => RuleAction::ACTION_SET_NOTE,
                    'action_value' => 'Processed by rule',
                ],
            ],
        ]);
    }
}
