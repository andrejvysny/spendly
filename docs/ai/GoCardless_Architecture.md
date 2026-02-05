# GoCardless Bank Account Data – Architecture

## 1. Overview

The integration uses **GoCardless Bank Account Data API v2** (base URL: `https://bankaccountdata.gocardless.com`). It allows users to link bank accounts via requisitions, import account metadata and balances, and sync transactions.

### Key components

- **BankDataController** (`app/Http/Controllers/Settings/BankDataController.php`): Settings UI and API for credentials, institutions, requisitions, callback, import account, and transaction sync. All Bank Data operations use the client from the **factory** (mock or production).
- **GoCardlessService** (`app/Services/GoCardless/GoCardlessService.php`): Uses `GoCardlessClientFactoryInterface` to obtain a client; exposes methods for institutions, requisitions, create/delete requisition, get accounts, import account, and sync transactions.
- **BankDataClientInterface** (`app/Services/GoCardless/BankDataClientInterface.php`): Contract for all Bank Data API operations.
- **GoCardlessBankDataClient**: Production HTTP client. **MockGoCardlessBankDataClient**: In-memory mock for development when `GOCARDLESS_USE_MOCK=true`.
- **TokenManager** (`app/Services/GoCardless/TokenManager.php`): Obtains access token via `POST /api/v2/token/refresh/` with body `refresh` (not `refresh_token`), persists tokens on the User model.
- **Client factories** (`app/Services/GoCardless/ClientFactory/`): `GoCardlessClientFactoryInterface` is bound in `GoCardlessServiceProvider` to `MockClientFactory` when `config('services.gocardless.use_mock')` is true, otherwise `ProductionClientFactory`. This ensures the entire Settings > Bank Data flow (institutions, connect bank, callback, import, sync) uses the mock when enabled.

## 2. Authentication (API v2)

- **New token pair**: `POST /api/v2/token/new/` with `secret_id`, `secret_key` → returns `access`, `refresh`, `access_expires`, `refresh_expires`.
- **Refresh access**: `POST /api/v2/token/refresh/` with **`refresh`** (not `refresh_token`) → returns `access`, `access_expires`.
- TokenManager uses the User’s stored refresh token and persists new access/refresh when refreshing.

## 3. Flow

1. User sets GoCardless Secret ID and Secret Key in Settings > Bank Data.
2. **Institutions**: `GET /api/v2/institutions/?country={iso2}` (e.g. `gb`) → list of institutions (id, name, bic, logo, etc.).
3. **Requisition**: `POST /api/v2/requisitions/` with `institution_id`, `redirect` (callback URL), optional `agreement` → returns `id`, `link`. User is redirected to `link` to complete bank auth.
4. **Callback**: User returns to app callback URL. Controller reads `session('gocardless_requisition_id')`, calls **getAccounts(requisitionId)**, then **auto-imports** each account via `GoCardlessService::importAccount`, and redirects with a success message (e.g. “N account(s) linked”).
5. **List requisitions**: `GET /api/v2/requisitions/` → paginated `{ count, next, previous, results }`. Single: `GET /api/v2/requisitions/{id}/` → one Requisition (id, created, redirect, status, institution_id, agreement, accounts, link, etc.).
6. **Account data**: For each account ID from a requisition:
   - **Details**: `GET /api/v2/accounts/{id}/details/` → `{ "account": { "resourceId", "iban", "currency", "ownerName", "name", … } }`.
   - **Balances**: `GET /api/v2/accounts/{id}/balances/`.
   - **Transactions**: `GET /api/v2/accounts/{id}/transactions/?date_from=&date_to=` → `{ "transactions": { "booked", "pending" } }`.
7. **Transaction sync**: Duplicate detection and updates are **scoped by account**: `TransactionRepository::getExistingTransactionIds(int $accountId, array $transactionIds)` and `updateBatch(int $accountId, array $updates)`. The `transactions` table has a composite unique constraint on `(account_id, transaction_id)` so the same external transaction ID can exist in different accounts without collision.

## 4. Mock (development)

