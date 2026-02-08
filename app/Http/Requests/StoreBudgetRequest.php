<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
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
                'required',
                'integer',
                Rule::in($categoryIds),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'period_type' => ['required', 'string', Rule::in([Budget::PERIOD_MONTHLY, Budget::PERIOD_YEARLY])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => [
                'required_if:period_type,' . Budget::PERIOD_MONTHLY,
                'nullable',
                'integer',
                'min:1',
                'max:12',
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
