<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCounterpartyRequest;
use App\Models\Counterparty;
use Illuminate\Http\JsonResponse;

class CounterpartyController extends Controller
{
    /**
     * Create a counterparty on behalf of any user from the superadmin labeling UI.
     */
    public function store(StoreCounterpartyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $counterparty = Counterparty::create([
            'name' => $validated['name'],
            'type' => $validated['type'] ?? 'merchant',
            'user_id' => $request->integer('target_user_id'),
        ]);

        return response()->json([
            'counterparty' => [
                'id' => $counterparty->id,
                'name' => $counterparty->name,
                'type' => $counterparty->type,
            ],
        ], 201);
    }
}
