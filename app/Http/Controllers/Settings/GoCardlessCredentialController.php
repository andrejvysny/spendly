<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoCardlessCredentialController extends Controller
{
    /**
     * Displays the GoCardless bank data settings page for the authenticated user.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/bank_data', [
            'has_gocardless_credentials' => $user instanceof User && $user->gocardless_secret_id !== null,
            'gocardless_secret_id_masked' => $user instanceof User && $user->gocardless_secret_id
                ? str_repeat('*', max(0, strlen($user->gocardless_secret_id) - 4)) . substr($user->gocardless_secret_id, -4)
                : null,
            'gocardless_use_mock' => config('services.gocardless.use_mock'),
        ]);
    }

    /**
     * Updates the authenticated user's GoCardless secret ID and key.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gocardless_secret_id' => ['nullable', 'string'],
            'gocardless_secret_key' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }
        if (! empty($validated['gocardless_secret_id'])) {
            $user->gocardless_secret_id = $validated['gocardless_secret_id'];
        }
        if (! empty($validated['gocardless_secret_key'])) {
            $user->gocardless_secret_key = $validated['gocardless_secret_key'];
        }
        $user->save();

        return to_route('bank_data.edit');
    }

    /**
     * Removes all stored GoCardless credentials and tokens from the authenticated user.
     */
    public function purgeGoCardlessCredentials(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }
        $user->gocardless_secret_id = null;
        $user->gocardless_secret_key = null;
        $user->gocardless_access_token = null;
        $user->gocardless_refresh_token = null;
        $user->gocardless_refresh_token_expires_at = null;
        $user->gocardless_access_token_expires_at = null;
        $user->save();

        return to_route('bank_data.edit')->with('success', 'GoCardless credentials purged successfully.');
    }
}
