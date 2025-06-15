<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Applies schema changes to add GoCardless synchronization columns to the transactions table and an import data column to the accounts table.
     *
     * Adds `is_gocardless_synced`, `gocardless_synced_at`, and `gocardless_account_id` columns to the `transactions` table, along with an index on `gocardless_account_id`. Also adds a nullable `import_data` JSON column to the `accounts` table.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add import_data column to store additional data from import
            $table->boolean('is_gocardless_synced')
                ->default(false)
                ->after('import_data');

            $table->timestamp('gocardless_synced_at')
                ->nullable()
                ->after('is_gocardless_synced');

            $table->string('gocardless_account_id')
                ->nullable()
                ->after('gocardless_synced_at');

            // Add index for faster querying of import_data
            $table->index(['gocardless_account_id']);

        });

        Schema::table('accounts', function (Blueprint $table) {
            // Add import_data column to store additional data from import
            $table->json('import_data')->nullable()->after('gocardless_last_synced_at');
        });
    }

    /**
     * Reverts the schema changes made by the migration.
     *
     * Drops the GoCardless-related columns and index from the `transactions` table and removes the `import_data` column from the `accounts` table.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Remove columns related to GoCardless sync
            $table->dropIndex(['gocardless_account_id']);
            $table->dropColumn(['is_gocardless_synced', 'gocardless_synced_at', 'gocardless_account_id']);
            // Drop index for gocardless_account_id
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Remove import_data column
            $table->dropColumn('import_data');
        });
    }
};
