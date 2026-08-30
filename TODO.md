# TODO

Updated: 2026-08-30

# Session: GoCardless Sync Correctness — IN PROGRESS 2026-08-30

Plan: `~/.claude/plans/do-thorough-analysis-of-concurrent-flame.md`. Branch: `fix/gocardless-sync-hardening`. No commits without explicit instruction.
Gates: no NEW phpstan errors in touched files, scoped pint (`vendor/bin/pint <files>`), tests via `docker compose run --rm --no-deps test sh -c "php artisan test --filter=..."`.

## Phase 0 — connection dies 24h after connecting — DONE 2026-08-30

- [x] 0.1 Token refresh incompatible with API. `/token/refresh/` (SpectacularJWTRefresh) returns only
      `access` + `access_expires`, but `TokenManager::updateTokens()` and
      `GoCardlessBankDataClient::processTokenResponse()` both demanded all four keys, so every valid
      refresh threw. Sync worked 24h (access TTL), then failed ~29 days until the refresh grant
      expired, then self-healed. Split into `storeNewTokenPair()` / `storeRefreshedAccessToken()` and
      `processNewTokenResponse()` / `processRefreshedTokenResponse()`; refresh token now preserved,
      rotated only if the response carries one. Both test suites had faked the wrong 4-key shape —
      corrected, added `refreshTokenResponse()` helper + 2 regression tests. 46 tests green.

## Phase 1 — stop losing data / stop reporting false success — DONE 2026-08-30

- [x] 1.1 `mapAccountData()` stamped `gocardless_last_synced_at = now()` at import, so the first
      sync of a fresh account fetched 1 day instead of 90. Stamp removed (null watermark already
      means full window). `calculateDateRange()` had been mocked in all 6 test sites — added 5
      direct tests + a guard that import never claims a watermark.
- [x] 1.2 `insertOrIgnore` dropped rows silently while the watermark still advanced. `createBatch`
      shortfall now surfaces as `stats['dropped']`, logged, and `resolveSyncWatermark()` holds on it.
      NOTE: the plan also proposed holding on `skipped_reasons` — dropped after tracing the code.
      Every skip reason (`update_disabled`, `fingerprint_duplicate`, `duplicate_in_batch`) means the
      row IS already stored, so holding on them would freeze the watermark on every incremental sync.
- [x] 1.3 Job discarded sync stats and stamped `success` unconditionally. Stats now captured,
      persisted to new `accounts.gocardless_sync_stats` (JSON), exposed in `syncStatusPayload()`;
      a run that lost rows is `incomplete` (new status; `success_with_errors` would not fit the
      16-char column). UI shows counts and an "Synced with errors" chip.
- [x] 1.4 `flash.success`/`flash.error` were shared to every page and read by nothing — all 9
      bank-connect callback outcomes were silent. Bridged to the already-mounted `ToastContainer`
      via `useFlashToasts()` in `app-layout.tsx`.

## Phase 2 — stop getting permanently stuck — 2.1-2.4 DONE 2026-08-30

- [x] 2.1 Stale-sync reaper. `gocardless_sync_started_at` was written and read nowhere, so a
      SIGKILLed worker wedged an account in `syncing` forever. `AccountRepository::reapStaleSync()`
      called from dispatch-sync + both HTTP sync endpoints. Separate thresholds: syncing 900s,
      queued 3600s (a queued job may legitimately wait out the 1800s stagger + a release).
      Existing rate-limit cooldown is carried through the reap.
- [x] 2.2 `failed()` hook erased the rate-limit cooldown (`markSyncFailed` writes retry_after
      unconditionally). `rate_limited` added to the short-circuit list.
- [x] 2.3 Unbounded 429 release could outlive `uniqueFor=1800`/`retryUntil=12h`. Capped at
      `MAX_RELEASE_SECONDS = 1500`; a longer wait is not parked on the queue at all — the account
      cooldown carries it and dispatch-sync re-queues. Stale `uniqueFor` docblock corrected.
- [x] 2.4 409 `AccountSuspendedError` was misclassified as transient (markers only checked on
      401/403). 409 added. ALSO: the marker list did not match the documented 409 wording at all
      ("Account suspended" / "...requisition was suspended") — found by the new test, markers added.
- [ ] 2.5 `compose.ml.yml` missing `REDIS_QUEUE_RETRY_AFTER=360` (redis default 90s vs
      `--timeout=300` = double-reserved syncs). BLOCKED: protected Docker config, awaiting go-ahead.

## Gates (all green 2026-08-30)

- Full suite: 1116 passed, 30 skipped (pre-existing RuleEngine skips), 0 failed. Baseline 1093.
- phpstan on touched paths: 204 errors vs 209 baseline — zero new, 5 pre-existing fixed by the
  token-response narrowing. (Diffed against a `main` worktree; raw counts are meaningless alone.)
- pint: 18 files pass. tsc clean, eslint clean, prettier clean.

## Open questions (asked, unanswered)

