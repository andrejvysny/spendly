<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\OwnedByUser;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
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
        // The new category is created for target_user_id (or the admin themselves);
        // any parent must belong to that same user to keep hierarchies single-tenant.
        $ownerId = $this->integer('target_user_id') ?: $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'parent_category_id' => ['nullable', 'integer', new OwnedByUser('categories', $ownerId)],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
