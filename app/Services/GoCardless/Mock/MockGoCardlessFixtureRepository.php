<?php

declare(strict_types=1);

namespace App\Services\GoCardless\Mock;

use Illuminate\Support\Facades\Log;

class MockGoCardlessFixtureRepository
{
    private const string DETAILS_PATTERN = '_details.json';

    private const string DETAILS_SUFFIX_PATTERN = '_details_';

    /** @var array<string, array<int, string>> institution id -> list of account IDs */
    private array $institutionAccounts = [];

    /** @var array<string, array{institution: string, prefix: string, suffix: string}> account id -> file resolution */
    private array $accountResolution = [];

    private bool $scanned = false;

    public function __construct(
        private readonly string $basePath
    ) {}

    /**
     * Get list of institutions with id and optional display name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstitutions(string $countryCode): array
    {
        $this->scanOnce();

        $out = [];
        foreach (array_keys($this->institutionAccounts) as $institutionId) {
            $out[] = [
                'id' => $institutionId,
                'name' => $this->institutionDisplayName($institutionId),
                'bic' => $this->institutionBic($institutionId),
                'transaction_total_days' => '90',
                'max_access_valid_for_days' => '90',
                'countries' => [$countryCode],
                'logo' => '',
            ];
        }

        return $out;
    }

    /**
     * Get account IDs for an institution (from fixture discovery).
     *
     * @return array<int, string>
     */
    public function getAccountIdsForInstitution(string $institutionId): array
    {
        $this->scanOnce();

        return $this->institutionAccounts[$institutionId] ?? [];
    }

    /**
     * Get account details payload from fixture. Returns null if file missing or invalid.
     *
     * @return array{account: array<string, mixed>}|null
     */
    public function getAccountDetailsPayload(string $accountId): ?array
    {
        $resolved = $this->resolveAccountId($accountId);
        if ($resolved === null) {
            return null;
        }

        $path = $this->basePath . '/' . $resolved['institution'] . '/' . $resolved['prefix'] . '_details' . $resolved['suffix'] . '.json';

        $data = $this->readJsonFile($path);
        if ($data === null || ! isset($data['account']) || ! is_array($data['account'])) {
            return null;
        }

        return $data;
    }

    /**
     * Get balances payload from fixture. Normalizes to include closingBooked when missing.
     * Returns null if file missing or invalid.
     *
     * @return array{balances: array<int, array<string, mixed>>}|null
     */
    public function getBalancesPayload(string $accountId): ?array
    {
        $resolved = $this->resolveAccountId($accountId);
        if ($resolved === null) {
            return null;
        }

        $path = $this->basePath . '/' . $resolved['institution'] . '/' . $resolved['prefix'] . '_balances' . $resolved['suffix'] . '.json';

        $data = $this->readJsonFile($path);
        if ($data === null || ! isset($data['balances']) || ! is_array($data['balances'])) {
            return null;
        }

        return $this->normalizeBalances($data);
    }

    /**
     * Get transactions payload from fixture. Optionally filters by date range.
     * Returns null if file missing or invalid.
     *
     * @return array{transactions: array{booked: array<int, mixed>, pending: array<int, mixed>}}|null
     */
    public function getTransactionsPayload(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): ?array
    {
        $resolved = $this->resolveAccountId($accountId);
        if ($resolved === null) {
            return null;
        }

        $path = $this->basePath . '/' . $resolved['institution'] . '/' . $resolved['prefix'] . '_transactions_booked' . $resolved['suffix'] . '.json';

        $data = $this->readJsonFile($path);
        if ($data === null) {
            return null;
        }

        $booked = $data['transactions']['booked'] ?? [];
        if (! is_array($booked)) {
            $booked = [];
        }

        if ($dateFrom !== null || $dateTo !== null) {
            $booked = $this->filterTransactionsByDate($booked, $dateFrom, $dateTo);
        }

        $pendingPath = $this->basePath . '/' . $resolved['institution'] . '/' . $resolved['prefix'] . '_transactions_pending' . $resolved['suffix'] . '.json';
        $pendingData = $this->readJsonFile($pendingPath);
        $pending = $pendingData['transactions']['pending'] ?? [];
        if (! is_array($pending)) {
            $pending = [];
        }

        return [
            'transactions' => [
                'booked' => $booked,
                'pending' => $pending,
            ],
        ];
    }

    /**
     * Resolve accountId to (institution, prefix, suffix) for file lookup.
     *
     * @return array{institution: string, prefix: string, suffix: string}|null
     */
    public function resolveAccountId(string $accountId): ?array
    {
        $this->scanOnce();

        return $this->accountResolution[$accountId] ?? null;
    }