- **Default in local/development**: When `APP_ENV` is `local` or `development`, the mock is used by default. You can override with `GOCARDLESS_USE_MOCK=true` or `GOCARDLESS_USE_MOCK=false` in `.env`.
- **Fixture-based data**: If the directory `gocardless_bank_account_data/` exists, the mock loads institutions and account data from it. Each subdirectory (e.g. `Revolut`, `SLSP`) is an institution. Account IDs are discovered from `*_details.json` and `*_details_*.json` (e.g. `*_details_USD.json`) filenames. For each account, the mock serves:
  - **Details**: `{institution}/{prefix}_details{suffix}.json` (shape `{ "account": { ... } }`). The mock injects `institution_id` so imported accounts get the correct field extractor (Revolut/SLSP).
  - **Balances**: `{institution}/{prefix}_balances{suffix}.json`. If the file has no `closingBooked` balance, the mock adds one from the first available balance type so import/refresh work.
  - **Transactions**: `{institution}/{prefix}_transactions_booked{suffix}.json` (and optional `_transactions_pending`). Results are filtered by `date_from`/`date_to` when provided.
- **Fallback**: If an account ID has no fixture file (e.g. legacy `mock_account_1` / `mock_account_2`) or the fixture directory is missing, the mock falls back to in-memory fake data so existing tests and flows (e.g. `MOCK_INSTITUTION`) keep working.
- Mock `createRequisition` returns a `link` that points to the app’s callback URL with `?mock=1&requisition_id=...`, so the full connect → redirect → callback → list → import/sync flow can be tested without the real API.
- Mock stores requisitions in cache (per user) and returns API-aligned shapes (paginated list, single Requisition, account details with `account` key, balances, transactions with `booked`/`pending`).

## 5. Configuration and routes

- **Config**: `config/services.php` → `gocardless.use_mock` (env `GOCARDLESS_USE_MOCK`; when unset, defaults to `true` if `APP_ENV` is `local` or `development`, otherwise `false`), `gocardless.mock_data_path` (env `GOCARDLESS_MOCK_DATA_PATH`, default `base_path('gocardless_bank_account_data')`), `secret_id`, `secret_key`, etc.
- **Routes** (under `auth` except callback):  
  - `GET/PATCH settings/bank_data` (edit/update), `DELETE settings/bank_data/credentials`.  
  - `GET /api/bank-data/gocardless/institutions`, `GET/POST /api/bank-data/gocardless/requisitions`, `DELETE .../requisitions/{id}`.  
  - `GET /api/bank-data/gocardless/requisition/callback` (no auth).  
  - `POST /api/bank-data/gocardless/import/account`.  
  - `POST /api/bank-data/gocardless/accounts/{account}/sync-transactions`, `POST .../accounts/sync-all`.

## 6. Security and errors

- Controllers authorize via authenticated user; callback uses session to get requisition ID and optionally `auth()->user()`.
- No credentials in code; validation and policies used where applicable.
- Errors from the client are caught and returned as JSON or redirect with flash messages; token refresh failures are handled by TokenManager.

## 7. Related files

| Area           | Files |
|----------------|-------|
| Controller     | `app/Http/Controllers/Settings/BankDataController.php` |
| Service        | `app/Services/GoCardless/GoCardlessService.php` |
| Clients        | `app/Services/GoCardless/GoCardlessBankDataClient.php`, `MockGoCardlessBankDataClient.php` |
| Mock fixtures  | `app/Services/GoCardless/Mock/MockGoCardlessFixtureRepository.php`, `gocardless_bank_account_data/` |
| Token          | `app/Services/GoCardless/TokenManager.php` |
| Sync           | `app/Services/GoCardless/TransactionSyncService.php` |
| Repositories    | `app/Repositories/TransactionRepository.php` (account-scoped getExistingTransactionIds / updateBatch) |
| Provider       | `app/Providers/GoCardlessServiceProvider.php` |
| Frontend       | `resources/js/pages/settings/bank_data.tsx`, `resources/js/components/settings/requisition.tsx`, `GoCardlessImportWizard.tsx` |
