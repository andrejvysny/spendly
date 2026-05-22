<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'type' => ['nullable', 'string', 'in:PAYMENT,CREDIT,TRANSFER,CARD_PAYMENT,DIRECT_DEBIT'],
            'needs_manual_review' => ['nullable', 'boolean'],
            'is_duplicate' => ['nullable', 'boolean'],
            'is_recurring' => ['nullable', 'boolean'],
            'is_uncertain' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
            'accept_ml_category' => ['nullable', 'boolean'],
            'accept_ml_counterparty' => ['nullable', 'boolean'],
        ];
    }
}
