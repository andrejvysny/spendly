<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitiveValueProvider(): array
    {
        return [
            'secret_id' => ['{"secret_id":"sid_abc123"}', 'sid_abc123'],
            'secret_key' => ['{"secret_key":"sk_live_topsecret"}', 'sk_live_topsecret'],
            'access token' => ['{"access":"eyJhbGciOi.abc.def"}', 'eyJhbGciOi.abc.def'],
            'refresh token' => ['{"refresh":"refresh_value_xyz"}', 'refresh_value_xyz'],
            'generic token key' => ['{"token":"plain_token_value"}', 'plain_token_value'],
            'ssn' => ['{"ssn":"123-45-6789"}', '123-45-6789'],
            'iban' => ['{"iban":"DE89370400440532013000"}', 'DE89370400440532013000'],
            'authorization' => ['{"authorization":"Basic dXNlcjpwYXNz"}', 'dXNlcjpwYXNz'],
            'case-insensitive key' => ['{"Secret_Key":"SK_UPPER_CASE"}', 'SK_UPPER_CASE'],
        ];
    }

    #[DataProvider('sensitiveValueProvider')]
    public function test_redacts_sensitive_json_values(string $input, string $secretValue): void
    {
        $result = SensitiveDataRedactor::redact($input);

        $this->assertStringNotContainsString($secretValue, $result);
        $this->assertStringContainsString('[redacted]', $result);
    }

    public function test_redacts_bearer_tokens(): void
    {
        $result = SensitiveDataRedactor::redact('Authorization header sent: Bearer eyJhbGciOiJIUzI1NiJ9.secret');

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret', $result);
        $this->assertStringContainsString('Bearer [redacted]', $result);
    }

    public function test_redacts_multiple_keys_in_same_payload(): void
    {
        $input = '{"secret_id":"sid_1","secret_key":"sk_2","access":"tok_3","refresh":"tok_4"}';

        $result = SensitiveDataRedactor::redact($input);

        $this->assertStringNotContainsString('sid_1', $result);
        $this->assertStringNotContainsString('sk_2', $result);
        $this->assertStringNotContainsString('tok_3', $result);
        $this->assertStringNotContainsString('tok_4', $result);
    }

    public function test_truncates_at_limit(): void
    {
        $input = str_repeat('a', 1000);

        $result = SensitiveDataRedactor::redact($input, 50);

        $this->assertLessThanOrEqual(53, strlen($result)); // 50 + '...'
        $this->assertStringStartsWith(str_repeat('a', 50), $result);
    }

    public function test_plain_text_without_sensitive_keys_passes_through_unchanged(): void
    {
        $input = 'Account not found for the requested id';

        $result = SensitiveDataRedactor::redact($input);

        $this->assertSame($input, $result);
    }

    public function test_non_sensitive_json_keys_are_left_untouched(): void
    {
        $input = '{"status":"error","detail":"Institution not found","code":404}';

        $result = SensitiveDataRedactor::redact($input);

        $this->assertSame($input, $result);
    }
}
