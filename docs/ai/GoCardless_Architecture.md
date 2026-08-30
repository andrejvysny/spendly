# GoCardless Bank Account Data – Architecture

## 1. Overview

Integration with the **GoCardless Bank Account Data API v2** (`https://bankaccountdata.gocardless.com/api/v2`). Users link a bank via a requisition (EUA + consent flow), import accounts, and sync transactions. Sync runs on a queue, never inline; requisitions are persisted locally with a status lifecycle instead of being re-derived from the API on every page load.

## 2. Components

- **Controllers** (`app/Http/Controllers/Settings/`) — three, replacing an earlier monolithic controller:
    - `GoCardlessCredentialController` — Settings > Bank Data page, personal credential save/purge.
    - `GoCardlessRequisitionController` — institutions, requisition create/list/delete/reconnect, the bank callback, account import.
    - `GoCardlessSyncController` — queue a sync, poll sync status, refresh balance.
- **`GoCardlessService`** (`app/Services/GoCardless/GoCardlessService.php`) — orchestration: resolves a per-user client via the factory, drives requisition creation, transaction sync, balance refresh, sync watermark logic.
- **`BankDataClientInterface`** — contract for all Bank Data API calls. `GoCardlessBankDataClient` is the production HTTP client; `MockGoCardlessBankDataClient` serves fixture data.
- **`ClientFactory\GoCardlessClientFactoryInterface`** — bound in `GoCardlessServiceProvider` to `MockClientFactory` when `config('services.gocardless.use_mock')`, else `ProductionClientFactory`. The mock path never touches `CredentialsResolver`.
- **`TokenManager`** — per-user access/refresh token lifecycle, persisted on the `User` model, guarded by a cache lock (`gc_token:{user_id}`) so concurrent requests/jobs don't race a refresh.
- **`CredentialsResolver`** — decides which secret pair a user's sync runs on (see §3).
- **`TransactionSyncService`** — maps, validates, deduplicates, and persists a batch of provider transactions; computes the sync watermark.
- **`TransactionDeduplicator`**, **`TransactionDataValidator`**, **`GocardlessMapper`**, **`FieldExtractors/`** — sync pipeline stages (see §7).
- **`SyncGoCardlessAccountJob`** — queued, one job per account sync (see §6).

## 3. Credential resolution

`CredentialsResolver` (`app/Services/GoCardless/CredentialsResolver.php`), strict precedence:

1. **User override** — `users.gocardless_secret_id` / `gocardless_secret_key`, set via Settings > Bank Data.
2. **Instance credentials** — `GOCARDLESS_SECRET_ID` / `GOCARDLESS_SECRET_KEY` env (`config('services.gocardless.secret_id'|'secret_key')`).

A **half-filled** user override (one field set, the other blank) is always an error (`MissingGoCardlessCredentialsException::partial()`) — it never silently falls back to the instance pair. Changing credentials discards every stored token for that user (`TokenManager::rotateForNewCredentials`, keyed by a `gocardless_token_secret_hash` fingerprint of the pair) so a token minted under the old pair can never be reused under the new one. `config/services.php` also reads `consent_warning_days`, `consent_check_stale_hours`, `min_sync_interval_hours`, `dispatch_stagger_seconds` — see §6 and §8.

## 4. Bank connection flow

1. User clicks connect (institution + optional `return_to`). `GoCardlessRequisitionController::startRequisitionFlow` mints a **signed, single-use, 2-hour** callback URL (`URL::temporarySignedRoute('bank_data.gocardless.callback', ..., ['reference' => uuid])`), creates the EUA + requisition at GoCardless, and writes a `gocardless_requisitions` row (status `pending`) keyed by that `reference`.
2. User authorizes at the bank and is redirected to the callback.
3. `handleRequisitionCallback` verifies the signature (`URL::hasValidSignature`, ignoring bank-appended params not covered by it — see `UNSIGNED_CALLBACK_PARAMS`), looks up the row by `reference` (never by session/auth — the browser may arrive with no session at all), and **claims** it (`claimCallback`, atomic null→timestamp transition) so a replayed redirect is a no-op instead of re-hitting the API.
4. On success: fetches account IDs, marks the row `linked`, resolves `access_valid_until` from the EUA's `accepted`/`access_valid_for_days` (falls back to an estimate anchored on `now()` if the EUA read fails), relinks any already-imported accounts to the fresh row, and marks other requisitions for the same institution `replaced`.
5. Accounts are **not** auto-imported on callback. The user picks which discovered accounts to import via `POST /import/account`, which checks the caller actually owns the GoCardless account ID (via the requisition or an existing linked account) before importing.