- Has this run against a real bank >24h? (would contradict the 0.1 analysis)
- 2.5 approved? (only remaining blocked item)
- Pull the 7-day rolling overlap forward? `calculateDateRange()` still uses a 1-day overlap, so a
  bank backfilling several days late can still drop a transaction that 1.1 alone does not cover.


# Session: GoCardless Production Hardening — IN PROGRESS 2026-08-02

Plan: `~/.claude/plans/act-as-senior-software-frolicking-rocket.md`. No commits without explicit instruction. Branch: main (branch question open).
Gates: no NEW phpstan errors in touched files (~1923 pre-existing, no baseline), scoped pint, tests via `docker compose run --rm test php artisan test --filter=...` (3 pre-existing deletion-listener failures NOT ours).
Agent-brief rules: scoped pint only (`vendor/bin/pint <files>`), NEVER git checkout/restore/stash (clobbered parallel agents once already).

## Phase 0 — Security hotfixes — DONE 2026-08-02 (diff-reviewed, gates green)

- [x] 0.1 Account policy (IDOR /accounts/{id}) + user_id out of fillable + createForUser (+ImportWizardController/AccountSeeder call sites; AuthorizesRequests on AccountController not base — ImportController private authorize() collision)
- [x] 0.2 Cross-tenant response caching deleted from GoCardlessBankDataClient (institutions cache kept; net −82)
- [x] 0.3 Rate limiters (read 60 / write 10 / sync 6 / callback 30 per min) + verified on GC routes
- [x] 0.4 RequisitionResource whitelist (ssn/redirect/reference/account_selection stripped; link null when LN)
- [x] 0.5 Migration 2026*08_02_173740: 4 users.gocardless*\* columns → TEXT
- [x] 0.6 sync-all --all + scheduler uses it (C3 stopgap; 3-user regression test)
- [x] 0.7 trustProxies(env TRUSTED_PROXIES, default \*) + .env.example
- Gates verified by main thread: full suite 722 passed, only the 3 documented pre-existing failures; tsc + eslint clean.

## Phase 1 — Correctness core

- [x] 1.1 send() unification: 401→refresh→retry-once (TokenManager::refreshAfterUnauthorized, rotation-aware) + pagination cursor fix (visited-set + SSRF + 50-page guards) + partial results shape (diff-reviewed; client phpstan 30→24)
- [x] 1.2 GoCardlessApiException (correlation IDs) + SensitiveDataRedactor + log/error hygiene; no bodies/traces to browser or logs; catch-site audit done; fallback token path leak fixed inline (diff-reviewed)
- [x] 1.3 Currency ISO-4217 format validation (malformed→error, uncommon→review, never relabel) + balance_after_transaction null default + frontend null render (diff-reviewed)
- [x] 1.4 TransactionDeduplicator + DedupDecision DTO; two-pass processBatch; is_gocardless_synced narrowing on import heuristics (PATH B); post-validation existingIds (PATH C); fingerprint-skip only for id-less rows (PATH A); in-batch guards; skipped_reasons stats+logs; BatchInsert real count. 16 dedup tests incl. headline. Full suite 820+3 pre-existing. (diff-reviewed)
- [x] 1.5 resolveSyncWatermark (partial→hold, errors→pull back to earliest-1d clamped 90d, clean→date_to) + retry command grouped per account through resyncRawTransactions (canonical pipeline: dedup/native_amount/rules/ML). Phase 1 gate: 864 passed + 3 pre-existing, tsc clean. (diff-reviewed)
- FOLLOW-UP noted: ExchangeRate 'date' cast persists with time suffix on SQLite → findRateWithWalkback same-day string compare misses (pre-existing, out of scope)
- [x] BONUS: GoCardlessFixtureLoader pointed to sample_data/ — woke 39 dormant fixture-gated tests (fixed 1 stale assertion in RevolutFieldExtractorTest). Wave-1 gate: 780 passed, 3 pre-existing failures, tsc clean.

## Phase 2 — Architecture

- [x] 2.1 gocardless_requisitions table (+accounts link migration), GoCardlessRequisitionStatus enum, model (relation renamed linkedAccounts — accounts JSON cast collision), repo w/ atomic claimCallback, factory, policy, bindings. 34 tests. (diff-reviewed)
- [x] 2.2 Signed callback (URL::hasValidSignature + fixed ignoreQuery allowlist; claim-before-API single-use; user from row, no session/auth fallback) + DB-first list w/ ?refresh=1 upsert (terminal rows never resurrected) + import/delete ownership checks + getEndUserAgreement. 892+3 gate. (diff-reviewed)
- NOTE: if GoCardless adds new redirect params, extend UNSIGNED_CALLBACK_PARAMS allowlist in GoCardlessRequisitionController.
- [x] 2.3 GoCardlessConsentExpiredException (marker-based 401/403 detection) + gocardless:check-consent daily (expire/warn/poll, terminal states protected) + reconnect endpoint (policy-gated, reuses startRequisitionFlow) + gocardless:backfill-requisitions + sync-path 409 reconnect_required + frontend badges/alerts. 928+3 gate. (diff-reviewed)
- [x] 2.4 SyncGoCardlessAccountJob (ShouldBeUnique per account, retryUntil 12h no $tries, 429→release(retryAfter), consent→fail+flag, timeout 280<300 worker) + gocardless:dispatch-sync everyFourHours (all users, 8h min interval, 20s stagger) + 202/sync-status endpoints + polling UX + timing chain (worker 300 / gracetime 300000 / stop_grace 330s / DB_QUEUE_RETRY_AFTER 360 / --queue=default,gocardless everywhere incl composer dev). 973+3 gate. (diff-reviewed)

