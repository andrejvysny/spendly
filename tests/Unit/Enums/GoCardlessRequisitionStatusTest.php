<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\GoCardlessRequisitionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoCardlessRequisitionStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, GoCardlessRequisitionStatus}>
     */
    public static function gocardlessCodeProvider(): array
    {
        return [
            'CR (created)' => ['CR', GoCardlessRequisitionStatus::PENDING],
            'ID (id verification)' => ['ID', GoCardlessRequisitionStatus::PENDING],
            'GC (giving consent)' => ['GC', GoCardlessRequisitionStatus::PENDING],
            'UA (undergoing authentication)' => ['UA', GoCardlessRequisitionStatus::PENDING],
            'SA (selecting accounts)' => ['SA', GoCardlessRequisitionStatus::PENDING],
            'GA (granting access)' => ['GA', GoCardlessRequisitionStatus::PENDING],
            'LN (linked)' => ['LN', GoCardlessRequisitionStatus::LINKED],
            'EX (expired)' => ['EX', GoCardlessRequisitionStatus::EXPIRED],
            'SU (suspended)' => ['SU', GoCardlessRequisitionStatus::SUSPENDED],
            'RJ (rejected)' => ['RJ', GoCardlessRequisitionStatus::REJECTED],
            'ER (error)' => ['ER', GoCardlessRequisitionStatus::ERROR],
            'unknown code defaults to pending' => ['ZZ', GoCardlessRequisitionStatus::PENDING],
        ];
    }

    #[DataProvider('gocardlessCodeProvider')]
    public function test_from_gocardless_maps_raw_codes(string $code, GoCardlessRequisitionStatus $expected): void
    {
        $this->assertSame($expected, GoCardlessRequisitionStatus::fromGoCardless($code));
    }

    public function test_is_active_is_true_only_for_linked(): void
    {
        $this->assertTrue(GoCardlessRequisitionStatus::LINKED->isActive());

        foreach (GoCardlessRequisitionStatus::cases() as $status) {
            if ($status === GoCardlessRequisitionStatus::LINKED) {
                continue;
            }
            $this->assertFalse($status->isActive(), "{$status->value} should not be active");
        }
    }

    public function test_needs_reconnect_matches_expected_statuses(): void
    {
        $expected = [
            GoCardlessRequisitionStatus::EXPIRED,
            GoCardlessRequisitionStatus::SUSPENDED,
            GoCardlessRequisitionStatus::REJECTED,
            GoCardlessRequisitionStatus::ERROR,
        ];

        foreach (GoCardlessRequisitionStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, $expected, true),
                $status->needsReconnect(),
                "{$status->value} needsReconnect() mismatch"
            );
        }
    }

    public function test_label_returns_non_empty_string_for_every_case(): void
    {
        foreach (GoCardlessRequisitionStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
