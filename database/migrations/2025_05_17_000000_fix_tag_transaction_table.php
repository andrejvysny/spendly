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
        // Check if the old table exists and rename it if it does
        if (Schema::hasTable('transaction_tag')) {
            Schema::rename('transaction_tag', 'tag_transaction');
        }
        // Otherwise create the tag_transaction table if it doesn't exist
        elseif (! Schema::hasTable('tag_transaction')) {
            Schema::create('tag_transaction', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['transaction_id', 'tag_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tag_transaction')) {
            Schema::rename('tag_transaction', 'transaction_tag');
        }
    }
};
