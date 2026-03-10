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
            // 1. Create budget_periods table
            Schema::create('budget_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('amount_budgeted', 12, 2);
                $table->decimal('rollover_amount', 12, 2)->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['budget_id', 'start_date']);
            });

            // 2. Add new columns to budgets table
            Schema::table('budgets', function (Blueprint $table) {
                $table->string('mode', 20)->default('limit')->after('currency');
                $table->boolean('rollover_enabled')->default(false)->after('name');
                $table->boolean('include_subcategories')->default(true)->after('rollover_enabled');
                $table->boolean('auto_create_next')->default(true)->after('include_subcategories');
                $table->string('overall_limit_mode', 20)->nullable()->after('auto_create_next');
                $table->boolean('is_active')->default(true)->after('overall_limit_mode');
                $table->integer('sort_order')->default(0)->after('is_active');
                $table->text('notes')->nullable()->after('sort_order');
            });

            // 3. Add base_currency, budget_mode to users table
            Schema::table('users', function (Blueprint $table) {
                $table->string('base_currency', 3)->default('EUR')->after('password');
                $table->string('budget_mode', 20)->default('limit')->after('base_currency');
            });

            // 4. Migrate existing budget data → budget_periods
            $budgets = DB::table('budgets')->get();
            foreach ($budgets as $budget) {
                if ($budget->period_type === 'monthly' && $budget->month >= 1) {
                    $startDate = sprintf('%04d-%02d-01', $budget->year, $budget->month);
                    $endDate = date('Y-m-t', (int) strtotime($startDate));
                } else {
                    $startDate = sprintf('%04d-01-01', $budget->year);
                    $endDate = sprintf('%04d-12-31', $budget->year);
                }

                $now = now();
                $status = $endDate < $now->format('Y-m-d') ? 'closed' : 'active';

                DB::table('budget_periods')->insert([
                    'budget_id' => $budget->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'amount_budgeted' => $budget->amount,
                    'rollover_amount' => 0,
                    'status' => $status,
                    'closed_at' => $status === 'closed' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 5. Make category_id nullable (for overall budgets)
            // SQLite doesn't support ALTER COLUMN, so we rebuild the table
            // For now, we'll handle nullable at model level since SQLite has limited ALTER support
            // The new unique constraint will be handled after dropping year/month

            // 6. Drop year, month columns and update unique constraint
            // SQLite requires table rebuild for column drops
            $this->rebuildBudgetsTable();
        });
    }

    private function rebuildBudgetsTable(): void
    {
        // Create temp table with new schema
        Schema::create('budgets_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('mode', 20)->default('limit');
            $table->string('period_type', 20)->default('monthly');
            $table->string('name')->nullable();
            $table->boolean('rollover_enabled')->default(false);
            $table->boolean('include_subcategories')->default(true);
            $table->boolean('auto_create_next')->default(true);
            $table->string('overall_limit_mode', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'category_id', 'currency', 'period_type']);
        });

        // Copy data from old to new
        DB::statement('INSERT INTO budgets_new (id, user_id, category_id, amount, currency, mode, period_type, name, rollover_enabled, include_subcategories, auto_create_next, overall_limit_mode, is_active, sort_order, notes, created_at, updated_at) SELECT id, user_id, category_id, amount, currency, mode, period_type, name, rollover_enabled, include_subcategories, auto_create_next, overall_limit_mode, is_active, sort_order, notes, created_at, updated_at FROM budgets');

        // Drop old and rename
        Schema::drop('budgets');
        Schema::rename('budgets_new', 'budgets');
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Rebuild budgets with year/month columns
            Schema::create('budgets_old', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3);
                $table->string('period_type', 10);
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('name')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'category_id', 'period_type', 'year', 'month']);
            });

            // Restore data from budget_periods
            $budgets = DB::table('budgets')->get();
            foreach ($budgets as $budget) {
                $period = DB::table('budget_periods')
                    ->where('budget_id', $budget->id)
                    ->orderBy('start_date', 'desc')
                    ->first();

                /** @var object{start_date: string}|null $period */
                $year = $period !== null ? (int) date('Y', (int) strtotime($period->start_date)) : (int) date('Y');
                $month = 0;
                if ($budget->period_type === 'monthly' && $period !== null) {
                    $month = (int) date('n', (int) strtotime($period->start_date));
                }

                DB::table('budgets_old')->insert([
                    'id' => $budget->id,
                    'user_id' => $budget->user_id,
                    'category_id' => $budget->category_id,
                    'amount' => $budget->amount,
                    'currency' => $budget->currency,
                    'period_type' => $budget->period_type,
                    'year' => $year,
                    'month' => $month,
                    'name' => $budget->name,
                    'created_at' => $budget->created_at,
                    'updated_at' => $budget->updated_at,
                ]);
            }

            Schema::drop('budgets');
            Schema::rename('budgets_old', 'budgets');
            Schema::dropIfExists('budget_periods');

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['base_currency', 'budget_mode']);
            });
        });
    }
};
