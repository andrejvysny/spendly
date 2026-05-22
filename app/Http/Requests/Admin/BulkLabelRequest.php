<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BulkLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['required_without:similar_group_key', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'exists:transactions,id'],
            'labels' => ['required', 'array', 'min:1'],
            'labels.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'labels.merchant_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'labels.counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'labels.needs_manual_review' => ['nullable', 'boolean'],
            'labels.is_transfer' => ['nullable', 'boolean'],
            'labels.is_duplicate' => ['nullable', 'boolean'],
            'labels.is_recurring' => ['nullable', 'boolean'],
            'labels.is_uncertain' => ['nullable', 'boolean'],
            'labels.accept_ml_category' => ['nullable', 'boolean'],
            'labels.accept_ml_counterparty' => ['nullable', 'boolean'],
            'labels.tags' => ['nullable', 'array'],
            'labels.tags.*' => ['integer', 'exists:tags,id'],
            'similar_group_key' => ['nullable', 'string', 'max:500'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.required_without' => 'transaction_ids is required when similar_group_key is not provided.',
        ];
    }
}
