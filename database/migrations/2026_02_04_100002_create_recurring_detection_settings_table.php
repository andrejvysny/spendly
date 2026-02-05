<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_detection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('scope', 20)->default('per_account'); // per_account, per_user
            $table->string('group_by', 30)->default('merchant_and_description'); // merchant_only, merchant_and_description
            $table->string('amount_variance_type', 20)->default('percent'); // percent, fixed
            $table->decimal('amount_variance_value', 10, 2)->default(5.00);
            $table->unsignedTinyInteger('min_occurrences')->default(3);
            $table->boolean('run_after_import')->default(true);
            $table->boolean('scheduled_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_detection_settings');
    }
};
