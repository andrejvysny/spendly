<?php

namespace Tests\Feature\RuleEngine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_creates_rule_groups_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('rule_groups'));

        $columns = Schema::getColumnListing('rule_groups');
        $expectedColumns = [
            'id',
            'user_id',
            'name',
            'description',
            'order',
            'is_active',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from rule_groups table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('rule_groups');
        $this->assertArrayHasKey('rule_groups_user_id_is_active_index', $indexes);
    }

    /**
     * @test
     */
    public function it_creates_rules_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('rules'));

        $columns = Schema::getColumnListing('rules');
        $expectedColumns = [
            'id',
            'user_id',
            'rule_group_id',
            'name',
            'description',
            'trigger_type',
            'stop_processing',
            'order',
            'is_active',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from rules table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('rules');
        $this->assertArrayHasKey('rules_user_id_is_active_trigger_type_index', $indexes);
        $this->assertArrayHasKey('rules_rule_group_id_order_index', $indexes);

        // Check foreign key columns exist
        $this->assertTrue(Schema::hasColumn('rules', 'user_id'));
        $this->assertTrue(Schema::hasColumn('rules', 'rule_group_id'));
    }

    /**
     * @test
     */
    public function it_creates_condition_groups_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('condition_groups'));

        $columns = Schema::getColumnListing('condition_groups');
        $expectedColumns = [
            'id',
            'rule_id',
            'logic_operator',
            'order',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from condition_groups table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('condition_groups');
        $this->assertArrayHasKey('condition_groups_rule_id_order_index', $indexes);

        // Check foreign key column exists
        $this->assertTrue(Schema::hasColumn('condition_groups', 'rule_id'));
    }

    /**
     * @test
     */
    public function it_creates_rule_conditions_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('rule_conditions'));

        $columns = Schema::getColumnListing('rule_conditions');
        $expectedColumns = [
            'id',
            'condition_group_id',
            'field',
            'operator',
            'value',
            'is_case_sensitive',
            'is_negated',
            'order',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from rule_conditions table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('rule_conditions');
        $this->assertArrayHasKey('rule_conditions_condition_group_id_order_index', $indexes);

        // Check foreign key column exists
        $this->assertTrue(Schema::hasColumn('rule_conditions', 'condition_group_id'));
    }

    /**
     * @test
     */
    public function it_creates_rule_actions_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('rule_actions'));

        $columns = Schema::getColumnListing('rule_actions');
        $expectedColumns = [
            'id',
            'rule_id',
            'action_type',
            'action_value',
            'order',
            'stop_processing',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from rule_actions table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('rule_actions');
        $this->assertArrayHasKey('rule_actions_rule_id_order_index', $indexes);

        // Check foreign key column exists
        $this->assertTrue(Schema::hasColumn('rule_actions', 'rule_id'));
    }

    /**
     * @test
     */
    public function it_creates_rule_execution_logs_table_with_correct_schema()
    {
        $this->assertTrue(Schema::hasTable('rule_execution_logs'));

        $columns = Schema::getColumnListing('rule_execution_logs');
        $expectedColumns = [
            'id',
            'rule_id',
            'transaction_id',
            'matched',
            'actions_executed',
            'execution_context',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column {$column} is missing from rule_execution_logs table");
        }

        // Check indexes
        $indexes = $this->getTableIndexes('rule_execution_logs');
        $this->assertArrayHasKey('rule_execution_logs_rule_id_created_at_index', $indexes);
        $this->assertArrayHasKey('rule_execution_logs_transaction_id_index', $indexes);

        // Check foreign key column exists
        $this->assertTrue(Schema::hasColumn('rule_execution_logs', 'rule_id'));
    }

    /**
     * @test
     */
    public function it_adds_is_reconciled_column_to_transactions_table()
    {
        $this->assertTrue(Schema::hasTable('transactions'));
        $this->assertTrue(Schema::hasColumn('transactions', 'is_reconciled'));

        // Check index on is_reconciled
        $indexes = $this->getTableIndexes('transactions');
        $this->assertArrayHasKey('transactions_is_reconciled_index', $indexes);
    }

    /**
     * @test
     */
    public function it_has_correct_foreign_key_constraints()
    {
        // Test cascade deletes
        $user = \App\Models\User::factory()->create();
        $ruleGroup = \App\Models\RuleGroup::factory()->create(['user_id' => $user->id]);
        $rule = \App\Models\Rule::factory()->create([
            'user_id' => $user->id,
            'rule_group_id' => $ruleGroup->id,
        ]);
        $conditionGroup = \App\Models\ConditionGroup::factory()->create(['rule_id' => $rule->id]);
        $condition = \App\Models\RuleCondition::factory()->create(['condition_group_id' => $conditionGroup->id]);
        $action = \App\Models\RuleAction::factory()->create(['rule_id' => $rule->id]);
        $log = \App\Models\RuleExecutionLog::factory()->create(['rule_id' => $rule->id]);

        // Delete user should cascade
        $user->delete();

        $this->assertDatabaseMissing('rule_groups', ['id' => $ruleGroup->id]);
        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
        $this->assertDatabaseMissing('condition_groups', ['id' => $conditionGroup->id]);
        $this->assertDatabaseMissing('rule_conditions', ['id' => $condition->id]);
        $this->assertDatabaseMissing('rule_actions', ['id' => $action->id]);
        $this->assertDatabaseMissing('rule_execution_logs', ['id' => $log->id]);
    }

    /**
     * @test
     */
    public function it_has_correct_data_types()
    {
        // Create test data to verify data types
        $user = \App\Models\User::factory()->create();
        $ruleGroup = \App\Models\RuleGroup::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Group',
            'description' => 'Long description text that should be stored as TEXT type',
            'order' => 5,
            'is_active' => true,
        ]);

        $rule = \App\Models\Rule::factory()->create([
            'user_id' => $user->id,
            'rule_group_id' => $ruleGroup->id,
            'stop_processing' => false,
        ]);

        $conditionGroup = \App\Models\ConditionGroup::factory()->create([
            'rule_id' => $rule->id,
            'logic_operator' => 'AND',
        ]);

        $condition = \App\Models\RuleCondition::factory()->create([
            'condition_group_id' => $conditionGroup->id,
            'value' => str_repeat('Long text value ', 100), // Test TEXT field
            'is_case_sensitive' => true,
            'is_negated' => false,
        ]);

        $action = \App\Models\RuleAction::factory()->create([
            'rule_id' => $rule->id,
            'action_value' => json_encode(['complex' => 'data']), // Test TEXT field with JSON
            'stop_processing' => true,
        ]);

        $log = \App\Models\RuleExecutionLog::factory()->create([
            'rule_id' => $rule->id,
            'matched' => true,
            'actions_executed' => ['action1', 'action2'], // Test JSON field
            'execution_context' => ['key' => 'value'], // Test JSON field
        ]);

        // Verify data was stored correctly
        $this->assertDatabaseHas('rule_groups', [
            'id' => $ruleGroup->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('rule_conditions', [
            'id' => $condition->id,
            'is_case_sensitive' => true,
            'is_negated' => false,
        ]);

        $freshLog = \App\Models\RuleExecutionLog::find($log->id);
        $this->assertIsArray($freshLog->actions_executed);
        $this->assertIsArray($freshLog->execution_context);
    }

    /**
     * Helper method to get table indexes
     */
    private function getTableIndexes(string $table): array
    {
        $indexes = [];
        $results = \DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$table]);

        foreach ($results as $result) {
            $indexes[$result->name] = true;
        }

        return $indexes;
    }

    /**
     * Helper method to check if a foreign key exists
     */
    private function hasForeignKey(string $table, string $foreignKey): bool
    {
        // In SQLite, we check the table schema for REFERENCES
        $sql = \DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);

        if (! empty($sql)) {
            $createSql = $sql[0]->sql;

            // Check if the table has any foreign key constraints
            return str_contains($createSql, 'REFERENCES') || str_contains($createSql, 'FOREIGN KEY');
        }

        return false;
    }
}
