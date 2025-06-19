<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if we're using SQLite
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support dropping foreign keys or modifying columns with foreign keys
            // We need to recreate the table

            // First, check if the table exists and has data
            if (Schema::hasTable('transaction_fingerprints')) {
                // Create a temporary table with the new structure
                DB::statement('CREATE TABLE transaction_fingerprints_temp AS SELECT * FROM transaction_fingerprints');

                // Drop the original table
                Schema::dropIfExists('transaction_fingerprints');

                // Create the new table with nullable transaction_id
                Schema::create('transaction_fingerprints', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('transaction_id')->nullable();
                    $table->string('fingerprint')->unique();
                    $table->timestamps();

                    $table->foreign('transaction_id')->references('id')->on('transactions');
                    $table->index('transaction_id');
                });

                // Copy data back from temporary table if it exists
                if (DB::getDriverName() === 'sqlite' && DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name='transaction_fingerprints_temp'")) {
                    DB::statement('INSERT INTO transaction_fingerprints SELECT * FROM transaction_fingerprints_temp');
                    DB::statement('DROP TABLE transaction_fingerprints_temp');
                }
            }
        } else {
            // For MySQL/PostgreSQL, use the standard approach
            Schema::table('transaction_fingerprints', function (Blueprint $table) {
                // Drop the existing foreign key constraint
                $table->dropForeign(['transaction_id']);

                // Make transaction_id nullable
                $table->unsignedBigInteger('transaction_id')->nullable()->change();

                // Re-add the foreign key without cascade delete
                $table->foreign('transaction_id')->references('id')->on('transactions');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // For SQLite, recreate the table with non-nullable transaction_id
            if (Schema::hasTable('transaction_fingerprints')) {
                // Create a temporary table
                DB::statement('CREATE TABLE transaction_fingerprints_temp AS SELECT * FROM transaction_fingerprints WHERE transaction_id IS NOT NULL');

                // Drop the original table
                Schema::dropIfExists('transaction_fingerprints');

                // Create the new table with non-nullable transaction_id
                Schema::create('transaction_fingerprints', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('transaction_id');
                    $table->string('fingerprint')->unique();
                    $table->timestamps();

                    $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
                    $table->index('transaction_id');
                });

                // Copy data back from temporary table
                if (DB::getDriverName() === 'sqlite' && DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name='transaction_fingerprints_temp'")) {
                    DB::statement('INSERT INTO transaction_fingerprints SELECT * FROM transaction_fingerprints_temp');
                    DB::statement('DROP TABLE transaction_fingerprints_temp');
                }
            }
        } else {
            Schema::table('transaction_fingerprints', function (Blueprint $table) {
                // Drop the foreign key
                $table->dropForeign(['transaction_id']);

                // Make transaction_id non-nullable again
                $table->unsignedBigInteger('transaction_id')->nullable(false)->change();

                // Re-add the foreign key with cascade delete
                $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            });
        }
    }
};