## Phase 3 — Credentials + deploy

- [x] 3.1 CredentialsResolver (user override > instance env; partial override = hard error, never silent fallback) + token_secret_hash rotation (clear-before-mint) + source-aware settings UI (instance secret never exposed) + AccountController flag via resolver. GOCARDLESS_SECRET_ID/KEY env now real. 1007+3 gate. (diff-reviewed)
- FOLLOW-UP → folded into 3.3: GocardlessCredentialsCommand/GocardlessBackfillRequisitionsCommand (+status cmd if exists) still read user columns directly, misreport under instance creds
- [x] 3.2 Caddyfile rebuilt from Octane stub (worker mode preserved) + SERVER_NAME opt-in auto-HTTPS (single site block, comma addresses; healthcheck stays plain HTTP) + --caddyfile flag added (stub was silently used before). Both modes `frankenphp validate` clean. compose.prod: SERVER_NAME/TRUSTED_PROXIES passthrough. (diff-reviewed)
- [x] 3.3 spendly:check-config (APP_URL/creds/queue/debug/key checks, exit 0, --json, runs in init-app) + AppUrlDiagnostics shared w/ bank_data banner + about 'Spendly' section + CLI commands resolver-aware (credentials/status/backfill). Agent died on spend limit mid-task; finished inline (phpstan fix, tests, JSON test). 11 tests.
- [x] 3.4 Docs truth pass: SELFHOSTING_GUIDE (SERVER_NAME HTTPS, reverse proxy + TRUSTED_PROXIES, hybrid credentials, APP_URL requirement, queue/scheduler table, diagnostics section added inline), GoCardless_Architecture rewrite, AGENTS.md CLI table, .env.example. All commands/env vars cross-checked vs code.

## SESSION COMPLETE 2026-08-03 — all 4 phases done

Final gate: 1019 passed + 3 pre-existing deletion-listener failures, tsc/eslint clean, phpstan zero-new per touched file.
NOT COMMITTED (per rule). Suggested next: commit sweep (~120 files), mock-mode 2-user E2E, sandbox smoke test w/ real credentials, image build test.
Open follow-ups: ExchangeRate SQLite date-cast bug; deletion-listener pre-existing failures; EUA 180d option; sync_runs history table; mail notifications for consent expiry.

---

## Now

- [ ] Commit sweep: propose per-commit split of dirty tree (L1+L2 labeling / ML Track B core / parallel B2+recurring-v2 — confirm authorship+intent of parallel work with owner first)

## Phase — Track B continuation

- [ ] Wire auto-apply consumption in Laravel (MlSuggestionService; gates from real per-class thresholds in model manifest; opt-in via auto_apply_suggestions; audit trail + bulk revert)
- [ ] B2 pairwise transfer scorer (transfer-pairs export task ready; deterministic tiers appear implemented in parallel work — verify, then decide if scorer still needed)
- [ ] `Last Trained` nit: ml:train doesn't update MlPersonalizationSetting (RetrainMlModelJob does)
- [ ] CI job for ml/ tests (.github/ needs owner permission — spec in docs/v1.1-roadmap.md)

## Phase — Pre-existing fixes

- [ ] Rule-engine job crash on deleted transactions (3 failing tests at HEAD; task chip spawned)

## Later / nice-to-have

- [ ] Cold start (concept table + instance-global model) — single-user instance, low urgency
- [ ] getSpentForBudgets SQL GROUP BY (perf)
- [ ] Sport & Wellness class weak (1 test sample) — label a few more rows

---

# Session: Recurring + Transfer Detection Hardening (non-ML) — IN PROGRESS 2026-07-11

Plan (approved, local plan file) — tiered transfer detection (IBAN → one-sided → heuristic → cross-currency → single-leg pockets) + recurring algorithm v2 (interval quorum + k×interval missed months, price plateaus, amount clustering, confidence, fingerprint v2). NOTHING COMMITTED (per user rule). Public repo: NO personal data in code/tests/docs — synthetic names/IBANs only.

## Done (all targeted tests green)