## 5. Requisition persistence & consent lifecycle

`gocardless_requisitions` (migration `2026_08_02_200000`) stores one row per requisition: local `status` (`GoCardlessRequisitionStatus` enum: pending/linked/expired/suspended/rejected/error/cancelled/replaced/revoked), the raw GoCardless status code, `access_valid_until` (+ `_estimated` flag), `last_checked_at`, `expiry_warning_sent_at`, `last_error`. `accounts.gocardless_requisition_id` links an imported account back to the row that authorized it.

- **List** (`GET /requisitions`) is **DB-first**: reads local rows, excluding `revoked`/`replaced`. `?refresh=1` reconciles against the live API first (`upsertFromRemote`, terminal local rows are never resurrected by a remote payload); a failing refresh degrades to the stored rows rather than erroring.
- **`gocardless:check-consent`** (daily, 05:30) is the lifecycle keeper: expires rows whose `access_valid_until` has passed, stamps a one-time warning `consent_warning_days` (default 7) out from expiry, and polls requisitions not checked in `consent_check_stale_hours` (default 24) to catch revocations that never produced a callback. Any status change that `needsReconnect()` flags every account the requisition authorized (`accounts.gocardless_needs_reconnect`).
- **Reconnect** (`POST /requisitions/{row}/reconnect`) re-runs the same `startRequisitionFlow` for the same institution; the old row is untouched until the new one supersedes it on a successful callback.
- **`gocardless:backfill-requisitions`** — one-shot repair for accounts imported before this table existed: mirrors GoCardless's requisition list locally and points already-imported accounts at the matching row. Not scheduled; the normal `?refresh=1` path already reconciles on demand.

## 6. Sync pipeline

Sync is always queued, never run from an HTTP request thread. Routes below are under `/api/bank-data/gocardless/`, `auth` middleware except the callback:

