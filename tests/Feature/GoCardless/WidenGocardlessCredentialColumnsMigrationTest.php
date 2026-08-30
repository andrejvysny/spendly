<?php

declare(strict_types=1);

namespace Tests\Feature\GoCardless;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the 2026_08_02_173740_widen_gocardless_credential_columns
 * migration: encrypted GoCardless credential columns must accept payloads
 * longer than the original VARCHAR(255), since the `encrypted` cast produces
 * base64 AES-256-CBC ciphertext that regularly exceeds 255 chars for real
 * GoCardless JWTs.
 *
 * SQLite enforces no column length limit, so this test mainly documents the
 * intent and guards against the migration being reverted; the truncation bug
 * itself only manifests on MySQL/Postgres.
 */
class WidenGocardlessCredentialColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_encrypted_credentials_round_trip(): void
    {
        $longValue = str_repeat('a', 512);

        $user = User::factory()->create([
            'gocardless_secret_id' => $longValue,
            'gocardless_secret_key' => $longValue,
            'gocardless_access_token' => $longValue,
            'gocardless_refresh_token' => $longValue,
        ]);

        $fresh = $user->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame($longValue, $fresh->gocardless_secret_id);
        $this->assertSame($longValue, $fresh->gocardless_secret_key);
        $this->assertSame($longValue, $fresh->gocardless_access_token);
        $this->assertSame($longValue, $fresh->gocardless_refresh_token);
        $this->assertSame(512, strlen($fresh->gocardless_secret_id));
    }

    public function test_gocardless_credential_columns_are_text_type(): void
    {
        $rows = \DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'");
        $this->assertNotEmpty($rows);

        $createSql = $rows[0]->sql;

        foreach (['gocardless_secret_id', 'gocardless_secret_key', 'gocardless_access_token', 'gocardless_refresh_token'] as $column) {
            $this->assertMatchesRegularExpression(
                '/"'.$column.'"\s+text/i',
                $createSql,
                "Column {$column} is not TEXT type"
            );
        }
    }
}
