<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\GoCardlessCredentialSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateGoCardlessCredentialsRequest;
use App\Models\User;
use App\Services\GoCardless\CredentialsResolver;
use App\Support\AppUrlDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoCardlessCredentialController extends Controller
{
    public function __construct(
        private readonly CredentialsResolver $credentialsResolver,
        private readonly AppUrlDiagnostics $appUrlDiagnostics,
    ) {}

    /**
     * Displays the GoCardless bank data settings page for the authenticated user.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Inertia::render('settings/bank_data', $this->unconfiguredProps());
        }

        $source = $this->credentialsResolver->sourceFor($user);
        $hasUserOverride = $this->credentialsResolver->hasUserOverride($user);

        return Inertia::render('settings/bank_data', [
            'has_gocardless_credentials' => $source !== null,
            'gocardless_credential_source' => $source instanceof GoCardlessCredentialSource ? $source->value : 'none',
            'gocardless_has_instance_credentials' => $this->credentialsResolver->hasInstanceCredentials(),
            'gocardless_has_user_override' => $hasUserOverride,
            // Only ever the user's own secret id. The instance pair is shared by every account on
            // the installation, so even a masked tail of it must not be handed out.
            'gocardless_secret_id_masked' => $this->maskUserSecretId($user),
            'gocardless_use_mock' => config('services.gocardless.use_mock'),
            'app_url_warning' => $this->appUrlDiagnostics->warning(),
        ]);
    }

    /**
     * Updates the authenticated user's personal GoCardless secret pair.
     *
     * Half-filled submissions are rejected by the form request, so by this point the pair is
     * either complete or entirely blank (the "leave blank to keep current" case).
     */
    public function update(UpdateGoCardlessCredentialsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }

        $secretId = is_string($validated['gocardless_secret_id'] ?? null) ? trim($validated['gocardless_secret_id']) : '';
        $secretKey = is_string($validated['gocardless_secret_key'] ?? null) ? trim($validated['gocardless_secret_key']) : '';

        if ($secretId === '' || $secretKey === '') {
            return to_route('bank_data.edit');
        }

        $user->gocardless_secret_id = $secretId;
        $user->gocardless_secret_key = $secretKey;
        $this->discardTokens($user);
        $user->save();

        return to_route('bank_data.edit')->with('success', 'Personal credentials saved.');
    }

    /**
     * Removes the authenticated user's personal credentials and every token minted from them.
     *
     * Bank sync is not necessarily switched off by this: where the installation carries its own
     * credentials the user falls back to those, so the response says which state they landed in.
     */
    public function purgeGoCardlessCredentials(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }

        $user->gocardless_secret_id = null;
        $user->gocardless_secret_key = null;
        $this->discardTokens($user);
        $user->save();

        $message = $this->credentialsResolver->hasInstanceCredentials()
            ? 'Personal credentials removed. Bank sync now uses the credentials configured by the server administrator.'
            : 'Personal credentials removed. Bank sync is no longer configured.';

        return to_route('bank_data.edit')->with('success', $message);
    }

    /**
     * Tokens are only valid for the secret pair that minted them, so any change to that pair
     * takes them with it rather than leaving TokenManager to discover the mismatch later.
     */
    private function discardTokens(User $user): void
    {
        $user->gocardless_access_token = null;
        $user->gocardless_refresh_token = null;
        $user->gocardless_refresh_token_expires_at = null;
        $user->gocardless_access_token_expires_at = null;
        $user->gocardless_token_secret_hash = null;
    }

    private function maskUserSecretId(User $user): ?string
    {
        $secretId = $user->gocardless_secret_id;

        if (! is_string($secretId) || $secretId === '') {
            return null;
        }

        return str_repeat('*', max(0, strlen($secretId) - 4)).substr($secretId, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private function unconfiguredProps(): array
    {
        return [
            'has_gocardless_credentials' => false,
            'gocardless_credential_source' => 'none',
            'gocardless_has_instance_credentials' => $this->credentialsResolver->hasInstanceCredentials(),
            'gocardless_has_user_override' => false,
            'gocardless_secret_id_masked' => null,
            'gocardless_use_mock' => config('services.gocardless.use_mock'),
            'app_url_warning' => $this->appUrlDiagnostics->warning(),
        ];
    }
}
