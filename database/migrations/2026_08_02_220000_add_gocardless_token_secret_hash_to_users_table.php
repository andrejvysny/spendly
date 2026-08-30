<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which GoCardless secret pair the stored tokens were minted from.
     *
     * Access/refresh tokens are only valid for the credentials that issued them. With both an
     * instance-level pair and per-user overrides in play, the pair backing a user can change
     * without the tokens changing — switching to a personal override, removing it and falling
     * back to the instance pair, or an administrator rotating the instance secrets. Storing the
     * pair's fingerprint lets TokenManager notice the swap and mint fresh tokens instead of
     * replaying tokens the new credentials never issued.
     *
     * Holds a SHA-256 hex digest of the secret id, so 64 chars exactly. Nullable: existing rows
     * predate the column and are treated as "unknown pair", forcing one fresh mint.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gocardless_token_secret_hash', 64)->nullable()->after('gocardless_secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gocardless_token_secret_hash');
        });
    }
};
