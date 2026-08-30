<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync moved from an inline HTTP request to a queued per-account job, so the account row is
 * now the only place a client can learn what happened to a sync it can no longer watch.
 *
 * gocardless_last_synced_at is deliberately untouched: that is the data watermark the next
 * fetch window is derived from, and it must keep meaning "how far transactions are known
 * good", not "when a job last ran".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // idle|queued|syncing|success|failed|rate_limited|needs_reconnect
            $table->string('gocardless_sync_status', 16)->default('idle');
            $table->timestamp('gocardless_sync_queued_at')->nullable();
            $table->timestamp('gocardless_sync_started_at')->nullable();
            $table->timestamp('gocardless_sync_finished_at')->nullable();
            $table->string('gocardless_sync_error')->nullable();
            // Cooldown floor: no sync may be dispatched for this account before this instant.
            $table->timestamp('gocardless_sync_retry_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'gocardless_sync_status',
                'gocardless_sync_queued_at',
                'gocardless_sync_started_at',
                'gocardless_sync_finished_at',
                'gocardless_sync_error',
                'gocardless_sync_retry_after',
            ]);
        });
    }
};
