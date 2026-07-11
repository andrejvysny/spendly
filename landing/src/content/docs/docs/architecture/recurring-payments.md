---
title: Recurring Payments
description: Automatic detection and management of subscriptions and recurring transactions.
---

## Overview

Spendly automatically detects recurring payments (subscriptions and repeating transactions) from your transaction history. Detected series are offered as **suggestions** — you can **confirm** or **dismiss** them.

## Data Model

- **RecurringGroup** — One recurring series (e.g., "Netflix"). Fields: `name`, `interval` (weekly/biweekly/monthly/quarterly/semiannual/yearly), `amount_min`/`amount_max`, `amount_current`, `confidence` (0–100), `currency`, `scope` (per_account/per_user), `status` (suggested/confirmed/dismissed), optional `counterparty_id`
- **Transaction.recurring_group_id** — Set when the transaction belongs to a confirmed RecurringGroup
- **RecurringDetectionSetting** — Per-user settings (scope, group_by, amount variance, min_occurrences, lookback_months, run_after_import, scheduled_enabled)
- **DismissedRecurringSuggestion** — Stores a fingerprint when dismissed to prevent re-suggestion

## Detection Algorithm (v2)

1. Load transactions for the user (optionally per account) in the lookback window (per-user `lookback_months`, default 24)
2. Group by payee **and currency**: counterparty or normalized description (strict counterparty-only mode available)
3. **Interval fit**: date gaps matched against windows for weekly/biweekly/monthly/quarterly/semiannual/yearly; a gap may span k missed occurrences (skipped month ≠ rejection); a 75% quorum of gaps must fit with a bounded number of outliers (refunds/double charges tolerated)
4. **Amount plateaus**: price changes start a new plateau instead of rejecting the series (`amount_current` tracks the latest price); interleaved same-payee subscriptions split via amount clustering
5. **Confidence** 0–100 from interval fit, amount stability, occurrence count, recency, and day-of-month consistency; suggestions below the threshold are not created; yearly/semiannual need only 2 occurrences
6. Skip if matching an existing confirmed/dismissed group (amount-independent v2 fingerprint — dismissals survive price changes)
7. Create or update RecurringGroup with status `suggested` (stable upsert; stale suggestions reconciled) — does not set `recurring_group_id` on transactions until confirmed

Tunables live in `config/recurring.php`.

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
- **Settings** (`/settings/recurring`) — Scope, group by, amount variance, lookback months, auto-detection toggles

## Tag Sync

When confirming a group, the service attaches the "Recurring" tag to all linked transactions. When unlinking, it removes the tag. This keeps the tag-based UX in sync with recurring groups.
