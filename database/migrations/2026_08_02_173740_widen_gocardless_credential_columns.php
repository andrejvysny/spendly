<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen GoCardless credential columns from VARCHAR(255) to TEXT.
     *
     * The `encrypted` cast on these User attributes produces base64-encoded
     * AES-256-CBC payloads that can exceed 255 characters for real GoCardless
     * JWTs, silently truncating on MySQL/Postgres (SQLite has no column length
     * enforcement, which masked this in dev/test).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('gocardless_secret_id')->nullable()->change();
            $table->text('gocardless_secret_key')->nullable()->change();
            $table->text('gocardless_access_token')->nullable()->change();
            $table->text('gocardless_refresh_token')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gocardless_secret_id')->nullable()->change();
            $table->string('gocardless_secret_key')->nullable()->change();
            $table->string('gocardless_access_token')->nullable()->change();
            $table->string('gocardless_refresh_token')->nullable()->change();
        });
    }
};
