<?php

namespace App\Http\Requests;

use App\Rules\OwnedByUser;
use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the parent id: the UI sends '0' / '' to mean "no parent".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('parent_category_id')) {
            $parent = $this->input('parent_category_id');
            $this->merge([
                'parent_category_id' => ($parent === '0' || $parent === '' || $parent === 0 || $parent === null)
                    ? null
                    : (int) $parent,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:255'],
            // Parent must belong to the authenticated user (no cross-tenant parenting).
            'parent_category_id' => ['nullable', 'integer', new OwnedByUser('categories', $this->user()?->id)],
        ];
    }
}
