<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sync job called GoCardlessService::syncAccountTransactions() and threw away its return
 * value, then stamped the account `success` unconditionally. A run in which every single
 * transaction failed validation was therefore indistinguishable from a clean one — the counts
 * existed only in a log line, and the polling endpoint had nothing to report but a status.
 *
 * Stored as JSON rather than a column per counter because the stat set belongs to
 * TransactionSyncService and has already grown once (`dropped`); pinning each key to its own
 * column would make every future counter a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // {created, updated, skipped, dropped, errors, needs_review, total} from the last run.
            $table->json('gocardless_sync_stats')->nullable()->after('gocardless_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('gocardless_sync_stats');
        });
    }
};
