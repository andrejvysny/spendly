<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CounterpartyRequest;
use App\Models\Counterparty;
use App\Policies\Ability;
use App\Repositories\CounterpartyRepository;
use App\Rules\OwnedByUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        if ($request->input('replacement_action') === 'replace' && $request->filled('replacement_counterparty_id')) {
            // Replacement must be one of the caller's own counterparties.
            $request->validate([
                'replacement_counterparty_id' => [
                    'integer',
                    Rule::notIn([$counterparty->id]),
                    new OwnedByUser('counterparties', $this->getAuthUserId()),
                ],
            ]);
            $counterparty->transactions()->update([
                'counterparty_id' => (int) $request->input('replacement_counterparty_id'),
            ]);
        } elseif ($request->has('replacement_action')) {
            $counterparty->transactions()->update(['counterparty_id' => null]);
        }

        $counterparty->delete();

        return redirect()->back()->with('success', 'Counterparty deleted successfully');
    }
}
