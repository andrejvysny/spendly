<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Transaction;
use App\Rules\OwnedByUser;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Taxonomy references must belong to the labeled transaction's owner,
        // not merely exist — otherwise admin edits create cross-tenant links.
        $ownerId = $this->transactionOwnerId();

        return [
            'category_id' => ['nullable', 'integer', new OwnedByUser('categories', $ownerId)],
            'counterparty_id' => ['nullable', 'integer', new OwnedByUser('counterparties', $ownerId)],
            'type' => ['nullable', 'string', 'in:PAYMENT,CREDIT,TRANSFER,CARD_PAYMENT,DIRECT_DEBIT'],
            'is_transfer' => ['nullable', 'boolean'],
            'needs_manual_review' => ['nullable', 'boolean'],
            'is_duplicate' => ['nullable', 'boolean'],
            'is_recurring' => ['nullable', 'boolean'],
            'is_uncertain' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', new OwnedByUser('tags', $ownerId)],
            'accept_ml_category' => ['nullable', 'boolean'],
            'accept_ml_counterparty' => ['nullable', 'boolean'],
        ];
    }

    private function transactionOwnerId(): ?int
    {
        $transaction = $this->route('transaction');

        if (! $transaction instanceof Transaction) {
            return null;
        }

        $userId = $transaction->account?->user_id;

        return $userId !== null ? (int) $userId : null;
    }
}
