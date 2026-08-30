<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\GoCardlessRequisitionRepositoryInterface;
use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\GoCardlessApiException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateGoCardlessRequisitionRequest;
use App\Http\Resources\GoCardless\RequisitionResource;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class GoCardlessRequisitionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Query parameters appended by the bank (or the mock client) *after* the signed
     * callback URL was generated. They are not covered by the signature and must be
     * excluded from verification, otherwise every real callback fails.
     *
     * @var list<string>
     */
    private const array UNSIGNED_CALLBACK_PARAMS = [
        'ref',
        'error',
        'details',
        'error_description',
        'mock',
        'requisition_id',
    ];

    private const int DEFAULT_ACCESS_VALID_FOR_DAYS = 90;

    private const string GENERIC_CALLBACK_ERROR = 'This bank connection link is invalid or has expired. Please start the connection again.';

    private GoCardlessService $gocardlessService;

    private AccountRepositoryInterface $accountRepository;

    private GoCardlessRequisitionRepositoryInterface $requisitionRepository;

    public function __construct(
        GoCardlessService $gocardlessService,
        AccountRepositoryInterface $accountRepository,
        GoCardlessRequisitionRepositoryInterface $requisitionRepository
    ) {
        $this->gocardlessService = $gocardlessService;
        $this->accountRepository = $accountRepository;
        $this->requisitionRepository = $requisitionRepository;
    }

    /**
     * Build a structured, secret-safe log context for a GoCardless failure.
     *
     * Deliberately excludes a stack trace: frames on this path include the arguments to
     * Http::post()/Http::get() calls, which can contain requisition or refresh secrets.
     *
     * @return array<string, mixed>
     */
    private function exceptionContext(\Throwable $e, mixed $userId = null): array
    {
        return [
            'user_id' => is_int($userId) || is_string($userId) ? $userId : null,
            'exception' => $e::class,
            'message' => SensitiveDataRedactor::redact($e->getMessage()),
            'correlation_id' => $e instanceof GoCardlessApiException ? $e->correlationId : null,
            'at' => $e->getFile().':'.$e->getLine(),
        ];
    }

    /**
     * Retrieves a list of financial institutions available in the specified country from the GoCardless API.
     */
    public function getInstitutions(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            $country = $request->input('country');
            $country = is_string($country) ? $country : '';
            Log::info('Fetching institutions from GoCardless API', ['country' => $country]);
            $institutions = $this->gocardlessService->getInstitutions($country, $user);

            return response()->json($institutions);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to fetch institutions', [
                'country' => $request->country,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch institutions'], 500);
        }
    }

    /**
     * Lists the user's GoCardless requisitions from the local DB.
     *
     * The DB is the source of truth: it survives cache eviction, records the local
     * lifecycle (revoked/replaced/cancelled) that GoCardless has no notion of, and
     * avoids a remote round-trip on every page load. `?refresh=1` reconciles against
     * the GoCardless API first; a failing refresh degrades to the stored rows.
     */
    public function getRequisitions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            $userId = (int) $user->id;

            if ($request->boolean('refresh')) {
                $this->refreshRequisitionsFromRemote($user, $userId);
            }

            $rows = $this->requisitionRepository->findByUser($userId)
                ->reject(fn (GoCardlessRequisition $row): bool => in_array(
                    $row->getAttribute('status'),
                    [GoCardlessRequisitionStatus::REVOKED, GoCardlessRequisitionStatus::REPLACED],
                    true
                ))
                ->sortByDesc(fn (GoCardlessRequisition $row): int => $this->rowId($row))
                ->values();

            $results = [];
            foreach ($rows as $row) {
                $results[] = $this->presentRequisition($row, $user);
            }

            return response()->json([
                'count' => count($results),
                'results' => RequisitionResource::collection($results)->resolve(),
            ]);
        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to fetch requisitions', $this->exceptionContext($e, $request->user()?->getKey()));

            return response()->json(['error' => 'Failed to fetch requisitions'], 500);
        }
    }

    /**
     * Mirror the remote requisition list into the local rows. Never fatal: the stored
     * rows are still served when GoCardless is unreachable or rate limiting us.
     */
    private function refreshRequisitionsFromRemote(User $user, int $userId): void
    {
        try {
            $remote = $this->gocardlessService->getRequisitionsList($user);

            foreach ($remote['results'] as $row) {
                if (is_string($row['id'] ?? null) && $row['id'] !== '') {
                    $this->requisitionRepository->upsertFromRemote($userId, $row);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GoCardless requisition refresh failed, serving stored rows', $this->exceptionContext($e, $userId));
        }
    }

    /**
     * Project a stored requisition into the array shape RequisitionResource whitelists.
     *
     * @return array<string, mixed>
     */
    private function presentRequisition(GoCardlessRequisition $requisition, User $user): array
    {
        $accountIds = $this->accountIds($requisition);
        $accounts = $accountIds === []
            ? []
            : $this->gocardlessService->getEnrichedAccountsForRequisition($accountIds, $user);

        $status = $requisition->getAttribute('status');
        $status = $status instanceof GoCardlessRequisitionStatus ? $status : null;

        $createdAt = $requisition->getAttribute('created_at');
        $accessValidUntil = $requisition->getAttribute('access_valid_until');

        return [
            'id' => $this->stringAttr($requisition, 'requisition_id') ?? '',
            'row_id' => $this->rowId($requisition),
            'created' => $createdAt instanceof CarbonInterface ? $createdAt->toIso8601String() : null,
            'status' => $this->stringAttr($requisition, 'gocardless_status') ?? '',
            'institution_id' => $this->stringAttr($requisition, 'institution_id'),
            'agreement' => $this->stringAttr($requisition, 'agreement_id'),
            'user_language' => 'EN',
            'accounts' => $accounts,
            'link' => $this->stringAttr($requisition, 'link'),
            'local_status' => $status?->value,
            'status_label' => $status?->label(),
            'access_valid_until' => $accessValidUntil instanceof CarbonInterface ? $accessValidUntil->toIso8601String() : null,
            'days_until_expiry' => $requisition->daysUntilExpiry(),
            'needs_reconnect' => $requisition->needsReconnect(),
        ];
    }

    /**
     * Creates a new GoCardless requisition and returns the bank authentication link.
     *
     * The callback URL is a Laravel temporary signed route keyed by a random reference;
     * the reference is the only thing the bank echoes back, and the matching DB row —
     * not the session or a cache entry — carries the user and return destination.
     */
    public function createRequisition(CreateGoCardlessRequisitionRequest $request): JsonResponse
    {
        $institutionId = $request->institutionId();
        $returnTo = $request->returnTo();

        Log::info('Creating GoCardless requisition', ['institution_id' => $institutionId]);

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $link = $this->startRequisitionFlow($user, $institutionId, $returnTo)['link'];

            return response()->json(['link' => $link]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('GoCardless import error', $this->exceptionContext($e, $request->user()?->getKey()));

            return response()->json(['error' => 'Failed to import account'], 500);
        }
    }

    /**
     * Start a bank authorization: mint the signed callback, create the EUA + requisition at
     * GoCardless, and persist the local row that the callback will later be matched against.
     *
     * Shared by the first connection and by reconnect so both produce an identical row — the
     * callback handler has no way to tell them apart, and must not need one.
     *
     * @return array{link: string, row: GoCardlessRequisition}
     */
    private function startRequisitionFlow(User $user, string $institutionId, ?string $returnTo): array
    {
        $reference = (string) Str::uuid();
        $redirectUrl = URL::temporarySignedRoute(
            'bank_data.gocardless.callback',
            now()->addHours(2),
            ['reference' => $reference]
        );

        $created = $this->gocardlessService->createRequisition($institutionId, $redirectUrl, $user, $reference);
        $requisition = $created['requisition'];
        $agreement = $created['agreement'];

        $requisitionId = is_string($requisition['id'] ?? null) ? $requisition['id'] : '';
        $link = is_string($requisition['link'] ?? null) ? $requisition['link'] : '';
        $goCardlessStatus = is_string($requisition['status'] ?? null) ? $requisition['status'] : null;

        $row = $this->requisitionRepository->createForUser((int) $user->id, [
            'requisition_id' => $requisitionId,
            'reference' => $reference,
            'institution_id' => $institutionId,
            'agreement_id' => is_string($agreement['id'] ?? null) ? $agreement['id'] : null,
            'max_historical_days' => $this->intOrNull($agreement['max_historical_days'] ?? null) ?? self::DEFAULT_ACCESS_VALID_FOR_DAYS,
            'access_valid_for_days' => $this->intOrNull($agreement['access_valid_for_days'] ?? null) ?? self::DEFAULT_ACCESS_VALID_FOR_DAYS,
            'status' => GoCardlessRequisitionStatus::PENDING,
            'gocardless_status' => $goCardlessStatus,
            'link' => $link,
            'return_to' => $returnTo,
            'accounts' => null,
        ]);

        Log::info('Requisition created', ['requisition_id' => $requisitionId]);

        return ['link' => $link, 'row' => $row];
    }

    /**
     * Re-authorize an existing bank connection whose 90-day consent lapsed.
     *
     * Starts a brand new requisition for the same institution and leaves the old row untouched:
     * until the user actually completes the bank flow, the old row is still the best record of
     * what they connected. The callback supersedes it (markReplacedForInstitution) only on success,
     * so an abandoned reconnect changes nothing.
     */
    public function reconnect(Request $request, GoCardlessRequisition $requisitionRow): JsonResponse
    {
        $this->authorize('update', $requisitionRow);

        $institutionId = $this->stringAttr($requisitionRow, 'institution_id');
        if ($institutionId === null) {
            return response()->json(['error' => 'This connection has no institution to reconnect to.'], 422);
        }

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $link = $this->startRequisitionFlow(
                $user,
                $institutionId,
                $this->stringAttr($requisitionRow, 'return_to')
            )['link'];

            Log::info('Started GoCardless reconnect', [
                'previous_requisition_row_id' => $this->rowId($requisitionRow),
                'institution_id' => $institutionId,
            ]);

            return response()->json(['link' => $link]);
        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('GoCardless reconnect failed', $this->exceptionContext($e, $request->user()?->getKey()));

            return response()->json(['error' => 'Failed to start reconnect'], 500);
        }
    }

    /**
     * Handles the callback from GoCardless after the user authenticated with their bank.
     *
     * Unauthenticated by design — the bank redirects the browser here and the session
     * cookie may be absent (cross-site redirect). Trust comes from the URL signature
     * plus the stored requisition row, never from auth() or the session.
     */
    public function handleRequisitionCallback(Request $request): RedirectResponse
    {
        if (! URL::hasValidSignature($request, true, self::UNSIGNED_CALLBACK_PARAMS)) {
            Log::warning('Rejected GoCardless callback with invalid or expired signature', [
                'ip' => $request->ip(),
            ]);

            return redirect()->route('bank_data.edit')->with('error', self::GENERIC_CALLBACK_ERROR);
        }

        $reference = $request->query('reference');
        $reference = is_string($reference) ? $reference : '';
        $requisition = $reference === '' ? null : $this->requisitionRepository->findByReference($reference);

        if ($requisition === null) {
            Log::warning('GoCardless callback carried a signed reference with no matching requisition');

            return redirect()->route('bank_data.edit')->with('error', self::GENERIC_CALLBACK_ERROR);
        }

        $route = $this->stringAttr($requisition, 'return_to') === 'accounts'
            ? 'accounts.index'
            : 'bank_data.edit';

        // Single-use: a GoCardless auth link is terminal once followed, whether it
        // succeeded or errored. Claiming before any outbound call keeps a replayed
        // redirect from re-hitting the API.
        if (! $this->requisitionRepository->claimCallback($requisition)) {
            Log::info('Ignoring replayed GoCardless callback', ['requisition_row_id' => $this->rowId($requisition)]);

            return $this->callbackRedirect($route, 'success', 'This bank connection was already processed.');
        }

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            return $this->handleCallbackError($requisition, $request, $route, $error);
        }

        $user = $requisition->user;
        if (! $user instanceof User) {
            Log::error('GoCardless requisition has no owning user', ['requisition_row_id' => $this->rowId($requisition)]);

            return redirect()->route('bank_data.edit')->with('error', self::GENERIC_CALLBACK_ERROR);
        }

        $requisitionId = $this->stringAttr($requisition, 'requisition_id') ?? '';

        try {
            $accountIds = $this->gocardlessService->getAccounts($requisitionId, $user);
        } catch (\Exception $e) {
            // This response renders in a browser the bank redirected here, so the reason
            // is recorded on the row (and in the log) rather than flashed to the page.
            Log::error('GoCardless callback error', $this->exceptionContext($e, $user->getKey()));
            $requisition->update(['last_error' => Str::limit(SensitiveDataRedactor::redact($e->getMessage()), 250)]);

            return $this->callbackRedirect($route, 'error', 'Failed to get accounts.');
        }

        $accountIds = array_values(array_filter($accountIds, 'is_string'));

        $requisition->update(array_merge(
            [
                'accounts' => $accountIds,
                'status' => GoCardlessRequisitionStatus::LINKED,
                'gocardless_status' => 'LN',
                'last_error' => null,
            ],
            $this->resolveAccessWindow($requisition, $user)
        ));

        $this->relinkAccounts($requisition, $accountIds, $user);

        $this->requisitionRepository->markReplacedForInstitution(
            (int) $user->id,
            $this->stringAttr($requisition, 'institution_id') ?? '',
            $this->rowId($requisition)
        );

        $count = count($accountIds);
        $message = $count > 0
            ? "Bank connected. {$count} account(s) are available—import them from the list below."
            : 'Bank connected. Import accounts from the list below when ready.';

        return $this->callbackRedirect($route, 'success', $message);
    }

    /**
     * Record a GoCardless-reported failure on the requisition and bounce the user back.
     */
    private function handleCallbackError(
        GoCardlessRequisition $requisition,
        Request $request,
        string $route,
        string $error
    ): RedirectResponse {
        $details = $request->query('details') ?? $request->query('error_description');
        $details = is_string($details) ? $details : '';

        if ($error === 'UserCancelledSession') {
            Log::info('User cancelled the GoCardless session', ['requisition_row_id' => $this->rowId($requisition)]);
            $this->requisitionRepository->updateStatus($requisition, GoCardlessRequisitionStatus::CANCELLED);

            return $this->callbackRedirect($route, 'error', 'Bank connection cancelled.');
        }

        Log::warning('GoCardless callback reported an error', [
            'requisition_row_id' => $this->rowId($requisition),
            'error' => $error,
        ]);

        $requisition->update(['last_error' => Str::limit(SensitiveDataRedactor::redact($error.($details !== '' ? ': '.$details : '')), 250)]);
        $this->requisitionRepository->updateStatus($requisition, GoCardlessRequisitionStatus::ERROR);

        $message = $error === 'ConsentLinkReused'
            ? 'That bank authorization link was already used. Please start the connection again.'
            : 'The bank reported an error during authentication. Please try again.';

        return $this->callbackRedirect($route, 'error', $message);
    }

    /**
     * Resolve when access to this requisition's data expires.
     *
     * Prefers the End User Agreement's own acceptance timestamp; falls back to an
     * estimate anchored on now() whenever the agreement cannot be read, so an
     * unreachable API never blocks a successful connection.
     *
     * @return array<string, mixed>
     */
    private function resolveAccessWindow(GoCardlessRequisition $requisition, User $user): array
    {
        $validForDays = $this->intOrNull($requisition->getAttribute('access_valid_for_days'))
            ?? self::DEFAULT_ACCESS_VALID_FOR_DAYS;
        $agreementId = $this->stringAttr($requisition, 'agreement_id');

        if ($agreementId !== null) {
            try {
                $agreement = $this->gocardlessService->getEndUserAgreement($agreementId, $user);
                $validForDays = $this->intOrNull($agreement['access_valid_for_days'] ?? null) ?? $validForDays;
                $accepted = $agreement['accepted'] ?? null;
                $acceptedAt = is_string($accepted) && $accepted !== '' ? Carbon::parse($accepted) : now();

                return [
                    'access_valid_for_days' => $validForDays,
                    'max_historical_days' => $this->intOrNull($agreement['max_historical_days'] ?? null)
                        ?? $this->intOrNull($requisition->getAttribute('max_historical_days')),
                    'accepted_at' => $acceptedAt,
                    'access_valid_until' => $acceptedAt->copy()->addDays($validForDays),
                    'access_valid_until_estimated' => false,
                ];
            } catch (\Throwable $e) {
                Log::warning('Could not read End User Agreement, estimating access window', [
                    'requisition_row_id' => $this->rowId($requisition),
                    'error' => SensitiveDataRedactor::redact($e->getMessage()),
                ]);
            }
        }

        return [
            'access_valid_for_days' => $validForDays,
            'accepted_at' => now(),
            'access_valid_until' => now()->addDays($validForDays),
            'access_valid_until_estimated' => true,
        ];
    }

    /**
     * Point already-imported accounts at the fresh requisition and clear their
     * reconnect flag — this is what turns a re-authorization into a repaired link
     * rather than a duplicate account.
     *
     * @param  list<string>  $accountIds
     */
    private function relinkAccounts(GoCardlessRequisition $requisition, array $accountIds, User $user): void
    {
        $rowId = $this->rowId($requisition);

        foreach ($accountIds as $accountId) {
            $account = $this->accountRepository->findByGocardlessId($accountId, (int) $user->id);
            if (! $account instanceof Account) {
                continue;
            }

            $account->gocardless_requisition_id = $rowId;
            $account->gocardless_needs_reconnect = false;
            $account->save();
        }
    }

    private function callbackRedirect(string $route, string $key, string $message): RedirectResponse
    {
        $redirect = redirect()->route($route)->with($key, $message);

        // Preserved from the pre-signed-URL flow: returning to the accounts page after a
        // successful connection re-opens the import modal so accounts can be picked.
        if ($route === 'accounts.index' && $key === 'success') {
            $redirect->with('open_go_cardless_modal', true);
        }

        return $redirect;
    }

    /**
     * Deletes a GoCardless requisition by its GoCardless ID.
     *
     * The local row is kept (status revoked) so the connection history — and any
     * account still pointing at it — survives for reconnect UX.
     */
    public function deleteRequisition(Request $request, string $id): JsonResponse
    {
        Log::info('Deleting GoCardless requisition', ['id' => $id]);
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $row = $this->requisitionRepository->findByRequisitionIdForUser($id, (int) $user->id);
            if ($row === null) {
                return response()->json(['error' => 'Requisition not found'], 404);
            }

            $deleteImportedAccounts = $request->boolean('delete_imported_accounts', false);

            if ($deleteImportedAccounts) {
                $goCardlessAccountIds = $this->gocardlessService->getAccounts($id, $user);
                if ($goCardlessAccountIds !== []) {
                    $accounts = Account::where('user_id', $user->id)
                        ->whereIn('gocardless_account_id', $goCardlessAccountIds)
                        ->get();

                    foreach ($accounts as $account) {
                        $account->transactions()->delete();
                        $this->accountRepository->delete($account);
                    }
                    Log::info('Deleted imported accounts for requisition', [
                        'requisition_id' => $id,
                        'account_count' => $accounts->count(),
                    ]);
                }
            }

            $this->gocardlessService->deleteRequisition($id, $user);
            $this->requisitionRepository->updateStatus($row, GoCardlessRequisitionStatus::REVOKED);
            Log::info('Requisition deleted successfully', ['id' => $id]);

            return response()->json(['message' => 'Requisition deleted successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to delete requisition', array_merge(
                ['id' => $id],
                $this->exceptionContext($e, $request->user()?->getKey())
            ));

            return response()->json(['error' => 'Failed to delete requisition'], 500);
        }
    }

    /**
     * Imports a GoCardless account for the authenticated user by account ID.
     */
    public function importAccount(Request $request): JsonResponse
    {
        $validated = $request->validate(['account_id' => 'required|string']);
        $accountId = (string) $validated['account_id'];
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // GoCardless account IDs are guessable-adjacent opaque strings; without this the
        // endpoint would import any account id a caller can name. The second clause keeps
        // re-import/repair paths working for accounts already linked to this user.
        $ownsAccount = $this->requisitionRepository->userOwnsGoCardlessAccount((int) $user->id, $accountId)
            || $this->accountRepository->gocardlessAccountExists($accountId, (int) $user->id);

        if (! $ownsAccount) {
            Log::warning('Rejected import of a GoCardless account the user does not own', [
                'user_id' => $user->getKey(),
            ]);

            return response()->json(['message' => 'Account not available for this user'], 403);
        }

        Log::info('Importing account with requisition', ['account_id' => $accountId]);

        try {
            $account = $this->gocardlessService->importAccount($accountId, $user);

            return response()->json([
                'message' => 'Account imported successfully',
                'account_id' => $account->gocardless_account_id,
            ]);
        } catch (AccountAlreadyExistsException) {
            return response()->json(['message' => 'Account already exists'], 400);
        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (\Exception $e) {
            Log::error('Failed to process account', array_merge(
                ['account_id' => $accountId],
                $this->exceptionContext($e, $user->getKey())
            ));

            return response()->json(array_filter([
                'message' => 'Failed to import account',
                'ref' => $e instanceof GoCardlessApiException ? $e->correlationId : null,
            ]), 500);
        }
    }

    /**
     * Checks if a GoCardless account ID already exists for the authenticated user.
     */
    public function getExistingGocardlessAccountIDs(Request $request): JsonResponse
    {
        $request->validate(['account_id' => 'required|string']);
        $accountId = $request->input('account_id');

        $exists = Account::where('user_id', auth()->id())
            ->where('gocardless_account_id', $accountId)
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    /**
     * @return list<string>
     */
    private function accountIds(GoCardlessRequisition $requisition): array
    {
        $accounts = $requisition->getAttribute('accounts');

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_string')) : [];
    }

    private function stringAttr(GoCardlessRequisition $requisition, string $key): ?string
    {
        $value = $requisition->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function rowId(GoCardlessRequisition $requisition): int
    {
        $key = $requisition->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }
}
