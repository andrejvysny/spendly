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
        Schema::rename('merchants', 'counterparties');

        Schema::table('counterparties', function (Blueprint $table) {
            $table->string('type')->default('merchant')->after('logo');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('merchant_id', 'counterparty_id');
        });

        Schema::table('recurring_groups', function (Blueprint $table) {
            $table->renameColumn('merchant_id', 'counterparty_id');
        });

        // Update rule engine stored values
        DB::table('rule_conditions')->where('field', 'merchant')->update(['field' => 'counterparty']);
        DB::table('rule_conditions')->where('field', 'has_merchant')->update(['field' => 'has_counterparty']);

        DB::table('rule_actions')->where('action_type', 'set_merchant')->update(['action_type' => 'set_counterparty']);
        DB::table('rule_actions')->where('action_type', 'create_merchant_if_not_exists')->update(['action_type' => 'create_counterparty_if_not_exists']);
        DB::table('rule_actions')->where('action_type', 'clear_merchant')->update(['action_type' => 'clear_counterparty']);
    }

    public function down(): void
    {
        // Revert rule engine stored values
        DB::table('rule_actions')->where('action_type', 'clear_counterparty')->update(['action_type' => 'clear_merchant']);
        DB::table('rule_actions')->where('action_type', 'create_counterparty_if_not_exists')->update(['action_type' => 'create_merchant_if_not_exists']);
        DB::table('rule_actions')->where('action_type', 'set_counterparty')->update(['action_type' => 'set_merchant']);

        DB::table('rule_conditions')->where('field', 'has_counterparty')->update(['field' => 'has_merchant']);
        DB::table('rule_conditions')->where('field', 'counterparty')->update(['field' => 'merchant']);

        Schema::table('recurring_groups', function (Blueprint $table) {
            $table->renameColumn('counterparty_id', 'merchant_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('counterparty_id', 'merchant_id');
        });

        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('counterparties', 'merchants');
    }
};
