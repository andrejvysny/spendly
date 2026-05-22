<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TransactionLabelingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:unlabeled,all,labeled,flagged,duplicates'],
            'type' => ['nullable', 'string', 'in:debit,transfer,credit,all'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'merchant_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ];
    }

    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        return array_merge([
            'status' => 'unlabeled',
            'type' => 'all',
            'page' => 1,
            'per_page' => 50,
        ], $validated);
    }
}
