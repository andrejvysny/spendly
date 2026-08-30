<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistence layer for GoCardless requisitions/EUAs. Previously these only
     * lived in a 1h cache entry + session fallback (see
     * GoCardlessRequisitionController::createRequisition).
     */
    public function up(): void
    {
        Schema::create('gocardless_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('requisition_id', 64);
            $table->uuid('reference')->unique();
            $table->string('institution_id', 128);
            $table->string('institution_name')->nullable();
            $table->string('agreement_id', 64)->nullable();
            $table->string('status', 24)->index(); // local GoCardlessRequisitionStatus enum value
            $table->string('gocardless_status', 2)->nullable(); // raw GoCardless StatusEnum code
            $table->unsignedSmallInteger('max_historical_days')->nullable();
            $table->unsignedSmallInteger('access_valid_for_days')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('access_valid_until')->nullable();
            $table->boolean('access_valid_until_estimated')->default(true);
            $table->json('accounts')->nullable(); // list of GoCardless account IDs
            $table->text('link')->nullable(); // bank authentication URL
            $table->string('return_to', 16)->nullable();
            $table->timestamp('callback_completed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expiry_warning_sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'requisition_id']);
            $table->index('requisition_id');
            $table->index(['user_id', 'status']);
            $table->index(['status', 'access_valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gocardless_requisitions');
    }
};
