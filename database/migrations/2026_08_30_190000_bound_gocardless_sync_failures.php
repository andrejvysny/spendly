<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gocardless_sync_failures grew without bound.
 *
 * Nothing stopped the same failing row being recorded again on every sync, and
 * gocardless:retry-failures made it worse: it replays stored payloads through the canonical
 * pipeline, which writes a *fresh* failure row for each one that still fails, while the command
 * separately bumps retry_count on the old row. Every 30 minutes, forever.
 *
 * A uniqueness key per (account, external transaction) turns that into an upsert. Rows the
 * provider gave no id for keep their NULL external_transaction_id, and NULLs do not collide in a
 * unique index on any of the supported engines, so those still append — which is correct, they are
 * not identifiable as the same row.
 *
 * Pre-existing duplicates are collapsed to the newest row first, otherwise the index cannot build.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse duplicates, keeping the most recent record for each key.
        $duplicates = DB::table('gocardless_sync_failures')
            ->select('account_id', 'external_transaction_id', DB::raw('MAX(id) as keep_id'))
            ->whereNotNull('external_transaction_id')
            ->groupBy('account_id', 'external_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('gocardless_sync_failures')
                ->where('account_id', $duplicate->account_id)
                ->where('external_transaction_id', $duplicate->external_transaction_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('gocardless_sync_failures', function (Blueprint $table) {
            $table->unique(
                ['account_id', 'external_transaction_id'],
                'gc_sync_failures_account_external_unique'
            );
        });

        // raw_data moves to an `encrypted:array` cast in the same change. Rows written before this
        // hold plain JSON, which the cast cannot decrypt — so they are encrypted in place here,
        // during the boot-time migration, before the app serves a request that would read them.
        DB::table('gocardless_sync_failures')
            ->select('id', 'raw_data')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (! is_string($row->raw_data) || $row->raw_data === '') {
                        continue;
                    }

                    // Already-encrypted payloads are not valid JSON; leave those untouched so the
                    // migration stays safe to re-run.
                    json_decode($row->raw_data, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    DB::table('gocardless_sync_failures')
                        ->where('id', $row->id)
                        ->update(['raw_data' => Crypt::encryptString($row->raw_data)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('gocardless_sync_failures')
            ->select('id', 'raw_data')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (! is_string($row->raw_data) || $row->raw_data === '') {
                        continue;
                    }

                    try {
                        $plain = Crypt::decryptString($row->raw_data);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('gocardless_sync_failures')
                        ->where('id', $row->id)
                        ->update(['raw_data' => $plain]);
                }
            });

        Schema::table('gocardless_sync_failures', function (Blueprint $table) {
            $table->dropUnique('gc_sync_failures_account_external_unique');
        });
    }
};
