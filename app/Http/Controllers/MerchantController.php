<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MerchantController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $merchants = Auth::user()->merchants()->get();

        return Inertia::render('merchants/index', [
            'merchants' => $merchants,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:255'],
        ]);

        $merchant = Auth::user()->merchants()->create($validated);

        return redirect()->back()->with('success', 'Merchant created successfully');
    }

    public function update(Request $request, Merchant $merchant)
    {
        $this->authorize('update', $merchant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:255'],
        ]);

        $merchant->update($validated);

        return redirect()->back()->with('success', 'Merchant updated successfully');
    }

    public function destroy(Request $request, Merchant $merchant)
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