- `POST /accounts/{account}/sync-transactions` and `/accounts/sync-all` mark the account `queued` and dispatch `SyncGoCardlessAccountJob` (queue `gocardless`), returning **202** immediately. A sync already in flight is a no-op, not a second dispatch.
- The job (`ShouldBeUnique` per account, `retryUntil` 12h, no `$tries`, `$maxExceptions = 3`, `timeout = 280s` — under the worker's `--timeout=300`) calls `GoCardlessService::syncAccountTransactions`. A 429 is `release()`d for the bank-given `retry_after` (doesn't burn the exception budget); a lapsed consent marks the account `needs_reconnect` and fails permanently (retrying can't fix it).
- **`gocardless:dispatch-sync`** is the scheduled entry point (every 4h, all users): queues one job per account that isn't mid-sync, isn't cooling down, and wasn't synced within `min_sync_interval_hours` (default 8), staggering dispatches by `dispatch_stagger_seconds` (default 20s, capped at 1800s) so a large installation doesn't arrive at the bank in one burst. This budget exists because GoCardless's free tier caps calls per endpoint per account per day.
- Clients learn the outcome by polling `GET /accounts/{account}/sync-status` (or `GET /accounts/sync-status` for every account), which returns the whitelisted `gocardless_sync_status`/`_error`/`_finished_at`/`needs_reconnect` columns — never a raw account row.
- **Sync watermark** (`GoCardlessService::resolveSyncWatermark`, drives the next run's `date_from`): a **partial** page fetch never advances it (unknown what's missing); a clean fetch advances to `date_to`; a fetch with failures pulls it back to the day before the earliest failed row's date, clamped to `[now()-90d, date_to]`.

## 7. Mapping, validation, dedup

Each raw provider transaction goes through, per batch of `TransactionSyncService::BATCH_SIZE` (100):

1. **`GocardlessMapper`** — provider-specific field extraction (`RevolutFieldExtractor`, `SlspFieldExtractor`, `GenericFieldExtractor` fallback).
2. **`TransactionDataValidator`** — field validation; synthesises a `fallback_*` ID for provider rows sent without a `transactionId` (tracked separately so such rows are never trusted as uniquely identified).
3. **`TransactionDeduplicator::decide`** — in order: (1) same `transaction_id` already stored for this account → update (or skip if `updateExisting=false`); (2) a strongly-matching CSV-imported row (only among rows with `is_gocardless_synced=false`) → adopt/enrich it instead of creating a duplicate; (3) no provider ID **and** the fingerprint already exists → skip; (4) a weaker CSV-import overlap → create, flagged `needs_manual_review`; (5) otherwise → create.
4. Native-currency amount resolved via `ExchangeRateService`; fingerprint (SHA256) computed; rule engine (`TRANSACTION_CREATED` trigger) and ML annotation run after the DB write commits.

Only **booked** transactions are synced — pending transactions from the API (`transactions.pending`) are intentionally never read into the pipeline. This is a deliberate design decision, not a gap: pending amounts can still change or vanish before settling, and treating them as final would corrupt balances/categorization.

## 8. Errors and rate limits

- **`GoCardlessApiException`** — non-2xx responses; carries a correlation ID (logged with the redacted response body) so an operator can find the real error without it ever reaching the browser or a stack trace.
- **`GoCardlessRateLimitException`** (429) — carries `retryAfterSeconds` from `X-Ratelimit-Account-Success-Reset`.
- **`GoCardlessConsentExpiredException`** — 401/403 whose body names a withdrawn/expired consent (matched against known GoCardless error markers), distinct from a generic auth failure so the caller flags the account instead of retrying.
- A 401 is retried once with a freshly-minted token (`sendWithAuthRetry`) before being classified as consent-expired or a generic API error.
- **`SensitiveDataRedactor`** strips secret/token/IBAN/authorization values from any response body or exception message before it is logged or surfaced.
- **Route throttles** (`RouteServiceProvider`): `gocardless-read` 60/min, `gocardless-write` 10/min, `gocardless-sync` 6/min (per user), `gocardless-callback` 30/min (per IP). Sync-status polling uses the `-read` limiter, not `-sync`, so polling doesn't eat the sync budget.

## 9. Mock mode

`GOCARDLESS_USE_MOCK=true` (default when `APP_ENV` is `local`/`development`) swaps in `MockClientFactory` → `MockGoCardlessBankDataClient`, which serves fixture data from `GOCARDLESS_MOCK_DATA_PATH` (default `sample_data/gocardless_bank_account_data/`, one subdirectory per institution). Falls back to in-memory fake data for account IDs with no fixture file. The mock's `createRequisition` returns a link back to the app's own callback URL with `?mock=1&requisition_id=...`, so the full connect → callback → import → sync flow can be exercised without the real API.

## 10. Commands

| Command                                                                                          | Purpose                                                                   |
| ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------- |
| `gocardless:dispatch-sync`                                                                       | Scheduled (4h): queue a sync for every due account, all users.            |
| `gocardless:check-consent`                                                                       | Scheduled (daily 05:30): expire/warn/poll consent lifecycle.              |
| `gocardless:retry-failures`                                                                      | Scheduled (30min): retry failed rows through the canonical sync pipeline. |
| `gocardless:backfill-requisitions`                                                               | One-shot: mirror remote requisitions into the local table.                |
| `gocardless:sync --account=`                                                                     | Sync one account inline (or `--queue` to dispatch).                       |
| `gocardless:sync-all [--all]`                                                                    | Sync one/every user's accounts inline (or `--queue`).                     |
| `gocardless:institutions/connect/import-account/requisitions/delete-requisition/refresh-balance` | Testing/agent utilities — see AGENTS.md.                                  |

## 11. Related files

| Area               | Files                                                                                                                                                                                |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Controllers        | `app/Http/Controllers/Settings/GoCardless{Credential,Requisition,Sync}Controller.php`                                                                                                |
| Service            | `app/Services/GoCardless/GoCardlessService.php`                                                                                                                                      |
| Credentials/tokens | `CredentialsResolver.php`, `TokenManager.php`, `DTOs/GoCardlessCredentials.php`                                                                                                      |
| Clients            | `GoCardlessBankDataClient.php`, `MockGoCardlessBankDataClient.php`, `ClientFactory/`                                                                                                 |
| Sync pipeline      | `TransactionSyncService.php`, `TransactionDeduplicator.php`, `TransactionDataValidator.php`, `GocardlessMapper.php`, `FieldExtractors/`                                              |
| Requisitions       | `app/Models/GoCardlessRequisition.php`, `app/Enums/GoCardlessRequisitionStatus.php`, `app/Repositories/GoCardlessRequisitionRepository.php`                                          |
| Job                | `app/Jobs/SyncGoCardlessAccountJob.php`                                                                                                                                              |
| Exceptions         | `app/Exceptions/GoCardless{Api,RateLimit,ConsentExpired}Exception.php`, `MissingGoCardlessCredentialsException.php`                                                                  |
| Provider           | `app/Providers/GoCardlessServiceProvider.php` (bindings), `RouteServiceProvider.php` (throttles)                                                                                     |
| Frontend           | `resources/js/pages/settings/bank_data.tsx`, `components/settings/requisition.tsx`, `components/accounts/GoCardlessSyncModal.tsx`, `pages/accounts/detail.tsx` (sync-status polling) |