    /**
     * Whether any fixture data was discovered (so we can show fixture institutions).
     */
    public function hasFixtureData(): bool
    {
        $this->scanOnce();

        return $this->institutionAccounts !== [];
    }

    private function scanOnce(): void
    {
        if ($this->scanned) {
            return;
        }
        $this->scanned = true;

        if (! is_dir($this->basePath)) {
            return;
        }

        $dirs = scandir($this->basePath);
        if ($dirs === false) {
            return;
        }

        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($this->basePath . '/' . $entry)) {
                continue;
            }

            $institutionId = $entry;
            $accountIds = $this->discoverAccountIdsInInstitution($institutionId);

            if ($accountIds !== []) {
                $this->institutionAccounts[$institutionId] = $accountIds;
                foreach ($accountIds as $accId) {
                    $this->accountResolution[$accId] = $this->accountIdToPrefixSuffix($accId, $institutionId);
                }
            }
        }
    }

    /**
     * Discover account IDs from details filenames in an institution folder.
     *
     * @return array<int, string>
     */
    private function discoverAccountIdsInInstitution(string $institutionId): array
    {
        $dir = $this->basePath . '/' . $institutionId;
        if (! is_dir($dir)) {
            return [];
        }

        $accountIds = [];
        $files = glob($dir . '/*_details*.json') ?: [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (str_ends_with($basename, self::DETAILS_PATTERN)) {
                $prefix = substr($basename, 0, -strlen(self::DETAILS_PATTERN));
                $accountIds[] = $prefix;
                continue;
            }
            if (str_contains($basename, self::DETAILS_SUFFIX_PATTERN)) {
                $rest = substr($basename, 0, -5);
                $idx = strrpos($rest, self::DETAILS_SUFFIX_PATTERN);
                if ($idx !== false) {
                    $prefix = substr($rest, 0, $idx);
                    $suffixPart = substr($rest, $idx + strlen(self::DETAILS_SUFFIX_PATTERN));
                    $accountIds[] = $prefix . '_' . $suffixPart;
                }
            }
        }

        return array_values(array_unique($accountIds));
    }

    /**
     * @return array{institution: string, prefix: string, suffix: string}
     */
    private function accountIdToPrefixSuffix(string $accountId, string $institutionId): array
    {
        $suffix = '';
        $prefix = $accountId;

        if (str_ends_with($accountId, '_USD')) {
            $prefix = substr($accountId, 0, -4);
            $suffix = '_USD';
        }

        return [
            'institution' => $institutionId,
            'prefix' => $prefix,
            'suffix' => $suffix,
        ];
    }

    private function readJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            Log::warning('GoCardless mock: invalid JSON fixture', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Ensure balances array has a closingBooked entry (copy from first available if missing).
     *
     * @param  array{balances: array<int, array<string, mixed>>}  $data
     * @return array{balances: array<int, array<string, mixed>>}
     */
    private function normalizeBalances(array $data): array
    {
        $balances = $data['balances'];
        $hasClosingBooked = false;
        $firstBalance = null;

        foreach ($balances as $b) {
            if (($b['balanceType'] ?? '') === 'closingBooked') {
                $hasClosingBooked = true;
                break;
            }
            if ($firstBalance === null) {
                $firstBalance = $b;
            }
        }

        if (! $hasClosingBooked && $firstBalance !== null) {
            $closing = $firstBalance;
            $closing['balanceType'] = 'closingBooked';
            $balances[] = $closing;
        }

        return ['balances' => $balances];
    }

    /**
     * Filter booked transactions by bookingDate within [dateFrom, dateTo].
     *
     * @param  array<int, array<string, mixed>>  $booked
     * @return array<int, array<string, mixed>>
     */
    private function filterTransactionsByDate(array $booked, ?string $dateFrom, ?string $dateTo): array
    {
        return array_values(array_filter($booked, function (array $tx) use ($dateFrom, $dateTo): bool {
            $bookingDate = $tx['bookingDate'] ?? $tx['valueDate'] ?? null;
            if ($bookingDate === null) {
                return true;
            }
            if ($dateFrom !== null && $bookingDate < $dateFrom) {
                return false;
            }
            if ($dateTo !== null && $bookingDate > $dateTo) {
                return false;
            }

            return true;
        }));
    }

    private function institutionDisplayName(string $institutionId): string
    {
        return match (strtoupper($institutionId)) {
            'REVOLUT' => 'Revolut',
            'SLSP' => 'Slovenská sporiteľňa',
            default => $institutionId,
        };
    }

    private function institutionBic(string $institutionId): string
    {
        return match (strtoupper($institutionId)) {
            'REVOLUT' => 'REVOGB21',
            'SLSP' => 'GIBASKBX',
            default => 'MOCK' . strtoupper(substr($institutionId, 0, 4)),
        };
    }
}
