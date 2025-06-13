<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\GoCardlessBankData;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Http;

class BankDataController extends Controller
{
    private GoCardlessBankData $client;
    public function __construct()
    {

        $this->client = new GoCardlessBankData(
            getenv("GOCARDLESS_SECRET_ID"),
            getenv("GOCARDLESS_SECRET_KEY"),
        );
    }

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/bank_data', [

        ]);
    }

    public function getRequisitions(): \Illuminate\Http\JsonResponse
    {

        if (Cache::has("data_gocardless_existing_requisitions")) {
            $existingRequisitions = Cache::get("data_gocardless_existing_requisitions");
            return response()->json($existingRequisitions);
        }

        $existingRequisitions = $this->client->getRequisitions();

        Cache::put("data_gocardless_existing_requisitions",$existingRequisitions, 3600); // Cache for 1 hour
        return response()->json($existingRequisitions);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        dd($request);
        $request->user()->fill($request->validated());
        $request->user()->save();

        return to_route('bank_data.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function deleteRequisition(string $id)
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->delete("https://bankaccountdata.gocardless.com/api/v2/requisitions/{$id}");

            if ($response->successful()) {

                // Clear the cached requisitions
                Cache::forget("data_gocardless_existing_requisitions");

                return response()->json(['message' => 'Requisition deleted successfully']);
            }

            return response()->json(['error' => 'Failed to delete requisition'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
