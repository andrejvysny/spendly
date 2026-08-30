<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('gocardless_requisition_id')->nullable()->constrained('gocardless_requisitions')->nullOnDelete();
            $table->boolean('gocardless_needs_reconnect')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gocardless_requisition_id');
            $table->dropColumn('gocardless_needs_reconnect');
        });
    }
};
