---
title: Bank Sync (GoCardless)
description: Connect bank accounts via GoCardless for automatic transaction import.
---

## Overview

Spendly integrates with the **GoCardless Bank Account Data API v2** to link bank accounts, import metadata and balances, and sync transactions automatically.

### Key Components

| Component                        | Description                                                               |
| -------------------------------- | ------------------------------------------------------------------------- |
| **BankDataController**           | Settings UI and API for credentials, institutions, requisitions, and sync |
| **GoCardlessService**            | Methods for institutions, requisitions, accounts, import, and sync        |
| **BankDataClientInterface**      | Contract for all Bank Data API operations                                 |
| **GoCardlessBankDataClient**     | Production HTTP client                                                    |
| **MockGoCardlessBankDataClient** | In-memory mock for development                                            |
| **TokenManager**                 | OAuth token management, persists tokens on User model                     |

## Setup

1. Visit [GoCardless Bank Account Data](https://bankaccountdata.gocardless.com/) and sign up
2. Complete verification and generate API credentials
3. Add credentials to Settings > Bank Data in Spendly (or to `.env`)

## Connection Flow

1. User sets GoCardless Secret ID and Secret Key in Settings
2. **Browse institutions**: Select your bank from the list (filtered by country)
3. **Create requisition**: Redirected to your bank's authentication page
4. **Callback**: After bank auth, accounts are auto-imported
5. **Sync**: Transactions are synced, with duplicate detection per account

### Transaction Sync

Duplicate detection uses a composite unique constraint on `(account_id, transaction_id)`, so the same external transaction ID can exist in different accounts without collision.

The sync service handles:

- Importing new transactions
- Updating existing transactions if data changes
- Balance refresh from the API

## API Routes

| Route                                             | Description              |
| ------------------------------------------------- | ------------------------ |
| `GET/PATCH settings/bank_data`                    | Edit/update credentials  |
| `DELETE settings/bank_data/credentials`           | Remove credentials       |
| `GET /api/bank-data/gocardless/institutions`      | List institutions        |
| `GET/POST /api/bank-data/gocardless/requisitions` | List/create requisitions |
| `DELETE .../requisitions/{id}`                    | Delete requisition       |
| `POST /api/bank-data/gocardless/import/account`   | Import account           |
| `POST .../accounts/{account}/sync-transactions`   | Sync transactions        |
| `POST .../accounts/sync-all`                      | Sync all accounts        |
| `POST .../accounts/{account}/refresh-balance`     | Refresh balance          |

## CLI Commands

All Bank Data operations can be run from the terminal:

| Command                                 | Description                            |
| --------------------------------------- | -------------------------------------- |
| `gocardless:institutions --country=`    | List institutions                      |
| `gocardless:requisitions`               | List requisitions and accounts         |
| `gocardless:connect --institution=`     | Create requisition and import accounts |
| `gocardless:import-account {id}`        | Import one account                     |
| `gocardless:sync --account=`            | Sync transactions for one account      |
| `gocardless:sync-all`                   | Sync all GoCardless-linked accounts    |
| `gocardless:delete-requisition {id}`    | Delete a requisition                   |
| `gocardless:refresh-balance --account=` | Refresh balance                        |
| `gocardless:retry-failures`             | Retry unresolved sync failures         |

Use `--user=<id>` for user ID or email (defaults to first user).

## Mock Mode (Development)

When `APP_ENV` is `local` or `development`, the mock client is used by default. Override with `GOCARDLESS_USE_MOCK=true/false` in `.env`.

### Fixture-Based Data

If `gocardless_bank_account_data/` exists (or `GOCARDLESS_MOCK_DATA_PATH` is set), the mock loads institutions and account data from fixture files:

- Each subdirectory is an institution (e.g., `Revolut`, `SLSP`)
- Account IDs discovered from `*_details.json` filenames
- Transactions filtered by `date_from`/`date_to` when provided
- `createRequisition` returns a link to the callback URL with `?mock=1`

Sample fixture data is available in `sample_data/gocardless_bank_account_data/`.

## Authentication

The API uses OAuth2 tokens:

- **New token pair**: `POST /api/v2/token/new/` with `secret_id`, `secret_key`
- **Refresh access**: `POST /api/v2/token/refresh/` with `refresh` token
- TokenManager persists tokens on the User model and handles automatic refresh
