<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionFingerprint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DuplicateTransactionService
{
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

        $amount = abs((float) ($normalized['amount'] ?? 0));
        $desc = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($normalized['description'] ?? '')));
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
     * @param  array<string, mixed>  $normalized
     */
    public function computeScore(array $normalized, Transaction $existing): float
    {
        $dateMatch = false;
        try {
            $inputDate = Carbon::parse((string) ($normalized['booked_date'] ?? $normalized['processed_date']))->toDateString();
            $dateMatch = $existing->booked_date->toDateString() === $inputDate;
        } catch (\Exception $e) {
            // ignore parsing errors
        }

        $amountMatch = abs((float) $existing->amount) === abs((float) ($normalized['amount'] ?? 0));
        $referenceExact = isset($normalized['reference_id']) && $existing->transaction_id === $normalized['reference_id'];

        $descriptionScore = TextSimilarity::similarity((string) $existing->description, (string) ($normalized['description'] ?? ''));
        $descriptionScore = $descriptionScore >= 0.90 ? 1.0 : 0.0;

        return ($dateMatch ? 0.50 : 0.0)
            + ($amountMatch ? 0.30 : 0.0)
            + ($descriptionScore * 0.15)
            + ($referenceExact ? 0.05 : 0.0);
    }

    /**
     * Determine if a record is a duplicate for the given user.
     *
     * @param  array<string, mixed>  $input
     */
    public function isDuplicate(array $input, int $userId): bool
    {
        $normalized = $this->normalizeRecord($input);
        $fingerprint = $this->buildFingerprint($normalized);

        $exists = TransactionFingerprint::where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->exists();
        if ($exists) {
            return true;
        }

        $candidates = $this->fetchCandidates($normalized, $userId);
        foreach ($candidates as $candidate) {
            if ($this->computeScore($normalized, $candidate) >= 0.80) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backwards compatibility wrapper for old check method.
     *
     * @param  array<string, mixed>  $data
     * @param  int  $accountId
     * @return array{duplicate: bool, level: int, identifier: string, fields: array<string, mixed>}
     */
    public function check(array $data, int $accountId): array
    {
        $normalized = $this->normalizeRecord($data);
        $identifier = $this->buildFingerprint($normalized);
        $duplicate = $this->isDuplicate($data, $accountId);

        return [
            'duplicate' => $duplicate,
            'level' => $duplicate ? 5 : 0,
            'identifier' => $identifier,
            'fields' => $normalized,
        ];
    }
}
