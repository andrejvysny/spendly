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
        Schema::dropIfExists('transaction_rules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate transaction_rules table for rollback
        Schema::create('transaction_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('trigger_type');
            $table->string('condition_type');
            $table->string('condition_operator');
            $table->string('condition_value');
            $table->string('action_type');
            $table->string('action_value');
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }
};
