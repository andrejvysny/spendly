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
        // Update users table with GoCardless fields
        Schema::table('users', function (Blueprint $table) {
            $table->string('gocardless_secret_id')->nullable();
            $table->string('gocardless_secret_key')->nullable();
            $table->string('gocardless_access_token')->nullable();
            $table->string('gocardless_refresh_token')->nullable();
            $table->timestamp('gocardless_refresh_token_expires_at')->nullable();
            $table->timestamp('gocardless_access_token_expires_at')->nullable();
            $table->string('gocardless_country', 2)->nullable();
        });

        // Create accounts table
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('type')->default('checking');
            $table->string('currency', 3);
            $table->decimal('balance', 10, 2);
            $table->string('gocardless_account_id')->nullable();
            $table->boolean('is_gocardless_synced')->default(false);
            $table->timestamp('gocardless_last_synced_at')->nullable();
            $table->timestamps();
        });

        // Create transactions table
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('transaction_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->timestamp('booked_date');
            $table->timestamp('processed_date');
            $table->string('description');
            $table->string('target_iban', 34)->nullable();
            $table->string('source_iban', 34)->nullable();
            $table->string('partner')->nullable();
            $table->string('type');
            $table->json('metadata')->nullable();
            $table->decimal('balance_after_transaction', 10, 2);
            $table->timestamps();
        });

        // Create transaction_rules table
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_rules');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');

        // Remove GoCardless fields from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'gocardless_secret_id',
                'gocardless_secret_key',
                'gocardless_access_token',
                'gocardless_refresh_token',
                'gocardless_refresh_token_expires_at',
                'gocardless_access_token_expires_at',
                'gocardless_country',
            ]);
        });
    }
};
