<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLabelingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];
        $mlData = $metadata['ml'] ?? [];

        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'booked_date' => $this->booked_date,
            'processed_date' => $this->processed_date,
            'description' => $this->description,
            'partner' => $this->partner,
            'type' => $this->type,
            'source_iban' => $this->source_iban,
            'target_iban' => $this->target_iban,
            'metadata' => $metadata,
            'import_data' => $this->import_data,
            'account' => $this->whenLoaded('account', function () {
                return [
                    'id' => $this->account->id,
                    'name' => $this->account->name,
                    'color' => $this->account->color ?? '#3b82f6',
                    'iban' => $this->account->iban,
                    'user' => $this->whenLoaded('account.user', function () {
                        return [
                            'id' => $this->account->user->id,
                            'name' => $this->account->user->name,
                            'email' => $this->account->user->email,
                        ];
                    }),
                ];
            }),
            'category' => $this->whenLoaded('category', function () {
                return $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'color' => $this->category->color,
                    'icon' => $this->category->icon,
                ] : null;
            }),
            'counterparty' => $this->whenLoaded('counterparty', function () {
                return $this->counterparty ? [
                    'id' => $this->counterparty->id,
                    'name' => $this->counterparty->name,
                ] : null;
            }),
            'tags' => $this->whenLoaded('tags', function () {
                return $this->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ]);
            }),
            'flags' => [
                'is_recurring' => $this->recurring_group_id !== null,
                'is_duplicate' => $this->duplicate_identifier !== null,
                'is_uncertain' => $this->needs_manual_review ?? false,
                'is_transfer' => $this->type === 'TRANSFER',
            ],
            'label_state' => [
                'is_labeled' => $this->isLabeled(),
                'is_ml_suggested' => $this->hasMlSuggestions(),
            ],
            'ml' => [
                'category' => $this->formatMlSuggestion($mlData['category_suggestion'] ?? null),
                'counterparty' => $this->formatMlSuggestion($mlData['counterparty_suggestion'] ?? null),
                'generated_at' => $mlData['generated_at'] ?? null,
                'origin' => $mlData['origin'] ?? null,
                'model_version' => $mlData['model_version'] ?? null,
            ],
            'similar_group' => $this->getSimilarGroup(),
            'raw' => [
                'source_iban' => $this->source_iban,
                'target_iban' => $this->target_iban,
                'import_data' => $this->import_data,
                'metadata' => $metadata,
            ],
        ];
    }

    private function isLabeled(): bool
    {
        return $this->category_id !== null || $this->counterparty_id !== null;
    }

    private function hasMlSuggestions(): bool
    {
        $metadata = $this->metadata ?? [];
        $mlData = $metadata['ml'] ?? [];
        return ! empty($mlData['category_suggestion']) || ! empty($mlData['counterparty_suggestion']);
    }

    private function formatMlSuggestion(?array $suggestion): ?array
    {
        if (empty($suggestion)) {
            return null;
        }
        return [
            'id' => $suggestion['id'] ?? null,
            'name' => $suggestion['name'] ?? null,
            'confidence' => $suggestion['confidence'] ?? 0,
            'source' => $suggestion['source'] ?? 'ml',
            'status' => $suggestion['status'] ?? 'pending',
            'accepted_at' => $suggestion['accepted_at'] ?? null,
        ];
    }

    private function getSimilarGroup(): ?array
    {
        if (empty($this->fingerprint)) {
            return null;
        }
        return [
            'key' => $this->fingerprint,
            'count' => $this->similar_group_count ?? 1,
        ];
    }
}
