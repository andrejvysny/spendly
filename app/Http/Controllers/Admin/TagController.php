<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTagRequest;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    /**
     * Create a tag on behalf of any user from the superadmin labeling UI.
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tag = Tag::create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? null,
            'user_id' => $request->integer('target_user_id'),
        ]);

        return response()->json([
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ],
        ], 201);
    }
}
