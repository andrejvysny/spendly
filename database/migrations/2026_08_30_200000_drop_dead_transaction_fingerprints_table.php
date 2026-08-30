<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops transaction_fingerprints.
 *
 * Created in 2025_07_01 and amended in 2025_06_15, but never read or written: there is no model, no
 * repository, and no query anywhere in app/, tests/ or resources/. Deduplication actually keys on
 * transactions.fingerprint plus the (account_id, transaction_id) unique index, so this table was
 * only ever a place for a future reader to be misled about where identity is decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('transaction_fingerprints');
    }

    public function down(): void
    {
        Schema::create('transaction_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });
    }
};
