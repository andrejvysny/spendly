# Transfer detection

## Overview

Spendly detects internal money moves (own-account transfers) so they are excluded from spending analytics. Detection is **rule-based and tiered**: strong IBAN evidence auto-pairs, weaker evidence is scored, borderline cases land in the manual review queue instead of being guessed. Detected transfers get `type = TRANSFER` and (for two-leg moves) `transfer_pair_transaction_id` linking the legs. `Transaction::scopeExcludingTransfers()` excludes them from spending/analytics.

Covers: bank↔bank transfers (e.g. traditional bank ↔ Revolut top-up), transfers between two accounts at the same bank (standing orders), and single-leg internal moves (Revolut pocket/vault, round-ups) where the counterpart row does not exist as a transaction.

Orchestrator: `app/Services/TransferDetectionService.php`. Building blocks in `app/Services/Transfers/`: `TransferConfig`, `AccountContext`, `CandidatePair`, `PairEvaluator`, `HeuristicScorer`, `PairMatcher`, `SingleLegDetector`, `Iban`.

## Tiers

Base gates for any pair (all tiers): opposite signs, different accounts, same currency (except tier 4), booked-date gap ≤ `date_gap_days` (default 3 — SEPA settlement), amount difference ≤ `max(amount_tolerance, amount_tolerance_percent/100 × amount)`.

| Tier | Method (`metadata.transfer_detection.method`) | Evidence | Action |
|---|---|---|---|
| 1 | `iban_bidirectional` | Debit's target IBAN = credit account's IBAN **and** credit's source IBAN = debit account's IBAN | Auto-pair |
| 2 | `iban_one_sided` | One directional IBAN links the legs, the other is absent — and **no contradiction** (see below) | Auto-pair |
| 3 | `heuristic` | No IBAN evidence; additive signal score | ≥ `heuristic.auto_threshold` (0.60): auto-pair. In `[review_threshold, auto_threshold)` (0.35–0.60): review-flag both legs with `suggested_pair_id`. Below: ignore |
| 4 | `cross_currency` | Different currencies; amounts reconcile via `original_amount`/`exchange_rate`/`native_amount` within `cross_currency.tolerance_percent` (3%) | Review-flag by default; auto-pair only if `cross_currency.auto_mark` |
| — | `single_leg` | Internal pocket/vault move, counterpart row doesn't exist | `type = TRANSFER`, `pair_id = null`, `metadata.single_leg_transfer = true`; auto if `single_leg.auto_mark` (default), else review |
| — | `manual` | User bulk-paired two rows via UI (`TransactionBulkService::applyType`) | Requires different accounts + same currency + amounts summing to ~0; otherwise types set but pairing blocked (`pair_blocked_reason`) |

**Contradiction rule** (tiers 2–3): if a leg carries a counterparty IBAN that is *present but does not match* the other leg's account, the pair is disqualified entirely — money verifiably went elsewhere. Absent IBANs are neutral; wrong IBANs are a veto.

**Matching is global best-first**, not per-debit greedy: all candidates are generated (credits bucketed by amount cents), sorted by (tier, score desc, date gap, amount diff, ids), and swept assigning each leg at most once. Tier-3 **ambiguous twins** — identical (score, gap, diff) candidates sharing a leg — are never guessed; all involved legs are flagged `transfer_ambiguous`.

## Heuristic signals (tier 3)

Scored by `HeuristicScorer`, additive, capped at 1.0. Text matches are case- and diacritics-insensitive (`AccountContext::fold` = ASCII-fold + collapse whitespace + uppercase), so "Ján Kováč" matches "JAN KOVAC".

| Signal | Weight |
|---|---|
| Both legs have `metadata.transfer_candidate` (import-time flag) | 0.35 (one leg: 0.20) |
| Account owner's name appears in either leg's partner/description | 0.20 |
| One leg mentions the other leg's account name or bank name | 0.20 |
| Transfer-ish type per leg — TRANSFER/TOPUP/STANDINGORDER in `type`, `bank_transaction_code`, proprietary code, or `metadata.transfer_type_hint` | 0.10 each, cap 0.20 |
| Date proximity: same day 0.10, next day 0.05 | ≤ 0.10 |
| Exact amount match (diff < 0.005) | 0.05 |

Worked example (synthetic): a bank→Revolut top-up where both rows were import-flagged (0.35), the credit says "Top-Up by JANA KOVAC" matching the owner (0.20), same day (0.10) = 0.65 → auto-paired. An unrelated same-amount coffee + refund on the same day typically scores ≤ 0.15 → ignored.

## Detection evidence

Every mark writes an audit trail into `metadata.transfer_detection`:

