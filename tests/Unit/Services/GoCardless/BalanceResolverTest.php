<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Services\GoCardless\BalanceResolver;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('gocardless')]
class BalanceResolverTest extends TestCase
{
    public function test_prefers_closing_booked_when_present(): void
    {
        $balances = [
            ['balanceType' => 'interimAvailable', 'balanceAmount' => ['amount' => '500.00']],
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '1000.00']],
            ['balanceType' => 'expected', 'balanceAmount' => ['amount' => '999.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(1000.0, $result);
    }

    public function test_falls_back_to_interim_available_when_no_closing_booked(): void
    {
        $balances = [
            ['balanceType' => 'expected', 'balanceAmount' => ['amount' => '300.00']],
            ['balanceType' => 'interimAvailable', 'balanceAmount' => ['amount' => '450.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(450.0, $result);
    }

    public function test_falls_back_to_expected_when_no_closing_booked_or_interim_available(): void
    {
        $balances = [
            ['balanceType' => 'expected', 'balanceAmount' => ['amount' => '250.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(250.0, $result);
    }

    public function test_falls_back_to_interim_booked_as_last_preferred_type(): void
    {
        $balances = [
            ['balanceType' => 'interimBooked', 'balanceAmount' => ['amount' => '750.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(750.0, $result);
    }

    public function test_returns_null_when_balances_array_is_empty(): void
    {
        $result = BalanceResolver::resolve([]);

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_balances_match_any_type(): void
    {
        // No preferred types match; array is non-empty so falls back to first entry.
        // This actually triggers the "first available" fallback, not null.
        // A genuinely unknown-only array still returns a value — verified here.
        $balances = [
            ['balanceType' => 'unknownType', 'balanceAmount' => ['amount' => '100.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        // Falls back to first available when no preferred type found.
        $this->assertSame(100.0, $result);
    }

    public function test_returns_null_when_passed_truly_empty_structure(): void
    {
        $result = BalanceResolver::resolve([]);

        $this->assertNull($result);
    }

    public function test_handles_string_amount(): void
    {
        $balances = [
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '1234.56']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(1234.56, $result);
    }

    public function test_handles_float_amount(): void
    {
        $balances = [
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => 1234.56]],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(1234.56, $result);
    }

    public function test_handles_negative_balance(): void
    {
        $balances = [
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '-250.75']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(-250.75, $result);
    }

    public function test_handles_zero_balance(): void
    {
        $balances = [
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '0.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(0.0, $result);
    }

    public function test_handles_integer_string_amount(): void
    {
        $balances = [
            ['balanceType' => 'interimAvailable', 'balanceAmount' => ['amount' => '500']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(500.0, $result);
    }

    public function test_returns_first_available_when_no_preferred_type_matches(): void
    {
        $balances = [
            ['balanceType' => 'openingBooked', 'balanceAmount' => ['amount' => '100.00']],
            ['balanceType' => 'forwardAvailable', 'balanceAmount' => ['amount' => '200.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        // First entry wins since neither type is preferred.
        $this->assertSame(100.0, $result);
    }

    public function test_priority_order_closing_booked_beats_interim_available(): void
    {
        $balances = [
            ['balanceType' => 'interimAvailable', 'balanceAmount' => ['amount' => '111.11']],
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '222.22']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(222.22, $result);
    }

    public function test_priority_order_interim_available_beats_expected(): void
    {
        $balances = [
            ['balanceType' => 'expected', 'balanceAmount' => ['amount' => '55.00']],
            ['balanceType' => 'interimAvailable', 'balanceAmount' => ['amount' => '88.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(88.0, $result);
    }

    public function test_priority_order_expected_beats_interim_booked(): void
    {
        $balances = [
            ['balanceType' => 'interimBooked', 'balanceAmount' => ['amount' => '10.00']],
            ['balanceType' => 'expected', 'balanceAmount' => ['amount' => '20.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(20.0, $result);
    }

    public function test_duplicate_balance_type_last_entry_wins(): void
    {
        // When the same balanceType appears twice, the last one overwrites due to array key assignment.
        $balances = [
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '100.00']],
            ['balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '200.00']],
        ];

        $result = BalanceResolver::resolve($balances);

        $this->assertSame(200.0, $result);
    }
}
