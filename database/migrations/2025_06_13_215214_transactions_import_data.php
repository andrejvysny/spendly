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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Remove columns related to GoCardless sync
            $table->dropColumn(['is_gocardless_synced', 'gocardless_synced_at', 'gocardless_account_id']);
            // Drop index for gocardless_account_id
            $table->dropIndex(['gocardless_account_id']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Remove import_data column
            $table->dropColumn('import_data');
        });
    }
};
