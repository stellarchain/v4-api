# Investigator first slice — local development notes

This work is separate from the forwardfill/statistics repair package and remains unpublished.

Existing API: `GET /v1/payment-flow/investigation`. Additive optional `cursor` and `operationType` (payment, create_account, account_merge, path_payment_strict_send/receive). Existing direction/ledger bounds/limit remain. Invalid ledger strings/overflow and mismatched cursor filters return 400.

Keyset order is descending `(ledger,id)`; both-direction queries merge bounded indexed walks without duplicate self-transfers. Cursor binds filters and upper ledger anchor, not a durable DB snapshot. Controller requests use an 8s per-statement timeout in a read-only transaction; unavailable data returns 503.

Coverage exposes `scope=page`, `nextCursor`, `latestObservedLedger`, `latestObservedClosedAt`, `completeHistoryVerified=false` and a limitations note. Observed latest ledger is **not** proof of gap-free indexing. Frontend retains graph/context and adds URL filters/pagination, page-grouped transaction evidence, exact-string JSON/CSV page exports, cancellation, retry and clear. A transaction can span pages.

Not part of this slice: asset/contract search, multihop expansion, saved cases, watchlists, full-history certification or full-dataset export.

## Current local verification (2026-09-29)

- The API now preserves source/destination amounts as decimal strings and sums XLM page totals with BCMath at the database's `NUMERIC(36,14)` scale. The regression covers values beyond floating-point precision and a one-unit difference at the 14th decimal place.
- Focused read-service tests: 3 tests / 24 assertions. Controller tests: 7 tests / 17 assertions. PHP syntax and `git diff --check` pass.
- Frontend export tests (Node 22): 3 passed. TypeScript, changed-file ESLint, scoped design audit and the Next.js 16 production static build with webpack pass. The build reports existing Sentry instrumentation warnings.
- Both real-PostgreSQL integration tests skipped because the named disposable test container is absent. No database container was created. Interactive browser QA is still outstanding because no browser surface was available; a static build does not prove runtime filter, pagination or download behavior.

## Previous combined workspace verification (2026-09-28)

These results were recorded before splitting the delivery packages; they are not a fresh verification of a deployed release.

- Targeted backend regression: **67 tests / 286 assertions**, including real PostgreSQL 14 SQL preview/apply/rollback on disposable synthetic data. No application dotenv or remote DB is loaded by those integration tests. Horizon calls in runner tests are stubs; real archive ingestion still needs the approved pilot.
- Frontend: 3 export regression tests, TypeScript, changed-file ESLint and production static build passed. JSON/CSV preserve exact textual IDs and amounts; import CSV identifiers/decimals as text in spreadsheet software to avoid its automatic numeric rounding.
- Scoped Investigator design audit: zero findings. DESIGN.md lint: zero errors, two documentation-only unused-token warnings. The whole legacy UI audit has unrelated findings and is not certified by this slice. Existing appearance/component owners are preserved; standards-based scrollbar support was added to the shared CSS baseline.
- Local HTTP smoke returned 200. **Interactive browser/visual QA was unavailable** (no enabled browser/native-app surface), so responsive/theme/keyboard/download/stale-request UI scenarios remain manual before release. Static audit and build do not prove those runtime behaviors.
- Disposable PostgreSQL and local development server were stopped after those checks. No commit, push, deployment or remote mutation had been performed during that verification.
