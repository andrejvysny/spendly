<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\MlPersonalizationSetting;
use App\Models\Transaction;

class MlSuggestionService
{
    public function __construct(private readonly MlService $mlService) {}

    /**
     * Fetches ML suggestions and stores them in transaction metadata only.
     * No category/counterparty is auto-applied.
     *
     * @param  array<int>  $transactionIds
     */
    public function annotateTransactions(int $userId, array $transactionIds, string $origin = 'system'): int
    {
        $transactionIds = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn (int $id): bool => $id > 0)));
        if ($transactionIds === []) {
            return 0;
        }

        if (! $this->mlService->isAvailable()) {
            return 0;
        }

        $categoryPredictions = $this->mlService->categorize($userId, $transactionIds, count($transactionIds));
        $counterpartyPredictions = $this->mlService->detectCounterparties($userId, $transactionIds, count($transactionIds));

        $categoryByTx = [];
        $categoryIds = [];
        foreach ($categoryPredictions as $prediction) {
            $txId = (int) ($prediction['transaction_id'] ?? 0);
            if ($txId <= 0) {
                continue;
            }
            $categoryByTx[$txId] = $prediction;
            $predictedCategoryId = $prediction['predicted_category_id'] ?? null;
            if (is_numeric($predictedCategoryId)) {
                $categoryIds[] = (int) $predictedCategoryId;
            }
        }

        $counterpartyByTx = [];
        $counterpartyIds = [];
        foreach ($counterpartyPredictions as $prediction) {
            $txId = (int) ($prediction['transaction_id'] ?? 0);
            if ($txId <= 0) {
                continue;
            }
            $counterpartyByTx[$txId] = $prediction;
            $predictedCounterpartyId = $prediction['predicted_counterparty_id'] ?? null;
            if (is_numeric($predictedCounterpartyId)) {
                $counterpartyIds[] = (int) $predictedCounterpartyId;
            }
        }

        $categoryNames = Category::query()
            ->whereIn('id', array_values(array_unique($categoryIds)))
            ->where('user_id', $userId)
            ->pluck('name', 'id')
            ->all();

        $counterpartyNames = Counterparty::query()
            ->whereIn('id', array_values(array_unique($counterpartyIds)))
            ->where('user_id', $userId)
            ->pluck('name', 'id')
            ->all();

        $transactions = Transaction::query()
            ->whereIn('id', $transactionIds)
            ->whereHas('account', fn ($query) => $query->where('user_id', $userId))
            ->get();

        $modelVersion = MlPersonalizationSetting::forUser($userId)->model_version;
        $generatedAt = now()->toIso8601String();

        $updated = 0;

        foreach ($transactions as $transaction) {
            $txId = (int) $transaction->id;
            // Defensive: metadata can be a still-encoded JSON string on rows
            // written through a raw-insert path; (array)-casting a string
            // would wrap it and corrupt the column on save.
            $rawMetadata = $transaction->getAttribute('metadata');
            if (is_string($rawMetadata)) {
                $rawMetadata = json_decode($rawMetadata, true);
            }
            $metadata = is_array($rawMetadata) ? $rawMetadata : [];
            $mlMetadata = is_array($metadata['ml'] ?? null) ? $metadata['ml'] : [];

            $existingCategorySuggestion = is_array($mlMetadata['category_suggestion'] ?? null)
                ? $mlMetadata['category_suggestion']
                : null;
            $existingCounterpartySuggestion = is_array($mlMetadata['counterparty_suggestion'] ?? null)
                ? $mlMetadata['counterparty_suggestion']
                : null;

            $mlMetadata['category_suggestion'] = $this->resolveCategorySuggestion(
                $categoryByTx[$txId] ?? null,
                $existingCategorySuggestion,
                $categoryNames
            );
            $mlMetadata['counterparty_suggestion'] = $this->resolveCounterpartySuggestion(
                $counterpartyByTx[$txId] ?? null,
                $existingCounterpartySuggestion,
                $counterpartyNames
            );

            if ($mlMetadata['category_suggestion'] === null) {
                unset($mlMetadata['category_suggestion']);
            }
            if ($mlMetadata['counterparty_suggestion'] === null) {
                unset($mlMetadata['counterparty_suggestion']);
            }

            if (($mlMetadata['category_suggestion'] ?? null) === null && ($mlMetadata['counterparty_suggestion'] ?? null) === null) {
                unset($metadata['ml']);
            } else {
                $mlMetadata['suggested_at'] = $generatedAt;
                $mlMetadata['generated_at'] = $generatedAt;
                $mlMetadata['origin'] = $origin;
                $mlMetadata['model_version'] = $modelVersion;
                $metadata['ml'] = $mlMetadata;
            }

            Transaction::withoutRuleEvents(function () use ($transaction, $metadata): void {
                $transaction->metadata = $metadata;
                $transaction->save();
            });

            $updated++;
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>|null  $prediction
     * @param  array<string, mixed>|null  $existing
     * @param  array<int, string>  $categoryNames
     * @return array<string, mixed>|null
     */
    private function resolveCategorySuggestion(?array $prediction, ?array $existing, array $categoryNames): ?array
    {
        if ($prediction === null) {
            return $this->preserveAcceptedSuggestion($existing);
        }

        $predictedCategoryId = $prediction['predicted_category_id'] ?? null;
        if (! is_numeric($predictedCategoryId)) {
            return $this->preserveAcceptedSuggestion($existing);
        }

        $categoryId = (int) $predictedCategoryId;
        $name = $categoryNames[$categoryId] ?? null;
        if ($name === null) {
            return $this->preserveAcceptedSuggestion($existing);
        }

        return $this->buildSuggestion(
            id: $categoryId,
            name: $name,
            confidence: (float) ($prediction['confidence'] ?? 0.0),
            source: (string) ($prediction['method'] ?? 'ml'),
            existing: $existing,
        );
    }

    /**
     * @param  array<string, mixed>|null  $prediction
     * @param  array<string, mixed>|null  $existing
     * @param  array<int, string>  $counterpartyNames
     * @return array<string, mixed>|null
     */
    private function resolveCounterpartySuggestion(?array $prediction, ?array $existing, array $counterpartyNames): ?array
    {
        if ($prediction === null) {
            return $this->preserveAcceptedSuggestion($existing);
        }

        $predictedCounterpartyId = $prediction['predicted_counterparty_id'] ?? null;

        if (is_numeric($predictedCounterpartyId)) {
            $counterpartyId = (int) $predictedCounterpartyId;
            $name = $counterpartyNames[$counterpartyId] ?? null;
            if ($name === null) {
                return $this->preserveAcceptedSuggestion($existing);
            }

            return $this->buildSuggestion(
                id: $counterpartyId,
                name: $name,
                confidence: (float) ($prediction['confidence'] ?? 0.0),
                source: (string) ($prediction['method'] ?? 'ml'),
                existing: $existing,
            );
        }

        $suggestedName = $prediction['suggested_counterparty_name'] ?? null;
        if (! is_string($suggestedName) || trim($suggestedName) === '') {
            return $this->preserveAcceptedSuggestion($existing);
        }

        return $this->buildSuggestion(
            id: null,
            name: $suggestedName,
            confidence: (float) ($prediction['confidence'] ?? 0.0),
            source: (string) ($prediction['method'] ?? 'ml'),
            existing: $existing,
        );
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function preserveAcceptedSuggestion(?array $existing): ?array
    {
        if (! is_array($existing)) {
            return null;
        }

        return ($existing['status'] ?? null) === 'accepted' ? $existing : null;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function buildSuggestion(?int $id, string $name, float $confidence, string $source, ?array $existing): array
    {
        $suggestion = [
            'id' => $id,
            'name' => $name,
            'confidence' => $confidence,
            'source' => $source,
            'status' => 'pending',
        ];

        if (is_array($existing)
            && ($existing['status'] ?? null) === 'accepted'
            && ($existing['id'] ?? null) === $id
        ) {
            $suggestion['status'] = 'accepted';
            if (isset($existing['accepted_at'])) {
                $suggestion['accepted_at'] = $existing['accepted_at'];
            }
            if (isset($existing['accepted_by'])) {
                $suggestion['accepted_by'] = $existing['accepted_by'];
            }
        }

        return $suggestion;
    }
}
