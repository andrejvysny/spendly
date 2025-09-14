<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MerchantRequest;
use App\Models\Merchant;
use App\Policies\Ability;
use App\Repositories\MerchantRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MerchantRepository $merchantRepository,
    ) {}

    public function index(): Response
    {
        $merchants = $this->merchantRepository->findByUserId($this->getAuthUserId());

        return Inertia::render('merchants/index', [
            'merchants' => $merchants,
        ]);
    }

    public function store(MerchantRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Create the merchant associated with the authenticated user
        $this->merchantRepository->create($validated);

        return redirect()->back()->with('success', 'Merchant created successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function update(MerchantRequest $request, Merchant $merchant): RedirectResponse
    {
        $this->authorize(Ability::update, $merchant);

        $validated = $request->validated();

        $merchant->update($validated);

        return redirect()->back()->with('success', 'Merchant updated successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Merchant $merchant): RedirectResponse
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
