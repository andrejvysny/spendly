# Recurring payments detection

## Overview

Spendly can automatically detect recurring payments (subscriptions and other repeating transactions) from your transaction history. Detected series are offered as **suggestions** ranked by a 0–100 **confidence** score; you can **confirm** (link transactions to a recurring group and optionally add the "Recurring" tag) or **dismiss** them.

## Data model

- **RecurringGroup**: One recurring series (e.g. "Netflix"). Fields include `user_id`, `name`, `interval` (weekly/biweekly/monthly/quarterly/semiannual/yearly), `amount_min`/`amount_max` (band across all price plateaus), `amount_current` (latest price plateau), `confidence` (0–100), `currency`, `scope` (per_account/per_user), `status` (suggested/confirmed/dismissed), and optional `counterparty_id` / `normalized_description`.
- **Transaction.recurring_group_id**: Set when the transaction belongs to a **confirmed** RecurringGroup.
- **RecurringDetectionSetting**: Per-user settings (scope, group_by, amount variance, min_occurrences, lookback_months, run_after_import, scheduled_enabled).
- **DismissedRecurringSuggestion**: Stores a fingerprint when a suggestion is dismissed so the same series is not re-suggested.

## Detection algorithm (v2)

Global tunables live in `config/recurring.php` (interval windows, gap quorum, plateaus, confidence threshold, high-frequency guard); per-user preferences in `recurring_detection_settings`.

1. Load transactions for the user (optionally per account) in the lookback window (per-user `lookback_months`, default 24 — yearly series need at least ~24).
2. Group by payee **and currency**: `counterparty_id` if present, else normalized description. `group_by = counterparty_only` is strict (transactions without a counterparty are skipped); `counterparty_and_description` falls back to the normalized description.
3. **Interval fit**: consecutive date gaps are matched against interval windows (weekly 5–10d, biweekly 11–18d, monthly 25–36d, quarterly 80–100d, semiannual 170–195d, yearly 350–380d). Each gap may span `k` expected occurrences (`k ≤ 3`), so a skipped month (~60d) counts as one *missed occurrence*, not a rejection. A **quorum** of gaps (75%) must fit and at most `max(1, 25%)` may be outliers — a single refund or double charge does not reject the series. Candidates are ranked by fitted fraction, then k=1 fraction (so a true biweekly series is not classified weekly-with-gaps), then fewer missed, then longer nominal.
4. **Amount plateaus**: amounts are segmented chronologically into price levels. A price change (same sign, step ≤ 100%, confirmed by the next occurrence) starts a new plateau — a Netflix-style price increase keeps the series whole and updates `amount_current`. Refunds/one-offs become outliers (bounded at `max(1, 20%)`). A revisited price level means interleaved subscriptions → the payee group is split by **1-D amount clustering** and each cluster is fitted independently (cluster series must keep their own cadence: k=1 fraction ≥ 0.5).
5. **Occurrences**: short intervals require `max(user min_occurrences, per-interval floor)`; semiannual/yearly use only their floor (2), otherwise they would be undetectable.
6. **High-frequency guard**: payees charging >4×/month (groceries, cafes) are skipped unless a clean weekly cadence fits (k=1 fraction ≥ 0.9).
7. **Confidence** = `100 × (0.35·interval + 0.25·amount + 0.20·occurrences + 0.20·recency)` + up to 5 bonus for day-of-month consistency. Interval = 0.7·fitted fraction + 0.3·k=1 fraction; amount penalizes extra plateaus and outliers; occurrences saturates at min+3; recency decays to 0 at 3× the nominal interval. Suggestions below `config('recurring.min_confidence')` (40) are not created.
8. Skip if the series matches an existing confirmed/dismissed group or a dismissed fingerprint. The **v2 fingerprint** is `sha256('v2|user|account-or-all|payeeKey|CURRENCY|interval|c{clusterOrdinal}')` — amount-independent, so dismissals survive price changes.
9. Create or update a RecurringGroup with status `suggested` (rows upsert in place across runs; stale suggestions whose fingerprint was not reproduced are reconciled away). Snapshot stores `transaction_ids`, `amount_outlier_transaction_ids`, `plateaus`, `missed_count`, `fitted_fraction`, `algorithm_version: 2`. `recurring_group_id` is not set on transactions until the user confirms.

