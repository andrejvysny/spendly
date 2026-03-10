<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DuplicateTransactionService
{
    /**
     * Threshold for description similarity to consider it a match.
     */
    private const float DESCRIPTION_SIMILARITY_THRESHOLD = 0.8;

    /**
     * Threshold for sending a row to manual review as a probable duplicate.
     */
    private const float PROBABLE_DUPLICATE_THRESHOLD = 0.75;

    /**
     * Mapping of canonical field names to possible aliases.
     *
     * @var array<string, array<int, string>>
     */
    private array $fieldMapping;

    /**
     * Cached exact duplicate counts for the current request/import run.
     *
     * @var array<string, int>
     */
    private array $exactCountCache = [];

    public function __construct(array $fieldMapping = [])
    {
        $this->fieldMapping = $fieldMapping;
    }

    /**
     * Normalize a transaction record using field aliases.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeRecord(array $input): array
    {
        $normalized = [
            'account_id' => $input['account_id'] ?? null,
            'description' => $input['description'] ?? null,
            'partner' => $input['partner'] ?? null,
            'booked_date' => $input['booked_date'] ?? null,
            'processed_date' => $input['processed_date'] ?? null,
            'amount' => $input['amount'] ?? null,
            'currency' => $input['currency'] ?? null,
            'type' => $input['type'] ?? null,
            'reference_id' => $input['reference_id'] ?? null,
            'target_iban' => $input['target_iban'] ?? null,
            'source_iban' => $input['source_iban'] ?? null,
        ];

        foreach (['description', 'partner', 'booked_date', 'reference_id'] as $canonical) {
            if (isset($input[$canonical]) && $input[$canonical] !== null && $input[$canonical] !== '') {
                $normalized[$canonical] = $input[$canonical];

                continue;
            }

            foreach ($this->fieldMapping[$canonical] ?? [] as $alias) {
                if (! empty($input[$alias])) {
                    $normalized[$canonical] = $input[$alias];

                    break;
                }
            }
        }

        if ($normalized['booked_date'] === null || $normalized['booked_date'] === '') {
            $normalized['booked_date'] = $normalized['processed_date'];
        }

        return $normalized;
    }

    /**
     * Build a stable fingerprint from normalized data.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function buildFingerprint(array $normalized): string
    {
        return Transaction::generateFingerprint([
            'account_id' => $normalized['account_id'] ?? null,
            'amount' => $normalized['amount'] ?? null,
            'currency' => $normalized['currency'] ?? null,
            'booked_date' => $normalized['booked_date'] ?? $normalized['processed_date'] ?? null,
            'description' => $normalized['description'] ?? null,
            'target_iban' => $normalized['target_iban'] ?? null,
            'source_iban' => $normalized['source_iban'] ?? null,
            'partner' => $normalized['partner'] ?? null,
            'type' => $normalized['type'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function getStableFingerprint(array $input): string
    {
        return $this->buildFingerprint($this->normalizeRecord($input));
    }

    /**
     * Compute a weighted duplicate score against an existing transaction.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function computeScore(array $normalized, Transaction $existing): float
    {
        $dateMatch = false;

        try {
            $inputDate = Carbon::parse((string) ($normalized['booked_date'] ?? $normalized['processed_date']))->startOfDay();
            $existingDate = $existing->booked_date->startOfDay();
            $dateMatch = $inputDate->equalTo($existingDate);
        } catch (\Exception) {
            // Ignore parsing errors and treat as not matching.
        }

        if (! $dateMatch) {
            return 0.0;
        }

        $amountMatch = (float) $existing->amount === (float) ($normalized['amount'] ?? 0);
        $referenceExact = isset($normalized['reference_id']) && $normalized['reference_id'] !== null
            && $existing->transaction_id === $normalized['reference_id'];

        $descriptionScore = TextSimilarity::similarity(
            (string) $existing->description,
            (string) ($normalized['description'] ?? $normalized['partner'] ?? '')
        );
        $descriptionScore = $descriptionScore >= self::DESCRIPTION_SIMILARITY_THRESHOLD ? 1.0 : 0.0;

        return ($amountMatch ? 0.40 : 0.0)
            + ($descriptionScore * 0.35)
            + ($referenceExact ? 0.25 : 0.0);
    }

    /**
     * Fetch same-account candidate transactions within +/- 1 day.
     *
     * @param  array<string, mixed>  $normalized
     * @return Collection<int, Transaction>
     */
    public function fetchCandidates(array $normalized, int $userId): Collection
    {
        $dateValue = $normalized['booked_date'] ?? $normalized['processed_date'];

        try {
            $date = Carbon::parse((string) $dateValue);
        } catch (\Exception) {
            return new Collection;
        }

        $query = Transaction::query()
            ->whereBetween('booked_date', [$date->copy()->subDay()->startOfDay(), $date->copy()->addDay()->endOfDay()]);

        $accountId = $normalized['account_id'] ?? null;
        if ($accountId !== null && $accountId !== '') {
            $query->where('account_id', (int) $accountId);
        } else {
            $query->whereHas('account', static function ($accountQuery) use ($userId) {
                $accountQuery->where('user_id', $userId);
            });
        }

        return $query->orderBy('booked_date')->get();
    }

    /**
     * Determine if a record has at least one exact duplicate in storage.
     *
     * @param  array<string, mixed>  $input
     */
    public function isDuplicate(array $input, int $userId): bool
    {
        return $this->getExactDuplicateCount($input, $userId) > 0;
    }

    /**
     * Get how many exact duplicate rows already exist for this account.
     *
     * @param  array<string, mixed>  $input
     */
    public function getExactDuplicateCount(array $input, int $userId): int
    {
        $accountId = $input['account_id'] ?? null;
        if ($accountId === null || $accountId === '') {
            return 0;
        }

        $fingerprint = $input['fingerprint'] ?? null;
        if ($fingerprint === null || $fingerprint === '') {
            $fingerprint = $this->getStableFingerprint($input);
        }

        $cacheKey = (int) $accountId.'|'.$fingerprint;

        if (! array_key_exists($cacheKey, $this->exactCountCache)) {
            $this->exactCountCache[$cacheKey] = Transaction::query()
                ->where('account_id', (int) $accountId)
                ->where('fingerprint', $fingerprint)
                ->whereNotNull('fingerprint')
                ->count();
        }

        return $this->exactCountCache[$cacheKey];
    }

    /**
     * Find the strongest probable duplicate candidate, if any.
     *
     * @param  array<string, mixed>  $input
     * @return array{transaction: Transaction, score: float}|null
     */
    public function findProbableDuplicate(array $input, int $userId): ?array
    {
        $normalized = $this->normalizeRecord($input);
        $stableFingerprint = $input['fingerprint'] ?? $this->buildFingerprint($normalized);
        $bestCandidate = null;
        $bestScore = 0.0;

        foreach ($this->fetchCandidates($normalized, $userId) as $candidate) {
            if ($candidate->fingerprint !== null && $candidate->fingerprint === $stableFingerprint) {
                continue;
            }

            $score = $this->computeScore($normalized, $candidate);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        if ($bestCandidate === null || $bestScore < self::PROBABLE_DUPLICATE_THRESHOLD) {
            return null;
        }

        return [
            'transaction' => $bestCandidate,
            'score' => $bestScore,
        ];
    }

    /**
     * Decide how an import row should be treated.
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *     decision: 'allow'|'skip'|'review',
     *     fingerprint: string,
     *     exact_duplicate_count: int,
     *     probable_duplicate: ?Transaction,
     *     probable_duplicate_score: ?float
     * }
     */
    public function classifyImportRow(array $input, int $userId, int $occurrenceInImport): array
    {
        $fingerprint = $input['fingerprint'] ?? $this->getStableFingerprint($input);
        $input['fingerprint'] = $fingerprint;

        $exactDuplicateCount = $this->getExactDuplicateCount($input, $userId);
        if ($occurrenceInImport <= $exactDuplicateCount) {
            return [
                'decision' => 'skip',
                'fingerprint' => $fingerprint,
                'exact_duplicate_count' => $exactDuplicateCount,
                'probable_duplicate' => null,
                'probable_duplicate_score' => null,
            ];
        }

        $probableDuplicate = $this->findProbableDuplicate($input, $userId);

        return [
            'decision' => $probableDuplicate !== null ? 'review' : 'allow',
            'fingerprint' => $fingerprint,
            'exact_duplicate_count' => $exactDuplicateCount,
            'probable_duplicate' => $probableDuplicate['transaction'] ?? null,
            'probable_duplicate_score' => $probableDuplicate['score'] ?? null,
        ];
    }

    /**
     * Backwards compatibility wrapper for the old API.
     *
     * @param  array<string, mixed>  $data
     * @return array{duplicate: bool, level: int, identifier: string, fields: array<string, mixed>}
     */
    public function check(array $data, int $accountId): array
    {
        $account = Account::find($accountId);
        if (! $account) {
            throw new \InvalidArgumentException("Account with ID {$accountId} not found");
        }

        $normalized = $this->normalizeRecord(array_merge($data, ['account_id' => $accountId]));
        $identifier = $this->buildFingerprint($normalized);
        $duplicate = $this->isDuplicate(array_merge($data, ['account_id' => $accountId, 'fingerprint' => $identifier]), (int) $account->user_id);

        return [
            'duplicate' => $duplicate,
            'level' => $duplicate ? 5 : 0,
            'identifier' => $identifier,
            'fields' => $normalized,
        ];
    }
}
