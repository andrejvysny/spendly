<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Create a category on behalf of any user from the superadmin labeling UI.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var \App\Models\User $user */
        $user = $request->user();

        $category = Category::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'parent_category_id' => $validated['parent_category_id'] ?? null,
            'user_id' => isset($validated['target_user_id']) && is_numeric($validated['target_user_id'])
                ? (int) $validated['target_user_id']
                : (int) $user->id,
        ]);

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
            ],
        ], 201);
    }
}
