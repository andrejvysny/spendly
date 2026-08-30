<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the import_failures.import_id foreign key.
 *
 * It cannot live in create_import_failures_table: that migration is dated
 * 2025_01_02, before create_imports_table (2025_05_11_103545), so on a fresh
 * database the referenced table does not exist yet. SQLite accepted the dangling
 * reference silently; PostgreSQL failed the migration and made a fresh pgsql
 * install impossible.
 *
 * Installs created before this migration existed already carry the constraint, so
 * adding it is wrapped — a duplicate is not an error worth failing a deploy over.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_failures') || ! Schema::hasTable('imports')) {
            return;
        }

        try {
            Schema::table('import_failures', function (Blueprint $table) {
                $table->foreign('import_id')
                    ->references('id')
                    ->on('imports')
                    ->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Already present (pre-existing sqlite installs). Nothing to do.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_failures')) {
            return;
        }

        try {
            Schema::table('import_failures', function (Blueprint $table) {
                $table->dropForeign(['import_id']);
            });
        } catch (\Throwable $e) {
            // Not present; nothing to drop.
        }
    }
};
