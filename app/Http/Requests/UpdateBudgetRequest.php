<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Budget;
use App\Models\RecurringGroup;
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
        // Any foreign key supplied on update must reference an entity the caller
        // owns (prevents retargeting a budget at another user's category/tag/etc.).
        $categoryIds = $user ? $user->categories()->pluck('id')->toArray() : [];
        $tagIds = $user ? $user->tags()->pluck('id')->toArray() : [];
        $counterpartyIds = $user ? $user->counterparties()->pluck('id')->toArray() : [];
        $recurringGroupIds = $user ? RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_CONFIRMED)
            ->pluck('id')->toArray() : [];
        $accountIds = $user ? $user->accounts()->pluck('id')->toArray() : [];

        return [
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in($categoryIds),
            ],
            'tag_id' => ['sometimes', 'nullable', 'integer', Rule::in($tagIds)],
            'counterparty_id' => ['sometimes', 'nullable', 'integer', Rule::in($counterpartyIds)],
            'recurring_group_id' => ['sometimes', 'nullable', 'integer', Rule::in($recurringGroupIds)],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::in($accountIds)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'mode' => ['sometimes', 'string', Rule::in([Budget::MODE_LIMIT, Budget::MODE_ENVELOPE])],
            'period_type' => ['sometimes', 'required', 'string', Rule::in([Budget::PERIOD_MONTHLY, Budget::PERIOD_YEARLY])],
            'name' => ['nullable', 'string', 'max:255'],
            'rollover_enabled' => ['sometimes', 'boolean'],
            'rollover_cap' => ['nullable', 'numeric', 'min:0'],
            'include_subcategories' => ['sometimes', 'boolean'],
            'include_transfers' => ['sometimes', 'boolean'],
            'auto_create_next' => ['sometimes', 'boolean'],
            'overall_limit_mode' => ['nullable', 'string', Rule::in(['independent', 'sum', 'pool'])],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
