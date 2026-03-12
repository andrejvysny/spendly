<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3)->default('EUR');
            $table->char('target_currency', 3);
            $table->decimal('rate', 15, 8);
            $table->date('date');
            $table->string('source', 20)->default('ecb');
            $table->timestamps();

            $table->unique(['base_currency', 'target_currency', 'date']);
            $table->index(['target_currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
