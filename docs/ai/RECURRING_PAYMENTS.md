# Recurring payments detection

## Overview

Spendly can automatically detect recurring payments (subscriptions and other repeating transactions) from your transaction history. Detected series are offered as **suggestions**; you can **confirm** (link transactions to a recurring group and optionally add the "Recurring" tag) or **dismiss** them.

## Data model

- **RecurringGroup**: One recurring series (e.g. "Netflix"). Fields include `user_id`, `name`, `interval` (weekly/monthly/quarterly/yearly), `amount_min`/`amount_max`, `scope` (per_account/per_user), `status` (suggested/confirmed/dismissed), and optional `merchant_id` / `normalized_description`.
- **Transaction.recurring_group_id**: Set when the transaction belongs to a **confirmed** RecurringGroup.
- **RecurringDetectionSetting**: Per-user settings (scope, group_by, amount variance, min_occurrences, run_after_import, scheduled_enabled).
- **DismissedRecurringSuggestion**: Stores a fingerprint when a suggestion is dismissed so the same series is not re-suggested.

## Detection algorithm

1. Load transactions for the user (optionally per account) in a lookback window (default 12 months).
2. Group by payee: merchant_id (if present and group_by allows) or normalized description.
3. For each group: sort by date, compute consecutive date deltas, infer interval (weekly ~7 days, monthly ~30, etc.) with allowed variance; check amounts against configured ±% or ±fixed variance.
4. Keep only series with at least 3 occurrences and consistent interval + amount.
5. Skip if the series matches an existing confirmed/dismissed group or a dismissed fingerprint.
6. Create or update a RecurringGroup with status `suggested`; store transaction IDs in `detection_config_snapshot`. Do not set `recurring_group_id` on transactions until the user confirms.

## When detection runs

- **After import/sync**: If the user has `run_after_import` enabled, `RecurringDetectionJob` is dispatched at the end of the import or GoCardless sync flow (see `ImportWizardController::process`, `BankDataController::syncAccountTransactions` / `syncAllAccounts`).
- **Scheduled**: Artisan command `php artisan recurring:detect` runs for all users with `scheduled_enabled`. Can be run for one user with `--user=ID` or one account with `--account=ID`.

## API

- `GET /api/recurring` – List suggested and confirmed groups.
- `GET /api/recurring/analytics?month=&year=` – Monthly recurring total and by-group breakdown.
- `GET /api/recurring/settings` – Get recurring detection settings.
- `PUT /api/recurring/settings` – Update settings.
- `POST /api/recurring/groups/{id}/confirm` – Confirm a suggested group (link transactions, optional "Recurring" tag).
- `POST /api/recurring/groups/{id}/dismiss` – Dismiss a suggestion.
- `POST /api/recurring/groups/{id}/unlink` – Unlink a confirmed group from its transactions.

## UI

- **Recurring page** (`/recurring`): Suggested list (confirm/dismiss), confirmed list (with optional transaction list per group), and monthly recurring total.
- **Transactions list**: Badge "Recurring" on transactions that have `recurring_group_id` set; filter "Recurring only" in the sidebar.
- **Settings** (`/settings/recurring`): Scope, group by, amount variance type/value, run after import, scheduled detection toggles.

## Tag sync

When confirming a group, the service can attach the "Recurring" tag to all linked transactions (default). When unlinking, it can remove that tag. This keeps the existing tag-based UX in sync with recurring groups.
