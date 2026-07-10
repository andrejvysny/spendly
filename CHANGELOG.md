# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-rc.1] - 2026-05-23

First release candidate. ~1 week soak window for self-hosting feedback before tagging `1.0.0`.

### Added

- **GoCardless** bank sync (production + mock modes, sandbox-tested, encrypted credentials, masked UI display).
- **Multi-currency analytics** via ECB rates (Frankfurter API, daily scheduled fetch, on-migrate warmup).
- **Rule Engine** — conditional/action pipeline with 12+ operators, 17 action types, audit log, async job processing, priority ordering, stop conditions.
- **Budgets** — category / tag / counterparty / subscription / account / overall targets, rollover (capped or unlimited), auto-create periods, pace projection, trend charts.
- **CSV Import Wizard** — Revolut + SLSP templates, field auto-mapping, deduplication via SHA256 fingerprint, per-row failure tracking.
- **Recurring detection** — pattern matching, dismissed suggestions, per-account vs global scope, budget suggestions from confirmed groups.
- **Transfer detection** — rule-based + ML fallback across accounts.
- **Admin labeling UI** (superadmin role) — bulk transaction labeling with ML suggestion overlay, inline category creation, filtering, reducer-driven state.
- **FrankenPHP** production runtime via s6-overlay supervised worker + queue.
- **Repository pattern** — Phases 1–5 complete (22 interfaces, batch insert + user-scoped + ordering concerns).
- **CI security workflow** — automated dependency scan + CodeQL + Trivy secret/Docker scans on PRs.

### Experimental / opt-in

- **ML categorization, merchant extraction, transfer detection, recurring detection** via standalone Python FastAPI service (`ml/`). Default scikit-learn model ships globally. Per-user opt-in via `MlPersonalizationSetting`. Manual categorizations tracked for future retraining.
- **ml-intern** local agent workflow — scaffolding ready for local MLX training of per-user models (post-v1.1).

### Known limitations

- ML categorization quality varies by region/language (default model trained on generic data).
- ml-intern training pipeline scaffolded but **no models trained yet** — wired for v1.1.
- Test suite has 7 pre-existing intermittent failures from SQLite `:memory:` lock contention; individual runs pass.
- `TransactionController` (1073 LOC) still monolithic — refactor deferred to v1.1.

### Security

- All GoCardless credentials encrypted at rest (4 fields on User model).
- All user-facing resources scoped via `BelongsToUser` trait.
- `SuperAdminMiddleware` gates `/admin/*` routes.
- Transaction ownership enforced server-side on updates and bulk operations.

### Notes

- License: GPL-3.0.
- PHP 8.3+ required.
- See `SELFHOSTING_GUIDE.md` for deployment.
