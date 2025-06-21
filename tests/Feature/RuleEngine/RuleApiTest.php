<?php

namespace Tests\Feature\RuleEngine;

use App\Models\Account;
use App\Models\Category;
use App\Models\Rule;
use App\Models\RuleAction;
use App\Models\RuleCondition;
use App\Models\RuleGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * @test
     */
    public function it_lists_rule_groups_with_rules()
    {
        // Create rule groups and rules
        $activeGroup = RuleGroup::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Active Group',
            'is_active' => true,
            'order' => 1,
        ]);

        $inactiveGroup = RuleGroup::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Inactive Group',
            'is_active' => false,
            'order' => 2,
        ]);

        Rule::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $activeGroup->id,
        ]);

        Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $inactiveGroup->id,
        ]);

        $response = $this->getJson('/api/rules');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
        
        // Check that both groups are present
        $data = $response->json('data');
        $groupNames = collect($data)->pluck('name')->toArray();
        $this->assertContains('Active Group', $groupNames);
        $this->assertContains('Inactive Group', $groupNames);

        // Test with active_only filter
        $response = $this->getJson('/api/rules?active_only=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Group');
    }

    /**
     * @test
     */
    public function it_gets_rule_options()
    {
        $response = $this->getJson('/api/rules/options');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'trigger_types',
                    'fields',
                    'operators',
                    'logic_operators',
                    'action_types',
                    'field_operators' => [
                        'numeric',
                        'string',
                    ],
                ],
            ])
            ->assertJsonPath('data.trigger_types', Rule::getTriggerTypes())
            ->assertJsonPath('data.fields', RuleCondition::getFields())
            ->assertJsonPath('data.operators', RuleCondition::getOperators())
            ->assertJsonPath('data.action_types', RuleAction::getActionTypes());
    }

    /**
     * @test
     */
    public function it_creates_rule_group()
    {
        $response = $this->postJson('/api/rules/groups', [
            'name' => 'New Rule Group',
            'description' => 'Test description',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Rule Group')
            ->assertJsonPath('data.description', 'Test description')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('rule_groups', [
            'user_id' => $this->user->id,
            'name' => 'New Rule Group',
        ]);
    }

    /**
     * @test
     */
    public function it_validates_rule_group_creation()
    {
        $response = $this->postJson('/api/rules/groups', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * @test
     */
    public function it_creates_rule_with_conditions_and_actions()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $category = Category::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/rules', [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Test Rule',
            'description' => 'Test rule description',
            'trigger_type' => Rule::TRIGGER_TRANSACTION_CREATED,
            'stop_processing' => false,
            'is_active' => true,
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => RuleCondition::FIELD_AMOUNT,
                            'operator' => RuleCondition::OPERATOR_GREATER_THAN,
                            'value' => '100',
                        ],
                        [
                            'field' => RuleCondition::FIELD_DESCRIPTION,
                            'operator' => RuleCondition::OPERATOR_CONTAINS,
                            'value' => 'AMAZON',
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
                [
                    'action_type' => RuleAction::ACTION_ADD_TAG,
                    'action_value' => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Rule')
            ->assertJsonPath('data.trigger_type', Rule::TRIGGER_TRANSACTION_CREATED)
            ->assertJsonCount(1, 'data.condition_groups')
            ->assertJsonCount(2, 'data.actions');

        $this->assertDatabaseHas('rules', [
            'user_id' => $this->user->id,
            'name' => 'Test Rule',
        ]);
    }

    /**
     * @test
     */
    public function it_prevents_creating_rule_for_other_users_group()
    {
        $otherUserGroup = RuleGroup::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $response = $this->postJson('/api/rules', [
            'rule_group_id' => $otherUserGroup->id,
            'name' => 'Test Rule',
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
                    'action_value' => 'Test',
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    /**
     * @test
     */
    public function it_shows_rule_details()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $rule = Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $ruleGroup->id,
        ]);

        $response = $this->getJson("/api/rules/{$rule->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $rule->id)
            ->assertJsonPath('data.name', $rule->name)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'rule_group',
                    'condition_groups',
                    'actions',
                ],
            ]);
    }

    /**
     * @test
     */
    public function it_prevents_showing_other_users_rule()
    {
        $otherRule = Rule::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $response = $this->getJson("/api/rules/{$otherRule->id}");

        $response->assertNotFound();
    }

    /**
     * @test
     */
    public function it_updates_rule()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $rule = Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Original Name',
        ]);

        $response = $this->putJson("/api/rules/{$rule->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('rules', [
            'id' => $rule->id,
            'name' => 'Updated Name',
            'is_active' => false,
        ]);
    }

    /**
     * @test
     */
    public function it_deletes_rule()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $rule = Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $ruleGroup->id,
        ]);

        $response = $this->deleteJson("/api/rules/{$rule->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Rule deleted successfully');

        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
    }

    /**
     * @test
     */
    public function it_duplicates_rule()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $rule = Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Original Rule',
        ]);

        $response = $this->postJson("/api/rules/{$rule->id}/duplicate", [
            'name' => 'Duplicated Rule',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Duplicated Rule')
            ->assertJsonPath('data.rule_group_id', $ruleGroup->id);

        $this->assertDatabaseCount('rules', 2);
        $this->assertDatabaseHas('rules', ['name' => 'Duplicated Rule']);
    }

    /**
     * @test
     */
    public function it_gets_rule_statistics()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);
        $rule = Rule::factory()->create([
            'user_id' => $this->user->id,
            'rule_group_id' => $ruleGroup->id,
        ]);

        // Create some execution logs
        $rule->executionLogs()->create([
            'transaction_id' => '12345',
            'matched' => true,
            'actions_executed' => ['test' => 'data'],
        ]);

        $rule->executionLogs()->create([
            'transaction_id' => '67890',
            'matched' => false,
        ]);

        $response = $this->getJson("/api/rules/{$rule->id}/statistics?days=30");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_executions',
                    'total_matches',
                    'match_rate',
                    'last_matched',
                    'last_executed',
                ],
            ])
            ->assertJsonPath('data.total_executions', 2)
            ->assertJsonPath('data.total_matches', 1)
            ->assertJsonPath('data.match_rate', 50);
    }

    /**
     * @test
     */
    public function it_validates_rule_creation()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/rules', [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Test Rule',
            'trigger_type' => 'invalid_trigger',
            'condition_groups' => [
                [
                    'logic_operator' => 'INVALID',
                    'conditions' => [],
                ],
            ],
            'actions' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_type',
                'condition_groups.0.logic_operator',
                'condition_groups.0.conditions',
                'actions',
            ]);
    }

    /**
     * @test
     */
    public function it_validates_condition_fields_and_operators()
    {
        $ruleGroup = RuleGroup::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/rules', [
            'rule_group_id' => $ruleGroup->id,
            'name' => 'Test Rule',
            'trigger_type' => Rule::TRIGGER_MANUAL,
            'condition_groups' => [
                [
                    'logic_operator' => 'AND',
                    'conditions' => [
                        [
                            'field' => 'invalid_field',
                            'operator' => 'invalid_operator',
                            'value' => 'test',
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'action_type' => 'invalid_action',
                    'action_value' => 'test',
                ],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'condition_groups.0.conditions.0.field',
                'condition_groups.0.conditions.0.operator',
                'actions.0.action_type',
            ]);
    }
} 