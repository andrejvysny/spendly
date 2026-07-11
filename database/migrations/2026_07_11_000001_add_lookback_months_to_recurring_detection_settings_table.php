<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_detection_settings', function (Blueprint $table) {
            // Detection lookback window; 24 months makes yearly series detectable.
            $table->unsignedSmallInteger('lookback_months')->default(24)->after('min_occurrences');
        });

        // Align legacy group_by values with the model constants
        // ('merchant_*' predates the merchants -> counterparties rename).
        DB::table('recurring_detection_settings')
            ->where('group_by', 'merchant_and_description')
            ->update(['group_by' => 'counterparty_and_description']);
        DB::table('recurring_detection_settings')
            ->where('group_by', 'merchant_only')
            ->update(['group_by' => 'counterparty_only']);
    }

    public function down(): void
    {
        Schema::table('recurring_detection_settings', function (Blueprint $table) {
            $table->dropColumn('lookback_months');
        });
    }
};