## When detection runs

- **After import/sync**: If the user has `run_after_import` enabled, `RecurringDetectionJob` is dispatched at the end of the import or GoCardless sync flow (see `ImportWizardController::process`, `BankDataController::syncAccountTransactions` / `syncAllAccounts`).
- **Scheduled**: Artisan command `php artisan recurring:detect` runs for all users with `scheduled_enabled`. Can be run for one user with `--user=ID` or one account with `--account=ID`.

## API

- `GET /api/recurring` – List suggested and confirmed groups, suggested ordered by confidence. Confirmed groups include a **stats** object (derived from linked transactions): `first_payment_date`, `last_payment_date`, `transactions_count`, `total_paid`, `average_amount`, `projected_yearly_cost`, `next_expected_payment`.
- `GET /api/recurring/analytics?month=&year=` – Monthly recurring total and by-group breakdown.
- `GET /api/recurring/settings` – Get recurring detection settings.
- `PUT /api/recurring/settings` – Update settings (incl. `lookback_months` 6–48).
- `POST /api/recurring/groups/{id}/confirm` – Confirm a suggested group (link transactions, optional "Recurring" tag).
- `POST /api/recurring/groups/{id}/dismiss` – Dismiss a suggestion.
- `POST /api/recurring/groups/{id}/unlink` – Unlink and remove a confirmed group: detach its transactions (and optionally remove Recurring tag), then delete the group so it no longer appears in the list.
- `POST /api/recurring/groups/{id}/detach-transactions` – Detach specific transactions from a confirmed group (body: `transaction_ids`, optional `remove_recurring_tag`). The group is not deleted.
- `POST /api/recurring/groups/{id}/attach-transactions` – Attach existing transactions to a confirmed group (body: `transaction_ids`, optional `add_recurring_tag`). Respects group scope (per_account/per_user); returns 422 with `ineligible_transaction_ids` if any transaction is wrong account or already in another group.

## UI

- **Recurring page** (`/recurring`): Suggested list (confirm/dismiss) with confidence badge (≥80 high / 60–79 medium / <60 low) and current-amount display, confirmed list with per-subscription **statistics** (collapsed: started date, payment count, total paid; expanded: first/last payment, count, average, total paid, projected yearly, next expected), per-transaction "Detach" and "Add transaction", and monthly recurring total. Amounts render in the group's currency.
- **Analytics page** (`/analytics`): **Recurring overview** card shows total projected yearly, total paid (all time), subscription count, and top 5 by projected yearly cost (with link to Recurring page).
- **Transactions list**: Badge "Recurring" on transactions that have `recurring_group_id` set; filter "Recurring only" in the sidebar.
- **Settings** (`/settings/recurring`): Scope, group by (strict counterparty vs description fallback), amount variance type/value, lookback months, run after import, scheduled detection toggles.

## Tag sync

When confirming a group, the service can attach the "Recurring" tag to all linked transactions (default). When unlinking, it can remove that tag. This keeps the existing tag-based UX in sync with recurring groups.

## Migration notes (v2)

- `2026_07_11_000000` adds `confidence`, `amount_current`, `currency` to `recurring_groups`.
- `2026_07_11_000001` adds `recurring_detection_settings.lookback_months` (default 24) and normalizes legacy `merchant_*` group_by values.
- `2026_07_11_000002` recomputes fingerprints of confirmed/dismissed groups to v2 and deletes stale suggested rows once (they regenerate on the next run). Legacy v1 hashes in `dismissed_recurring_suggestions` remain as harmless orphans.
