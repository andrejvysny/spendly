<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $categoryIds = $user ? $user->categories()->pluck('id')->toArray() : [];

        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::in($categoryIds),
            ],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'period_type' => ['sometimes', 'required', 'string', Rule::in([Budget::PERIOD_MONTHLY, Budget::PERIOD_YEARLY])],
            'year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'month' => [
                'nullable',
                'integer',
                'min:0',
                'max:12',
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
