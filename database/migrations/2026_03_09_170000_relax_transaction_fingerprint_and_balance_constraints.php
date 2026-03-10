<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('balance_after_transaction', 10, 2)->nullable()->change();
            $table->index(['account_id', 'fingerprint'], 'transactions_account_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_fingerprint_index');
            $table->decimal('balance_after_transaction', 10, 2)->nullable(false)->change();
            $table->unique('fingerprint');
        });
    }
};
