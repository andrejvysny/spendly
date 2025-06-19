<?php

namespace App\Http\Controllers;

use App\Http\Requests\TagRequest;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $tags = Auth::user()->tags()->get();

        return Inertia::render('tags/index', [
            'tags' => $tags,
        ]);
    }

    public function store(TagRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $tag = Auth::user()->tags()->create($validated);

        return redirect()->back()->with('success', 'Tag created successfully');
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $this->authorize('update', $tag);

        $validated = $request->validated();

        $tag->update($validated);

        return redirect()->back()->with('success', 'Tag updated successfully');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return redirect()->back()->with('success', 'Tag deleted successfully');
    }
}
