<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
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

    public function store(CategoryRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $data = $validated;
        if ($data['parent_category_id'] === '0') {
            $data['parent_category_id'] = null;
        } elseif ($data['parent_category_id'] !== null) {
            $data['parent_category_id'] = (int) $data['parent_category_id'];
        }

        $category = Auth::user()->categories()->create($data);

        return redirect()->back()->with('success', 'Category created successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function update(CategoryRequest $request, Category $category): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validated();

        $data = $validated;
        if ($data['parent_category_id'] === '0') {
            $data['parent_category_id'] = null;
        } elseif ($data['parent_category_id'] !== null) {
            $data['parent_category_id'] = (int) $data['parent_category_id'];
        }

        // Additional validation for parent_category_id
        if ($data['parent_category_id'] !== null) {
            $request->validate([
                'parent_category_id' => [
                    'exists:categories,id',
                    Rule::notIn([$category->id]), // Prevent setting itself as parent
                ],
            ]);
        }

        $category->update($data);

        return redirect()->back()->with('success', 'Category updated successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Category $category): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $category);

        // Check if we need to handle transactions with this category
        if ($request->has('replacement_action')) {
            if ($request->replacement_action === 'replace' && $request->has('replacement_category_id')) {
                // Replace this category with another category in all transactions
                $category->transactions()->update([
                    'category_id' => $request->replacement_category_id,
                ]);
            } else {
                // Remove the category from all transactions
                $category->transactions()->update([
                    'category_id' => null,
                ]);
            }
        }

        $category->delete();

        return redirect()->back()->with('success', 'Category deleted successfully');
    }
}
