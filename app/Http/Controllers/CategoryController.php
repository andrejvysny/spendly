<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Rules\OwnedByUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $categories = Auth::user()->categories()
            ->with('parentCategory')
            ->get();
        $categories = $categories->map(function ($category) {
            $category->parent_category_id = $category->parentCategory ? $category->parentCategory->id : null;

            return $category;
        });

        return Inertia::render('categories/index', [
            'categories' => $categories,
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        // parent_category_id is normalized + ownership-validated in CategoryRequest.
        Auth::user()->categories()->create($request->validated());

        return redirect()->back()->with('success', 'Category created successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $data = $request->validated();

        $newParentId = $data['parent_category_id'] ?? null;
        if ($newParentId !== null) {
            $this->assertNoCycle($category, (int) $newParentId);
        }

        $category->update($data);

        return redirect()->back()->with('success', 'Category updated successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        if ($request->input('replacement_action') === 'replace' && $request->filled('replacement_category_id')) {
            // Replacement must be one of the caller's own categories (no cross-tenant retarget).
            $request->validate([
                'replacement_category_id' => [
                    'integer',
                    Rule::notIn([$category->id]),
                    new OwnedByUser('categories'),
                ],
            ]);
            $category->transactions()->update([
                'category_id' => (int) $request->input('replacement_category_id'),
            ]);
        } elseif ($request->has('replacement_action')) {
            $category->transactions()->update(['category_id' => null]);
        }

        $category->delete();

        return redirect()->back()->with('success', 'Category deleted successfully');
    }

    /**
     * Guard against hierarchy cycles: the proposed parent must not be the category
     * itself or any of its descendants. Walks ancestors of the proposed parent.
     *
     * @throws ValidationException
     */
    private function assertNoCycle(Category $category, int $newParentId): void
    {
        $ancestorId = $newParentId;
        $guard = 0;
        while ($ancestorId !== null && $guard++ < 1000) {
            if ((int) $ancestorId === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_category_id' => 'A category cannot be set as a child of itself or one of its descendants.',
                ]);
            }
            $ancestorId = Category::where('id', $ancestorId)->value('parent_category_id');
        }
    }
}
