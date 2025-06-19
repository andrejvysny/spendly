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
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'duplicate_identifier')) {
                $table->string('duplicate_identifier', 64)->nullable()->after('gocardless_account_id');
            }
            if (! Schema::hasColumn('transactions', 'original_amount')) {
                $table->decimal('original_amount', 10, 2)->nullable()->after('duplicate_identifier');
            }
            if (! Schema::hasColumn('transactions', 'original_currency')) {
                $table->string('original_currency', 3)->nullable()->after('original_amount');
            }
            if (! Schema::hasColumn('transactions', 'original_booked_date')) {
                $table->timestamp('original_booked_date')->nullable()->after('original_currency');
            }
            if (! Schema::hasColumn('transactions', 'original_source_iban')) {
                $table->string('original_source_iban', 34)->nullable()->after('original_booked_date');
            }
            if (! Schema::hasColumn('transactions', 'original_target_iban')) {
                $table->string('original_target_iban', 34)->nullable()->after('original_source_iban');
            }
            if (! Schema::hasColumn('transactions', 'original_partner')) {
                $table->string('original_partner')->nullable()->after('original_target_iban');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'duplicate_identifier')) {
                $table->dropColumn('duplicate_identifier');
            }
            if (Schema::hasColumn('transactions', 'original_amount')) {
                $table->dropColumn('original_amount');
            }
            if (Schema::hasColumn('transactions', 'original_currency')) {
                $table->dropColumn('original_currency');
            }
            if (Schema::hasColumn('transactions', 'original_booked_date')) {
                $table->dropColumn('original_booked_date');
            }
            if (Schema::hasColumn('transactions', 'original_source_iban')) {
                $table->dropColumn('original_source_iban');
            }
            if (Schema::hasColumn('transactions', 'original_target_iban')) {
                $table->dropColumn('original_target_iban');
            }
            if (Schema::hasColumn('transactions', 'original_partner')) {
                $table->dropColumn('original_partner');
            }
        });
    }
};
