<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_groups', function (Blueprint $table) {
            // Detection confidence 0-100 (null for pre-v2 rows).
            $table->unsignedTinyInteger('confidence')->nullable()->after('amount_max');
            // Latest price plateau median (the current subscription price).
            $table->decimal('amount_current', 12, 2)->nullable()->after('confidence');
            // Series currency (detection groups per currency since v2).
            $table->string('currency', 3)->nullable()->after('amount_current');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_groups', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'amount_current', 'currency']);
        });
    }
};
