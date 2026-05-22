<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_personalization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->boolean('auto_apply_suggestions')->default(false);
            $table->boolean('auto_retrain')->default(false);
            $table->unsignedInteger('retrain_threshold')->default(10);
            $table->string('model_version')->nullable();
            $table->timestamp('last_trained_at')->nullable();
            $table->json('personalization_vector')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_personalization_settings');
    }
};
