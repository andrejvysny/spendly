<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\RetrainMlModelJob;
use App\Models\MlPersonalizationSetting;
use App\Models\User;
use App\Services\MlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MlPersonalizationController extends Controller
{
    public function edit(Request $request, MlService $ml): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Inertia::render('settings/ml_engine', [
                'settings' => null,
                'metrics' => null,
            ]);
        }

        $settings = MlPersonalizationSetting::forUser($user->id);
        $metrics = $ml->getCategorizerMetrics($user->id);

        return Inertia::render('settings/ml_engine', [
            'settings' => $settings,
            'metrics' => $metrics['latest'] ?? null,
            'categoryNames' => $user->categories()->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('ml_engine.edit');
        }

        $validated = $request->validate([
            'auto_retrain' => ['nullable', 'boolean'],
            'retrain_threshold' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'model_version' => ['nullable', 'string', 'max:255'],
            'personalization_vector' => ['nullable', 'array'],
        ]);

        $settings = MlPersonalizationSetting::forUser($user->id);
        $settings->update(array_filter($validated, fn ($value) => $value !== null));

        return to_route('ml_engine.edit');
    }

    public function retrain(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
            ], 401);
        }

        RetrainMlModelJob::dispatch($user->id);

        return response()->json([
            'success' => true,
            'status' => 'queued',
            'message' => 'ML retraining job dispatched',
        ]);
    }
}
