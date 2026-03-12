<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Budget;
use App\Models\RecurringGroup;
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
        $tagIds = $user ? $user->tags()->pluck('id')->toArray() : [];
        $counterpartyIds = $user ? $user->counterparties()->pluck('id')->toArray() : [];
        $recurringGroupIds = $user ? RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_CONFIRMED)
            ->pluck('id')->toArray() : [];
        $accountIds = $user ? $user->accounts()->pluck('id')->toArray() : [];

        return [
            'target_type' => ['required', 'string', Rule::in(Budget::ALL_TARGET_TYPES)],
            'category_id' => [
                'nullable',
                'integer',
                Rule::in($categoryIds),
                Rule::requiredIf(fn () => $this->input('target_type') === Budget::TARGET_CATEGORY),
                Rule::prohibitedIf(fn () => ! in_array($this->input('target_type'), [Budget::TARGET_CATEGORY, null], true)),
            ],
            'tag_id' => [
                'nullable',
                'integer',
                Rule::in($tagIds),
                Rule::requiredIf(fn () => $this->input('target_type') === Budget::TARGET_TAG),
                Rule::prohibitedIf(fn () => $this->input('target_type') !== Budget::TARGET_TAG && $this->input('target_type') !== null),
            ],
            'counterparty_id' => [
                'nullable',
                'integer',
                Rule::in($counterpartyIds),
                Rule::requiredIf(fn () => $this->input('target_type') === Budget::TARGET_COUNTERPARTY),
                Rule::prohibitedIf(fn () => $this->input('target_type') !== Budget::TARGET_COUNTERPARTY && $this->input('target_type') !== null),
            ],
            'recurring_group_id' => [
                'nullable',
                'integer',
                Rule::in($recurringGroupIds),
                Rule::requiredIf(fn () => $this->input('target_type') === Budget::TARGET_SUBSCRIPTION),
                Rule::prohibitedIf(fn () => $this->input('target_type') !== Budget::TARGET_SUBSCRIPTION && $this->input('target_type') !== null),
            ],
            'account_id' => [
                'nullable',
                'integer',
                Rule::in($accountIds),
                Rule::requiredIf(fn () => $this->input('target_type') === Budget::TARGET_ACCOUNT),
                Rule::prohibitedIf(fn () => $this->input('target_type') !== Budget::TARGET_ACCOUNT && $this->input('target_type') !== null),
            ],
            'include_transfers' => ['sometimes', 'boolean'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'mode' => ['sometimes', 'string', Rule::in([Budget::MODE_LIMIT, Budget::MODE_ENVELOPE])],
            'period_type' => ['required', 'string', Rule::in([Budget::PERIOD_MONTHLY, Budget::PERIOD_YEARLY])],
            'name' => ['nullable', 'string', 'max:255'],
            'rollover_enabled' => ['sometimes', 'boolean'],
            'rollover_cap' => ['nullable', 'numeric', 'min:0'],
            'include_subcategories' => ['sometimes', 'boolean'],
            'auto_create_next' => ['sometimes', 'boolean'],
            'overall_limit_mode' => ['nullable', 'string', Rule::in(['independent', 'sum', 'pool'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