- [x] B0 consistency: TransactionRepository::getForRecurringDetection → excludingTransfers(); TransactionBulkService::applyType validates diff-account+same-currency, writes metadata.transfer_detection {method:manual}, returns pair_blocked_reason; TransactionController bulkTypeUpdate surfaces it
- [x] B1: config/transfers.php (gap 3d, tolerance, thresholds, single_leg patterns, window padding); new app/Services/Transfers/{Iban,TransferConfig,AccountContext,CandidatePair,HeuristicScorer,PairEvaluator,PairMatcher,SingleLegDetector}; TransferDetectionService rewritten (tier1 bidirectional, tier2 one-sided w/ contradiction rule, tier3 scored ≥0.60 auto / 0.35–0.60 review+suggested_pair_id / ambiguous-twins flagged, cross-currency review-flag default via original_amount|exchange_rate|native_amount ±3%, single-leg pass runs even w/ 1 account); evidence metadata.transfer_detection on all marks; ML gate = tier≤2; sync/import triggers windowed (min booked − padding; import uses created_at >= import.created_at)
- [x] B2-3: TransferFixIncorrectCommand method-aware (skips single_leg/manual; heuristic/cross_currency only with --include-heuristic on hard violations; one_sided rechecked vs tier2; legacy → bidirectional recheck)
- [x] B4-6 import paths: TransactionDataParser classifyTransferType strong/weak (strong=contains transfer/prevod/presun/… → transfer_candidate (+single_leg_transfer_candidate if pocket pattern); weak=trvalým príkazom/platobný príkaz/topup → metadata.transfer_type_hint only); virtual partner_iban field resolved by amount sign; ImportMappingService partner_iban rule + not_conditions + Str::ascii header folding + new type rule (before category, 'type' removed from category patterns); ConfigureStep.tsx + FieldMappingService.ts parity; RevolutFieldExtractor flags vault moves single_leg_transfer_candidate
- [x] A1: config/recurring.php (intervals weekly/biweekly/monthly(25–36)/quarterly/semiannual/yearly + min_occurrences, quorum .75, max_missed 3, plateaus 3, hf guard 4/mo); RecurringGroup biweekly/semiannual consts + stats math; RecurringDetectionSetting lookback_months (default 24); migrations 2026_07_11_0000{00,01,02} (confidence/amount_current/currency; lookback+group_by data fix; fingerprint v2 backfill — logic inlined, deletes stale suggested once)
- [x] A2-4: RecurringDetectionService v2 — fitInterval (k=1..3 per gap, quorum, ranking fitted→k1→missed→nominal), segmentAmountPlateaus (price steps confirmed-by-next, monotone-levels check), clusterAmounts fallback (cluster series require k1_fraction ≥ 0.5 — anti cherry-pick), high-frequency guard w/ clean-weekly exception, effectiveMinOccurrences (yearly/semiannual=2 ignore user min), computeConfidence (0.35i+0.25a+0.20o+0.20r + dom bonus 5, min 40), currency in group key, fingerprint v2 sha256(v2|user|acct|payee|CCY|interval|cN), reconcileStaleSuggestions replaces wipe
- [x] A5 (mostly): controller orders suggested by confidence, lookback*months validation 6–48; settings/recurring.tsx lookback input + FIXED group_by values (were merchant*\_ → 422 on save, now counterparty\_\_); recurring/index.tsx confidence badge + amount_current + group.currency (was hardcoded EUR); docs/ai/RECURRING_PAYMENTS.md + landing/…/recurring-payments.md rewritten for v2
- [x] Tests added: TransferDetectionTiersTest (20), TransferFixIncorrectCommandTest (8), RecurringDetectionV2Test (20), parser/mapping/extractor unit tests, bulk-service pair-block tests. All pass + all pre-existing transfer(18)/recurring(11) tests pass. npm run types clean.

## Remaining