```json
{
    "method": "heuristic",
    "score": 0.65,
    "signals": ["both_transfer_candidates", "own_name_match", "same_day"],
    "pair_id": 4211,
    "matched_at": "2026-07-11T09:00:00+00:00"
}
```

`score` is set for tier 3 only. Review-band suggestions store `suggested_pair_id` instead of `pair_id`. Single-leg marks have `pair_id: null` plus `metadata.single_leg_transfer: true`.

## Review reasons

Written to `transactions.review_reason` (comma-appendable) with `needs_manual_review = true`; auto-reasons are cleared automatically when the transaction is later confirmed as a transfer:

- `transfer_heuristic_review` — tier-3 score in the review band; `suggested_pair_id` points at the counterpart
- `transfer_ambiguous` — multiple equally-plausible tier-3 counterparts; nothing auto-assigned
- `transfer_cross_currency_candidate` — cross-currency pair reconciled within tolerance (default: review, not auto)
- `single_leg_transfer_candidate` — pocket/vault-looking row when `single_leg.auto_mark` is off
- `transfer_candidate_unpaired` — import flagged the row as a transfer but no counterpart was found

## When detection runs

- **After GoCardless sync** (`TransactionSyncService`) and **after CSV import** (`ImportWizardController`): windowed to the batch — from the earliest booked date of the new rows minus `detection_window_padding_days` (3). Skipped when the batch is empty.
- **CLI**: `php artisan transfers:detect [--user=ID]` — full history.
- **Manual pairing**: bulk "Mark as transfer" in the transactions UI (method `manual`).

## Repair: `transfers:fix-incorrect`

`php artisan transfers:fix-incorrect [--dry-run] [--user=] [--fix-pairs] [--include-heuristic]`

Reclassifies TRANSFER rows whose evidence no longer holds. **Method-aware**:

- `manual` and `single_leg` (or `metadata.single_leg_transfer`) — never touched (user intent / no pair expected).
- `iban_bidirectional` / legacy (no evidence) — full bidirectional IBAN recheck (with `--fix-pairs`).
- `iban_one_sided` — rechecked against the tier-2 rule (one-sided link + no contradiction).
- `heuristic` / `cross_currency` — skipped unless `--include-heuristic`; then unpaired only on **hard invariant violations** (same signs, same account, same-currency amount beyond tolerance) — scores are not re-litigated.
- Unpaired TRANSFER rows without protection are reclassified to debit/credit.

## Import-time signals (what feeds the tiers)

- `TransactionDataParser::classifyTransferType` — **strong** type strings (contains, folded: transfer, prevod, presun, überweisung, virement, bonifico) set `metadata.transfer_candidate`; **weak** strings (standing order variants, trvalým príkazom, topup) set only `metadata.transfer_type_hint` (scorer signal, no review spam). Strong + pocket-pattern description also sets `metadata.single_leg_transfer_candidate`.
- Virtual CSV field `partner_iban` (e.g. an "IBAN partnera" column) resolves by amount sign: negative → `target_iban`, positive → `source_iban`. Explicit directional mappings win. Auto-mapped by `ImportMappingService` (+ frontend parity in `ConfigureStep.tsx` / `FieldMappingService.ts`).
- `RevolutFieldExtractor` flags GoCardless pocket/vault moves (proprietary code TRANSFER, no counterparty IBANs, remittance matches `single_leg.patterns`) as `single_leg_transfer_candidate`.

## Configuration

`config/transfers.php` — all env-overridable (`TRANSFERS_*`), defaults work without `.env` changes:

| Key | Default | Meaning |
|---|---|---|
| `date_gap_days` | 3 | Max days between legs' booked dates |
| `amount_tolerance` | 0.01 | Absolute amount tolerance between legs |
| `amount_tolerance_percent` | 0.0 | Relative tolerance for fee-skimmed transfers; effective = max(abs, pct) |
| `heuristic.auto_threshold` | 0.60 | Tier-3 score to auto-pair |
| `heuristic.review_threshold` | 0.35 | Tier-3 score to review-flag |
| `cross_currency.enabled` | true | Run the cross-currency pass |
| `cross_currency.tolerance_percent` | 3.0 | FX reconciliation tolerance |
| `cross_currency.auto_mark` | false | Auto-pair instead of review-flag |
| `single_leg.auto_mark` | true | Auto-mark pocket/vault moves |
| `single_leg.patterns` | revolut vault, to/from pocket, pocket withdrawal, roundups | Folded substrings identifying pocket moves |
| `detection_window_padding_days` | 3 | Window padding for sync/import-triggered runs |

## ML fallback

`detectTransfersWithMlFallback` consults the optional ML service only for pairs the rules could not decide; ML-proposed pairs are accepted only when they also satisfy tier 1 or tier 2 (IBAN evidence) — the heuristic tier never launders ML guesses into auto-marks.
