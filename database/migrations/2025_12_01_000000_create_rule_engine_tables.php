<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rule groups to organize rules
        Schema::create('rule_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['user_id', 'is_active']);
        });

        // Main rules table
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('rule_group_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type'); // 'transaction_created', 'transaction_updated', 'manual'
            $table->boolean('stop_processing')->default(false); // Stop after this rule matches
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['user_id', 'is_active', 'trigger_type']);
            $table->index(['rule_group_id', 'order']);
        });

        // Condition groups for AND/OR logic
        Schema::create('condition_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->onDelete('cascade');
            $table->enum('logic_operator', ['AND', 'OR'])->default('AND');
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index(['rule_id', 'order']);
        });

        // Individual conditions
        Schema::create('rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_group_id')->constrained()->onDelete('cascade');
            $table->string('field'); // 'amount', 'description', 'category', etc.
            $table->string('operator'); // 'equals', 'contains', 'regex', 'wildcard', 'greater_than', etc.
            $table->text('value'); // The value to compare against
            $table->boolean('is_case_sensitive')->default(false);
            $table->boolean('is_negated')->default(false); // NOT condition
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index(['condition_group_id', 'order']);
        });

        // Actions to execute when rule matches
        Schema::create('rule_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->onDelete('cascade');
            $table->string('action_type'); // 'set_category', 'set_merchant', 'add_tag', etc.
            $table->text('action_value')->nullable(); // JSON encoded value for flexibility
            $table->integer('order')->default(0);
            $table->boolean('stop_processing')->default(false); // Stop after this action
            $table->timestamps();
            
            $table->index(['rule_id', 'order']);
        });

        // Rule execution history for auditing
        Schema::create('rule_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained()->onDelete('cascade');
            $table->string('transaction_id');
            $table->boolean('matched')->default(false);
            $table->json('actions_executed')->nullable();
            $table->json('execution_context')->nullable();
            $table->timestamps();
            
            $table->index(['rule_id', 'created_at']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_execution_logs');
        Schema::dropIfExists('rule_actions');
        Schema::dropIfExists('rule_conditions');
        Schema::dropIfExists('condition_groups');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('rule_groups');
    }
}; 