- [x] A5 tail: docs/ai/TRANSFER_DETECTION.md created (tiers, scoring table, config keys, evidence schema, review reasons, fix-incorrect semantics)
- [x] .env.example: TRANSFERS*\* + RECURRING*\* keys added (user approved 2026-07-11); env() wrappers added to config/recurring.php lookback/min_confidence
- [x] phpstan level 9: repo has 1923 pre-existing errors at HEAD (no baseline file — does NOT pass clean). All NEW files 0 errors; every modified file ≤ its HEAD count (net −11 vs baseline). Baseline diff method: git worktree at HEAD + docker phpstan, raw outputs in scratchpad.
- [x] pint clean (40 files), eslint/prettier/tsc clean
- [x] Full suite: 692 passed; 3 failures = documented pre-existing deletion-listener crashes (delete-all, revert-import, bulk-delete — Transaction.php:45). Fixed our 1 regression: TransactionControllerTest bulk-type pair test now uses 2 accounts + new same_account block test.
- [x] E2E scratch DB (found + fixed 5 import-pipeline bugs, see below). Final numbers (sample_data, all 5 CSVs, user 3): 2740 rows imported (1 legit zero-amount reject), 1044 transfers (65 bidirectional pairs SLSP↔SLSP via partner_iban, 27 one-sided pairs SLSP→Revolut top-ups, 860 single-leg pockets = 100% of pocket rows), review queue = 2 heuristic-band suggestions + 25 unpaired candidates, 57 recurring suggestions (Netflix/Spotify/4×Apple price clusters/USD groups/semiannual), fix-incorrect --dry-run = 0.
- [x] Full suite FINAL: 698 passed, 3 failures = documented pre-existing deletion-listener crashes only. phpstan: all touched files ≤ HEAD baseline (repo has 1923 pre-existing errors). pint/eslint/prettier/tsc clean.
- [x] E2E real dev data: backup at database/backups/pre-detection-20260711.sqlite; 3 migrations applied (kept); recurring:detect → 38 suggestions on real data (kept, visible in UI). Transfers: real rows imported with the OLD pipeline carry NO signals (0 directional IBANs, no metadata flags) → tier 1-3 cannot fire; tried fix-incorrect(1241 legacy unpaired TRANSFERs)→detect cycle, got 0 re-marks, so RESTORED transaction columns (type/pair/review/metadata) from backup via sqlite ATTACH. Dev DB now = pre-session transactions + migrations + 38 recurring suggestions.
- [ ] FOLLOW-UP (needed for transfers on existing installs): backfill command that re-derives target_iban/source_iban + transfer_candidate/type hints from stored `import_data` (raw row + headers are in every tx), then transfers:detect. Without it, old imports never gain IBAN evidence; alternative is re-import (dedup skips rows, no backfill).

## New this session (2026-07-11 pt.2)

- DateParser::detectFormatFromSamples returned 'd/m/Y' for dotted samples ('05.02.2026') → every SLSP CLI import row failed on booked_date. Fixed: separator tracked, returns d.m.Y / m.d.Y (+2 unit tests).
- TransactionDataParser::parseDate alternative-format fallback was dead code (Carbon throws, never returns false) → rewritten as per-format try loop (+1 unit test).
- ImportCsvCommand now merges missing optional fields (partner_iban, type, directional IBANs) from ImportMappingService::autoDetectMapping into the AutoDetectionService result — CLI imports previously never got partner_iban even after B5.
- METADATA CORRUPTION chain found+fixed: (1) TransactionPersister batch "Inconsistent columns" check threw whenever rows had differing optional keys (empty IBAN cells) → whole batch fell into per-row fallback; now columns are normalized (union+null-fill). (2) Fallback createOne(prepareForInsert(...)) double-encoded metadata/import_data (pre-encoded string + Eloquent json cast) → new prepareForModel() keeps arrays. (3) MlSuggestionService `(array) $tx->metadata` wrapped corrupt string metadata into `["{...}"]` on every annotate → now decodes-if-string (self-heals). Regression test: ImportCsvCommandTest::maps_partner_iban_and_survives_mixed_optional_columns (fixture tests/fixtures/import_partner_iban_mixed.csv, synthetic).
- phpunit.xml: ML_ENABLED=false forced — suite previously made live HTTP calls to ml-service when dev .env enabled it.
- config/transfers.php single_leg.patterns += 'pocket withdrawal' (22 Revolut rows per sample export).
- Gotcha: `$result = $this->artisan(...)` defers execution to destruct — call `->run()` before DB assertions (bit us once).

## Gotchas

- Tests: MUST use `docker compose run --rm test php artisan test` (cli service hits locked dev sqlite — `database is locked`). No local php.
- Pre-existing failure (also on clean main): TransactionBulkServiceTest::test_delete_all_clears_partner_pair_and_detaches_tags (TransactionDeleted listener ModelNotFoundException) — NOT ours.
- RevolutFieldExtractorTest new tests may skip in container (fixture-gated setUp).
- Recurring tests pin Carbon::setTestNow('2026-07-01') — confidence recency depends on it.
- Cross-currency test fixture has no FX metadata → legacy `does_not_pair_cross_currency` still green unchanged.

# Session: ML Live in Dev + B0 Eval Harness + B1 Core — DONE 2026-07-11

- [x] P0: ml-service live on spendly_default (rw DB mount), ML_ENABLED in .env, `ml:annotate` command, suggestions on all rows
- [x] B0: evaluation.py (temporal holdout + CV per-class thresholds), metrics history JSON, GET /api/v1/models/categorizer/metrics, MlService::getCategorizerMetrics, Model Quality panel in settings/ml_engine.tsx, export upgrades (booked_date/account_id + transfer-pairs task), ml/scripts/backtest.py
- [x] B1 core: merchant-normalized token (clean_text/normalize_merchant), SGD→LogisticRegression, partial_train deleted, per-class needs_review thresholds + auto_apply flag in categorize response, 0.3 gate removed
- [x] Measured (temporal holdout, 1186-row export): SGD 92.9% acc / 91.7 macro-F1 → LR 95.4% acc / 95.7 macro-F1; live model v3 94.7% acc on full data
- [x] Privacy: ml/.gitignore hardened (data/\* blanket), database/backups/ snapshots
- [!] INCIDENT: live DB wiped mid-session (cause unproven — marker tests cleared ml:train + app restart); fully restored deterministically from captured label decisions + saved classification; backup snapshot now in database/backups/
- [ ] Follow-up: `Last Trained` in settings reads MlPersonalizationSetting — CLI train doesn't update it (RetrainMlModelJob does); wire ml:train to touch it or read manifest
- [ ] Next: auto-apply consumption in Laravel (gates on real metrics), B2 transfer scorer (transfer-pairs export ready), commit sweep (tree holds L1+L2 + Track B core, all uncommitted per user choice)

