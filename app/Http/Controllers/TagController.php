<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Http\Requests\TagRequest;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TagController extends Controller
{
    public function index()
    {
        $tags = Auth::user()->tags()->get();

        return Inertia::render('tags/index', [
            'tags' => $tags,
        ]);
    }

    public function store(TagRequest $request)
    {
        $validated = $request->validated();

        $tag = Auth::user()->tags()->create($validated);

        return redirect()->back()->with('success', 'Tag created successfully');
    }

    public function update(TagRequest $request, Tag $tag)
    {
        $this->authorize('update', $tag);

        $validated = $request->validated();

        $tag->update($validated);

        return redirect()->back()->with('success', 'Tag updated successfully');
    }

    public function destroy(Tag $tag)
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return redirect()->back()->with('success', 'Tag deleted successfully');
    }
}
