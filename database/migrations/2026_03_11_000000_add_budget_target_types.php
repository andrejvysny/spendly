<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->rebuildBudgetsTable();
        });
    }

    private function rebuildBudgetsTable(): void
    {
        Schema::create('budgets_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recurring_group_id')->nullable()->constrained('recurring_groups')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type', 20)->default('category');
            $table->string('target_key', 50)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('mode', 20)->default('limit');
            $table->string('period_type', 20)->default('monthly');
            $table->string('name')->nullable();
            $table->boolean('rollover_enabled')->default(false);
            $table->decimal('rollover_cap', 12, 2)->nullable();
            $table->boolean('include_subcategories')->default(true);
            $table->boolean('include_transfers')->default(false);
            $table->boolean('auto_create_next')->default(true);
            $table->string('overall_limit_mode', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'target_key', 'currency', 'period_type']);
            $table->index('target_type');
            $table->index('tag_id');
            $table->index('counterparty_id');
            $table->index('recurring_group_id');
            $table->index('account_id');
        });

        // Insert only one budget per (user_id, target_key, currency, period_type) to satisfy unique constraint.
        // Keep the most recently updated row when duplicates exist.
        DB::statement("
            INSERT INTO budgets_new (
                id, user_id, category_id, tag_id, counterparty_id, recurring_group_id, account_id,
                target_type, target_key,
                amount, currency, mode, period_type, name,
                rollover_enabled, rollover_cap, include_subcategories, include_transfers,
                auto_create_next, overall_limit_mode, is_active, sort_order, notes,
                created_at, updated_at
            )
            SELECT
                id, user_id, category_id, NULL, NULL, NULL, NULL,
                CASE WHEN category_id IS NULL THEN 'overall' ELSE 'category' END,
                CASE WHEN category_id IS NULL THEN 'overall' ELSE 'cat:' || category_id END,
                amount, currency, mode, period_type, name,
                rollover_enabled, rollover_cap, include_subcategories, 0,
                auto_create_next, overall_limit_mode, is_active, sort_order, notes,
                created_at, updated_at
            FROM budgets
            WHERE id IN (
                SELECT MAX(id) FROM budgets
                GROUP BY user_id,
                    CASE WHEN category_id IS NULL THEN 'overall' ELSE 'cat:' || category_id END,
                    currency, period_type
            )
        ");

        Schema::drop('budgets');
        Schema::rename('budgets_new', 'budgets');
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::create('budgets_old', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3);
                $table->string('mode', 20)->default('limit');
                $table->string('period_type', 20)->default('monthly');
                $table->string('name')->nullable();
                $table->boolean('rollover_enabled')->default(false);
                $table->decimal('rollover_cap', 12, 2)->nullable();
                $table->boolean('include_subcategories')->default(true);
                $table->boolean('auto_create_next')->default(true);
                $table->string('overall_limit_mode', 20)->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'category_id', 'currency', 'period_type']);
            });

            DB::statement("
                INSERT INTO budgets_old (
                    id, user_id, category_id, amount, currency, mode, period_type, name,
                    rollover_enabled, rollover_cap, include_subcategories,
                    auto_create_next, overall_limit_mode, is_active, sort_order, notes,
                    created_at, updated_at
                )
                SELECT
                    id, user_id, category_id, amount, currency, mode, period_type, name,
                    rollover_enabled, rollover_cap, include_subcategories,
                    auto_create_next, overall_limit_mode, is_active, sort_order, notes,
                    created_at, updated_at
                FROM budgets
                WHERE target_type IN ('category', 'overall')
            ");

            Schema::drop('budgets');
            Schema::rename('budgets_old', 'budgets');
        });
    }
};
