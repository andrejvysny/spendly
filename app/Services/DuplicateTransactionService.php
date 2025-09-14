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
     * Threshold for description similarity to consider it a match
     */
    private const DESCRIPTION_SIMILARITY_THRESHOLD = 0.8;

    /**
     * Mapping of canonical field names to possible aliases.
     *
     * @var array<string, array<int, string>>
     */
    private array $fieldMapping;

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
            'description' => null,
            'booked_date' => null,
            'processed_date' => $input['processed_date'] ?? null,
            'amount' => $input['amount'] ?? null,
            'reference_id' => null,
        ];

        foreach (['description', 'booked_date', 'reference_id'] as $canonical) {
            if (isset($input[$canonical]) && $input[$canonical] !== null) {
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

        return $normalized;
    }

    /**
     * Build a SHA-256 fingerprint from normalized data.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function buildFingerprint(array $normalized): string
    {
        $date = $normalized['booked_date'] ?? $normalized['processed_date'];
        try {
            $date = Carbon::parse((string) $date)->toDateString();
        } catch (\Exception $e) {
            $date = '';
        }

        // Format amount to 2 decimal places to avoid float precision issues
        $amountRaw = abs((float) ($normalized['amount'] ?? 0));
        $amount = number_format($amountRaw, 2, '.', '');

        // Normalize description: first collapse whitespace, then remove special chars
        $desc = (string) ($normalized['description'] ?? '');
        $desc = preg_replace('/\s+/u', ' ', trim($desc)); // collapse whitespace
        $desc = strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)); // remove special chars

        $reference = (string) ($normalized['reference_id'] ?? '');

        return hash('sha256', $date.'|'.$amount.'|'.$desc.'|'.$reference);
    }

    /**
     * Fetch candidate transactions within ±1 day for the same user.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function fetchCandidates(array $normalized, int $userId): Collection
    {
        $date = $normalized['booked_date'] ?? $normalized['processed_date'];
        try {
            $date = Carbon::parse((string) $date);
        } catch (\Exception $e) {
            return collect();
        }

        $start = $date->copy()->subDay()->startOfDay();
        $end = $date->copy()->addDay()->endOfDay();

        return Transaction::whereHas('account', static function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->whereBetween('booked_date', [$start, $end])
            ->get();
    }

    /**
     * Compute a weighted duplicate score against an existing transaction.
     *
     * Scoring policy:
     * - Date MUST match exactly - different dates mean NOT a duplicate
     * - If dates match, then score based on:
     *   - Amount match: 0.40 (exact amount including sign)
     *   - Description similarity: 0.35 (≥80% similarity threshold)
     *   - Reference ID match: 0.25 (exact match)
     *
     * A score ≥ 0.80 indicates a duplicate (only possible when dates match).
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
        } catch (\Exception $e) {
            // ignore parsing errors
        }

        // If dates don't match, it's definitively NOT a duplicate
        if (! $dateMatch) {
            return 0.0;
        }

        $amountMatch = (float) $existing->amount === (float) ($normalized['amount'] ?? 0);
        $referenceExact = isset($normalized['reference_id']) && $existing->transaction_id === $normalized['reference_id'];

        $descriptionScore = TextSimilarity::similarity((string) $existing->description, (string) ($normalized['description'] ?? ''));
        $descriptionScore = $descriptionScore >= self::DESCRIPTION_SIMILARITY_THRESHOLD ? 1.0 : 0.0;

        // Only calculate score if dates match
        return ($amountMatch ? 0.40 : 0.0)
            + ($descriptionScore * 0.35)
            + ($referenceExact ? 0.25 : 0.0);
    }

    /**
     * Determine if a record is a duplicate for the given user.
     *
     * @param  array<string, mixed>  $input
     */
    public function isDuplicate(array $input, int $userId): bool
    {
        return false; // Temporarily disable duplicate checks

        // TODO: Re-enable duplicate checks when TransactionFingerprint model is implemented
        $normalized = $this->normalizeRecord($input);
        $fingerprint = $this->buildFingerprint($normalized);

        //        $exists = TransactionFingerprint::where('user_id', $userId)
        //            ->where('fingerprint', $fingerprint)
        //            ->exists();
        //        if ($exists) {
        //            return true;
        //        }

        $candidates = $this->fetchCandidates($normalized, $userId);
        foreach ($candidates as $candidate) {
            if ($this->computeScore($normalized, $candidate) >= 0.80) {
                return true;
            }
        }

        // Save the fingerprint for future duplicate checks
        //        TransactionFingerprint::updateOrCreate(
        //            [
        //                'user_id' => $userId,
        //                'fingerprint' => $fingerprint,
        //            ],
        //            [
        //                'created_at' => now(),
        //            ]
        //        );

        return false;
    }

    /**
     * Backwards compatibility wrapper for old check method.
     *
     * @param  array<string, mixed>  $data
     * @return array{duplicate: bool, level: int, identifier: string, fields: array<string, mixed>}
     */
    public function check(array $data, int $accountId): array
    {
        // Get user_id from account
        $account = Account::find($accountId);
        if (! $account) {
            throw new \InvalidArgumentException("Account with ID {$accountId} not found");
        }

        $userId = $account->user_id;

        $normalized = $this->normalizeRecord($data);
        $identifier = $this->buildFingerprint($normalized);
        $duplicate = $this->isDuplicate($data, $userId);

        return [
            'duplicate' => $duplicate,
            'level' => $duplicate ? 5 : 0,
            'identifier' => $identifier,
            'fields' => $normalized,
        ];
    }
}
