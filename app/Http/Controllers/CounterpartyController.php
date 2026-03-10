<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CounterpartyRequest;
use App\Models\Counterparty;
use App\Policies\Ability;
use App\Repositories\CounterpartyRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CounterpartyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CounterpartyRepository $counterpartyRepository,
    ) {}

    public function index(): Response
    {
        $counterparties = $this->counterpartyRepository->findByUser($this->getAuthUserId());

        return Inertia::render('counterparties/index', [
            'counterparties' => $counterparties,
        ]);
    }

    public function store(CounterpartyRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['user_id'] = $this->getAuthUserId();

        $this->counterpartyRepository->create($validated);

        return redirect()->back()->with('success', 'Counterparty created successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function update(CounterpartyRequest $request, Counterparty $counterparty): RedirectResponse
    {
        $this->authorize(Ability::update, $counterparty);

        $validated = $request->validated();

        $counterparty->update($validated);

        return redirect()->back()->with('success', 'Counterparty updated successfully');
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Counterparty $counterparty): RedirectResponse
    {
        $this->authorize('delete', $counterparty);

        if ($request->has('replacement_action')) {
            if ($request->replacement_action === 'replace' && $request->has('replacement_counterparty_id')) {
                $counterparty->transactions()->update([
                    'counterparty_id' => $request->replacement_counterparty_id,
                ]);
            } else {
                $counterparty->transactions()->update([
                    'counterparty_id' => null,
                ]);
            }
        }

        $counterparty->delete();

        return redirect()->back()->with('success', 'Counterparty deleted successfully');
    }
}
