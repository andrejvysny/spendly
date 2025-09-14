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
            // Add reconciled timestamp and note fields
            $table->timestamp('reconciled_at')->nullable()->after('is_reconciled');
            $table->text('reconciled_note')->nullable()->after('reconciled_at');
            
            // Add fingerprint field for duplicate detection
            $table->string('fingerprint', 64)->nullable()->unique()->after('reconciled_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
            $table->dropColumn(['reconciled_at', 'reconciled_note', 'fingerprint']);
        });
    }
};
