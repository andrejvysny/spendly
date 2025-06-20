<?php

namespace App\Http\Controllers;

use App\Http\Requests\MerchantRequest;
use App\Models\Merchant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MerchantController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        $merchants = Auth::user()->merchants()->get();

        return Inertia::render('merchants/index', [
            'merchants' => $merchants,
        ]);
    }

    public function store(MerchantRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $merchant = Auth::user()->merchants()->create($validated);

        return redirect()->back()->with('success', 'Merchant created successfully');
    }

    public function update(MerchantRequest $request, Merchant $merchant): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $merchant);

        $validated = $request->validated();

        $merchant->update($validated);

        return redirect()->back()->with('success', 'Merchant updated successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Merchant $merchant): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $merchant);

        // Check if we need to handle transactions with this merchant
        if ($request->has('replacement_action')) {
            if ($request->replacement_action === 'replace' && $request->has('replacement_merchant_id')) {
                // Replace this merchant with another merchant in all transactions
                $merchant->transactions()->update([
                    'merchant_id' => $request->replacement_merchant_id,
                ]);
            } else {
                // Remove the merchant from all transactions
                $merchant->transactions()->update([
                    'merchant_id' => null,
                ]);
            }
        }

        $merchant->delete();

        return redirect()->back()->with('success', 'Merchant deleted successfully');
    }
}
