<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('interval', 20); // weekly, monthly, quarterly, yearly
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->decimal('amount_min', 12, 2);
            $table->decimal('amount_max', 12, 2);
            $table->string('scope', 20); // per_account, per_user
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('normalized_description', 500)->nullable();
            $table->string('status', 20)->default('suggested'); // suggested, confirmed, dismissed
            $table->json('detection_config_snapshot')->nullable();
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->string('dismissal_fingerprint', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_groups');
    }
};