# Session: Labeling Pipeline Rebuild (L1+L2) — DONE 2026-07-10

Goal: per-user custom labels (each user own categories/tags/counterparties) via admin labeling UI, then relabel data, then Track B0 eval harness. Exploration findings: 14 gaps (see session notes / plan file).

## L1 — per-user scoping + correctness (DONE 2026-07-10)

- [x] Scope lookups per user: `getCategories/getCounterparties/getTags` accept `user_id` (SuperAdminController.php:297-322); frontend re-fetches on user-filter change (index.tsx:38-58 currently mount-only)
- [x] Ownership validation on writes: category_id/counterparty_id/tags must belong to transaction owner (UpdateTransactionLabelRequest, BulkLabelRequest — currently bare `exists:`; reuse app/Rules/OwnedByUser)
- [x] Fix row display: TransactionLabelingResource missing top-level category_id/counterparty_id → selects never show existing labels, optimistic value reverts after PATCH_SUCCESS
- [x] Inline category create: derive owner from transaction owner, not user filter (currently defaults to admin's own user when no filter — CategoryController.php:29-31)
- [x] `is_transfer` on single update (missing from UpdateTransactionLabelRequest rules; expanded-panel switch silently ignored)

## L2 — throughput (DONE 2026-07-10)

- [x] Counterparty create endpoint + inline create with type (merchant/employer/person/institution); CounterpartyInlineSelect handleAddNew currently no-op
- [x] Tag create endpoint + inline; implement bulk `labels.tags` server-side (validated but ignored in bulkLabel loop)
- [x] Real merchant-group bulk: implement `similar_group_key` server-side (validated, never used), wire "xN similar" badge (empty onClick), cross-page group labeling
- [x] Labeling queue: uncategorized grouped by normalized merchant, largest group first (= Track B1 labeling queue)
- [x] Send `user_id` on bulk from UI (server guard currently unexercised)

## Deferred to L3 (not this session)

- Suppress target user's rule engine on admin label edits (keep ML counter); recurring-group creation from UI; review_reason; per-user category seeding (14-cat taxonomy from auto_label script). Note: applyUserFilter perf fix (whereHas) already done in L1.

## Then

- [ ] Relabel data: import CSVs + improved UI + auto_label script
- [ ] Track B0: eval harness (temporal holdout, metrics history, endpoint + settings UI)

# Previous Session: ML Service Hardening (Track A) — 2026-07-10

Strategy decided: classical ML (sklearn), CPU-only Docker; MLX/ml-intern track PARKED (see docs/ML_INTERN.md header). Full plan: docs/v1.1-roadmap.md ML section.

- [x] A0: Remove EntityBehavior trait (getUserId TypeError release blocker); fix 3 SuperAdminControllerTest test bugs
- [x] A1: Fix broken SQL in ml/app/core/database.py (train/recurring/transfers 500'd since inception); datetime coercion; asyncpg dep; months_lookback param
- [x] A2: Bearer auth (ML_SERVICE_TOKEN, fail-closed 503); CORS removed; ml-service ports→expose; ml/.env deleted + .env.example added; Laravel withToken
- [x] A3: ML_DATA_DIR settings-driven paths; ML_API_URL→ML_SERVICE_URL (compose.prod.yml); queue-worker service in compose.ml.yml
- [x] A4: Deleted Celery scaffolding, legacy unversioned routers, docker-compose.ml.yml; ml_data volume for model persistence
- [x] A5: Python test suite (ml/tests/, 34 tests: unit + API contract + schema-drift guard); pyproject.toml; ruff + mypy clean
- [x] A6: MlServiceTest bearer assertion; docs truth pass (ML_SERVICE.md, ml/README.md, ML_INTERN.md parked, NEXT_STEPS.md retired, v1.1-roadmap ML section rewritten)
- [ ] Follow-up: CI job for ml/ tests (.github/ needs permission — spec in roadmap)
- [ ] Follow-up: rule-engine job crash on deleted transactions (3 failing tests at HEAD, spawned as separate task)
- [ ] Track B (next): eval harness → categorizer upgrade + cold start + auto-apply → transfer tiers + pairwise scorer → recurring consolidation

# Previous Session: ML-Intern Local Setup

- [x] Inspect current ml-intern config, scripts, tasks, docs, and cleanup scope
- [x] Implement local-only Kimi K2.6 config updates
- [x] Remove dataset Hub push flow and keep local export/prepare only
- [x] Rewrite ml-intern task prompts for local MLX training
- [x] Rewrite docs for Spendly local labeling/export/train workflow
- [x] Update ignore rules and verify changed paths/commands

# Current Session: Counterparty Cleanup + ML Export Hardening

- [ ] Review current cleanup/export pipeline and lock label policy
- [ ] Implement manifest-driven user-2 counterparty cleanup with role splits
- [ ] Re-run cleanup and regenerate review artifacts + ML datasets
- [ ] Harden partner ML export for role-aware filtered labels
- [ ] Validate final labels, iterate on noisy rows, and prep ml-intern datasets

# Production Release Review

## Critical (Must Fix)

### Security

- [x] **TransactionController missing `declare(strict_types=1)`** — fixed
- [x] **Error messages leak internals** — fixed: GoCardless controllers now return generic messages
- [x] **GoCardless credentials stored unencrypted** — Already handled: User model has `encrypted` cast on all 4 GoCardless fields. `GocardlessEncryptCredentialsCommand` exists for migrating legacy plaintext data. Frontend no longer receives raw credentials (masked display only).
- [x] **Routes outside auth middleware** — fixed: moved `/transactions/filter` and `/transactions/load-more` inside auth group
- [x] **`updateTransaction` has no authorization check** — fixed: added ownership check via `account.user_id`

### Null Safety / Runtime Crashes

- [x] **`Auth::user()->accounts()` can crash** — fixed: added `@var` annotation with typed user
- [x] **`Carbon::createFromFormat` can return null** — fixed: added null guards in AnalyticsController
- [x] **Missing null check on `$first`/`$second`** — TransactionController:582-583. Will be addressed when TxCtrl is extracted into summary service in v1.1 (refactor deferred per release-candidate plan).

## High Priority

### Backend Quality

- [ ] **TransactionController is 1000+ lines** — Extract filter logic, bulk operations, and summary calculations into separate services. **Deferred to v1.1** (see `docs/v1.1-roadmap.md`).
- [x] **N+1 query in BudgetService::getSuggestedAmounts** — Already mitigated: per-group transaction lookup pre-fetched in a single `whereIn(...)` (BudgetService.php:321-332).
- [x] **N+1 in BudgetService::getSpentForPeriod** — Fixed: new `getSpentForBudgets()` performs a single transactions fetch + in-memory aggregation. Regression covered by `BudgetServiceTest::test_get_budgets_with_progress_does_not_scale_query_count`.
- [ ] **BudgetController duplicated serialization code** — `index()` and `builder()` both map categories/tags/counterparties/recurringGroups/accounts identically. Extract shared method.
- [x] **Missing exchange rate fetch scheduler** — fixed: added `exchange-rates:fetch` daily at 06:00
- [x] **GoCardlessRequisitionController:358 missing validation** — fixed: added `$request->validate()` and `$request->input()`

### Frontend / TypeScript

- [x] **TypeScript errors fixed:**
    - [x] `rules/index.tsx` — replaced inline type with `RuleOptionsResponse['data']`
    - [x] `review.tsx` — removed invalid breadcrumbs prop, added `review_reason`/`needs_manual_review` to Transaction type
    - [x] `form-inputs/index.ts` — removed dead re-exports of non-existent modules
- [x] **Remaining TS errors (5, pre-existing)** — verified clean: `npm run types` exits 0 after `@types/jest` install + DataTable.test.tsx generic narrowing.
- [x] **ESLint scanning `venv/`/`ml/`/`landing/`** — fixed: added to eslint ignores (83K→72 errors)
- [ ] **Remaining ESLint (72, pre-existing):** unused vars in `failures.tsx`, `any` types in type files, missing effect deps
- [x] **`filters` unused in review.tsx** — fixed: removed

### Docker / Infrastructure

- [ ] **`compose.yml` `cli` container** — No database volume persistence for production
- [ ] **Orphan containers piling up** — Use `--rm` flag
- [ ] **`public/frankenphp-worker.php`** — Untracked, needs commit or .gitignore

## Medium Priority

### Backend

- [x] **BudgetService::computePeriodEnd bug** — fixed: `startOfDay()` → `endOfDay()`
- [ ] **PHPStan errors in changed files (187 total)** — **Deferred to v1.1** (see `docs/v1.1-roadmap.md`). Acceptable for rc.1 since errors are type-annotation gaps, not bugs.
- [ ] **`BackfillNativeAmountsCommand` does individual UPDATEs** — Use batch update for performance. **Deferred to v1.1.**
- [x] **ExchangeRateService calls external API during requests** — Already hardened: `ensureRatesExist()` is read-only in request paths; daily scheduler + `MigrationsEnded` warmup listener handle ingestion.

### Testing

- [x] **GoCardless Settings controllers have no feature tests** — Resolved: dedicated tests exist (`tests/Feature/Settings/GoCardless*ControllerTest.php`, 670 LOC across 3 files).
- [x] **`markTestIncomplete` in RuleEngineTest** — Verified absent.
- [x] **AnalyticsController has no tests** — Resolved: `AnalyticsControllerTest.php` added in earlier commit.
- [x] **TransactionController bulk operations need auth edge-case tests** — Will be re-asserted in v1.1 once TxCtrl is extracted into TransactionBulkService.

## Low Priority / Polish

- [ ] Remove `console.error` calls in UI components, use toast notifications
- [ ] Add index on `exchange_rates(base_currency, target_currency, date)` if not exists
- [ ] Add rate limiting to GoCardless API proxy endpoints
- [ ] Consider CSRF protection for JSON API routes under `/api/bank-data/`

# Finance Subsystem Release-Hardening (Budgets/Categories/Merchants/Import/Recurring)

> Full handoff in `CURRENT_STATE.md`. Plan: `~/.claude/plans/act-as-senior-software-imperative-pelican.md`.
> Validate with: `docker run --rm -v "$PWD":/app -w /app ghcr.io/andrejvysny/php-cli:8.3 php artisan test --filter=…` (targeted, not full suite — it OOMs on pre-existing failures).

## P0 — Critical (DONE, validated)

- [x] **F1 Budgets cross-currency** — sum `native_amount`, convert to budget currency (`BudgetService`)
- [x] **F2 Budgets net refunds** — scoped targets net, account/overall gross (`budgetNetsRefunds`)
- [x] **F3 Transfer double-count** — `Transaction::scopeExcludingTransfers()` / `isTransfer()` everywhere
- [x] **F4 Budget last-day undercount** — `getSpentForPeriod` inclusive of full end day
- [x] **F5 Orphaned-category budget** — null category_id matches nothing + `is_orphaned`
- [x] **Authz/IDOR cluster** — `App\Rules\OwnedByUser` across budget/category/counterparty/transaction/import-failure requests; `TransactionController::update` ownership guard (was open IDOR); `TransactionBulkService::dropForeignTargets`; recurring `confirmGroup` scoping
- [x] **Category cycle prevention** — `CategoryController::assertNoCycle`
- [x] **Multi-currency analytics/dashboard/summaries** — transfer-pair exclusion + native_amount flag passthrough

## P1 — Data integrity (DONE, validated)

- [x] **D1** recursive `getAllDescendantIds` + cycle guard
- [x] **D2** clear stale SUGGESTED recurring groups before re-detect (idempotent)
- [x] **D3** recurring `next_expected_payment` calendar-accurate
- [x] **D4** counterparty dedup: `normalized_name` + unique index migration + normalized matching
- [x] **D5** verified already correct (real pipeline rejects missing amount)
- [x] **D6** import: no 1:1 fabrication when FX rate missing → null + `needs_manual_review`
- [x] **D7** orphaned transfer-leg flagging on delete/reclassify (re-pair guard already present)
- [x] **D8** budget-period race made resilient (unique constraint already existed)

## P2 — Polish/migrations (DONE)

- [x] Migration: backfill same-currency `native_amount`; deactivate orphaned category budgets
- [x] CSV formula-injection neutralization on import-failure export (`csvSafe`)

## REMAINING (next session)

- [ ] **getSpentForBudgets → SQL GROUP BY** (perf only; in-memory path is correct) — `BudgetService`
- [ ] **RecurringGroup stats sign** — flip signed `total_paid`/`average_amount`/`projected_yearly_cost` to positive cost; needs coordinated `resources/js` change (UpcomingRecurringCard, recurring page)
- [ ] **BLOCKER (pre-existing): `EntityBehavior::getUserId(): int` returns null → TypeError** — breaks `SuperAdminControllerTest` + `TrackManualCategorizationTest`, OOMs full test run. `app/Traits/EntityBehavior.php` + `Account/Category::getUserId()`
- [x] ~~**BLOCKER: rule engine reloads deleted transaction → `ModelNotFoundException`**~~ — fixed: `TransactionDeleted` no longer uses `SerializesModels`, and `ActionExecutor` refuses to act on a model that no longer exists (every action ends in `save()`, which resurrected the row).

## Rule-engine tests predating the current API

`tests/{Unit,Feature}/RuleEngine/` (5 classes, 104 methods) were written with `it_*`
method names but no `#[Test]` attribute, so PHPUnit never collected any of them —
they had been silently dead since they were written. Adding the attribute (and
wiring `newFactory()` on the `App\Models\RuleEngine` models, whose factories live in
`Database\Factories\` rather than the `RuleEngine\` sub-namespace Laravel looks for)
brought 74 of them to life.

The remaining **30 are marked `markTestSkipped`** and need porting to the current API:

- `ActionExecutorTest` — 23. Most assert against methods that have since been renamed
  or removed (e.g. `RuleAction::getActionTypes()`).
- `EventDrivenRulesTest` — 3, `MigrationTest` — 2, `ConditionEvaluatorTest` — 1,
  `RuleApiTest` — 1.

Each carries a `markTestSkipped` explaining why. Delete the skip and fix the assertion.
