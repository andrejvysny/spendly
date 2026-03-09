---
title: Recurring Payments
description: Automatic detection and management of subscriptions and recurring transactions.
---

## Overview

Spendly automatically detects recurring payments (subscriptions and repeating transactions) from your transaction history. Detected series are offered as **suggestions** — you can **confirm** or **dismiss** them.

## Data Model

- **RecurringGroup** — One recurring series (e.g., "Netflix"). Fields: `name`, `interval` (weekly/monthly/quarterly/yearly), `amount_min`/`amount_max`, `scope` (per_account/per_user), `status` (suggested/confirmed/dismissed), optional `merchant_id`
- **Transaction.recurring_group_id** — Set when the transaction belongs to a confirmed RecurringGroup
- **RecurringDetectionSetting** — Per-user settings (scope, group_by, amount variance, min_occurrences, run_after_import, scheduled_enabled)
- **DismissedRecurringSuggestion** — Stores a fingerprint when dismissed to prevent re-suggestion

## Detection Algorithm

1. Load transactions for the user (optionally per account) in a lookback window (default 12 months)
2. Group by payee: merchant_id or normalized description
3. For each group: sort by date, compute consecutive date deltas, infer interval with allowed variance; check amounts against configured variance
4. Keep only series with at least 3 occurrences and consistent interval + amount
5. Skip if matching an existing confirmed/dismissed group
6. Create RecurringGroup with status `suggested` — does not set `recurring_group_id` on transactions until confirmed

## When Detection Runs

- **After import/sync**: If user has `run_after_import` enabled, `RecurringDetectionJob` is dispatched after import or GoCardless sync
- **Scheduled**: `php artisan recurring:detect` runs for all users with `scheduled_enabled`. Options: `--user=ID`, `--account=ID`

## API

```
GET  /api/recurring                              # List suggested and confirmed groups
GET  /api/recurring/analytics?month=&year=       # Monthly breakdown
GET  /api/recurring/settings                     # Detection settings
PUT  /api/recurring/settings                     # Update settings
POST /api/recurring/groups/{id}/confirm          # Confirm suggestion
POST /api/recurring/groups/{id}/dismiss          # Dismiss suggestion
POST /api/recurring/groups/{id}/unlink           # Unlink and remove group
POST /api/recurring/groups/{id}/detach-transactions  # Detach specific transactions
POST /api/recurring/groups/{id}/attach-transactions  # Attach transactions
```

### Confirmed Group Stats

Confirmed groups include computed statistics:

- `first_payment_date`, `last_payment_date`
- `transactions_count`, `total_paid`, `average_amount`
- `projected_yearly_cost`, `next_expected_payment`

## UI

- **Recurring page** (`/recurring`) — Suggested list (confirm/dismiss), confirmed list with per-subscription statistics, monthly total
- **Analytics page** (`/analytics`) — Recurring overview card with projected yearly cost, total paid, top 5 subscriptions
- **Transactions list** — "Recurring" badge on linked transactions, "Recurring only" filter
- **Settings** (`/settings/recurring`) — Scope, group by, amount variance, auto-detection toggles

## Tag Sync

When confirming a group, the service attaches the "Recurring" tag to all linked transactions. When unlinking, it removes the tag. This keeps the tag-based UX in sync with recurring groups.
