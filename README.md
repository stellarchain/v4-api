# StellarChain API

Symfony 8 + API Platform project for StellarChain data (accounts + metrics).

Forward-only history ingestion and preview-first SQL repairs: [operational runbook](docs/forwardfill.md). Deployment and remote writes are separate approval gates.

Q3 bounded API compatibility, Investigator V2 and historical metric read-model gates: [Q3 read models](docs/q3-read-model-gates.md).

Partner discovery, protected web administration and aggregate click CSV reports: [partner runbook](docs/partners.md).

## Orion account reads

`GET /v1/accounts/{address}` reads mainnet state from Orion, with Horizon state
fallback. Its existing local labels, verification and metrics remain available;
`stellarData.horizon` contains the Horizon-shaped current state and
`stellarData.source` identifies its provider. The GET returns detached account and
metric objects and does not persist, flush or register missing local accounts.
The explicit synchronization method retains its existing write behavior.

`GET /v1/accounts/{address}/{transactions|operations|effects}` proxies bounded
history reads through the API without requiring a local/current account record.
It accepts `network`, `limit` (1..200), `order`, `cursor`, and `include_failed`
for transaction/operation reads. Responses contain `records` plus `meta` with
provider, network, continuation cursor and `hasMore`. Mainnet uses Orion;
testnet/futurenet use their configured Horizon endpoints. History failures return
503, not an empty successful history. These endpoints do not write to the DB.

`ORION_ACCOUNT_READS_ENABLED` defaults to `1`; set `0` to use Horizon for account
reads. `ORION_BASE_URL` is shared with contract reads. Account calls have a
2-second inactivity timeout, 5-second deadline, 8 MiB response ceiling and no
redirects or automatic retry loop. Deploy this backend before the account UI that
consumes `stellarData.horizon` and the new history endpoints.

A 404 current-state response does not establish that an address has no history.
The frontend displays a separate history view without fabricated balances. For
the address supplied on 2026-10-27, both state providers returned 404; Orion's
transaction history returned 503 (resource limit), and operations returned 500.
The integration preserves these upstream limitations as visible retryable errors.

Focused tests:

```bash
php vendor/bin/simple-phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Service/AccountReadGatewayTest.php
php vendor/bin/simple-phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/DataProvider/AccountItemReadOnlyTest.php
```

## Orion contract reads

`GET /v1/contracts/{contractId}/wasm?network=mainnet` downloads the compiled
WebAssembly binary through the backend, including contracts absent from the local
DB. It resolves the current Orion contract executable, reads `/code`, strictly
decodes `wasm_base64`, checks the module header/size, and verifies SHA-256 against
both the code response and the current contract hash. Mismatches fail with 503;
missing/non-WASM contracts and unsupported networks return 404. The response uses
`application/wasm`, an attachment filename, a hash ETag and mandatory cache
revalidation so upgrades do not reuse stale binaries. No DB write is performed.
This binary download does not change source-code/build-verification status.

The browser continues to use `/v1/contracts/{contractId}` and its `/events`,
`/transactions`, and `/storage` subresources. The API reads Orion on mainnet,
including contracts absent from the local database. These are read-only requests;
no contract is inserted or synchronized into the database. `/v1/contracts` listing,
local verified/source metadata, balances, activity and argument-usage indexes stay
on their existing providers. Testnet and futurenet keep their existing sources.

- `ORION_BASE_URL`: backend-only upstream URL, default `https://pubnet.stellarchain.io`.
- `ORION_CONTRACT_READS_ENABLED`: default `1`; set `0` to use existing DB/RPC reads.
- HTTP reads have a 2-second inactivity timeout, a 3-second per-call deadline,
  a 4 MiB response ceiling, and no redirects. Upstream failures, incompatible
  schemas and identity mismatches fall back to local reads on the first page.
  Continuation failures return 503 rather than mixing upstream and local pages.
- A local WASM hash mismatch rejects the upstream detail enrichment, preserving
  locally stored verification/source provenance. Counts retain the larger known
  local/upstream value; neither counts nor empty lists establish full coverage.
- Orion `first_seen_at` is exposed as `firstSeenAt`, not as a proven deployment
  date. `functions`, `archived`, and `chainDataSource` are additive detail fields.
- Transaction pages are accepted only with envelope evidence of a direct call or
  a contract-data footprint reference. `invocationsOnly=true` requires a direct
  call. The currently observed unrelated DEX transactions are rejected; the API
  falls back to its local history when available. Nested calls without direct
  envelope evidence remain on local history until upstream association is verified.
- Collections retain camelCase fields and `meta.hasMore`/`meta.nextCursor`.
  Orion records have `id: null` and an additive `upstreamId`; they do not acquire
  fabricated local DB identifiers. Storage exposes separate key/value ScVals as
  `entryDecoded.key`, `entryDecoded.valueXdr`, and `entryDecoded.durability` when
  no complete ledger-entry XDR is supplied.
- Opaque Orion cursors bind contract, resource, page size and invocation mode.
  They cannot be combined with local `beforeId`, ledger or transaction filters.
  Existing local filters/cursors keep their DB semantics. Page-number clients
  use 30-second cached continuation positions to avoid repeated prefix scans;
  an uncached jump requiring more than 10 upstream pages returns 503. A request
  also has a 10-second pagination budget. Prefer `meta.nextCursor` for deep history.
- Orion's currently unpaginated `/data` response is sliced locally for numeric
  storage pages, with exact `meta.totalItems` and `meta.hasMore`.

On-demand WASM decompilation:

- Opening the WASM tab calls `POST /v1/contracts/{id}/decompile?network=mainnet`
  with JSON `{ "wasmHash": "<hash shown on the contract page>" }`. The backend
  retrieves the WASM from Orion and verifies its SHA-256 and current executable
  before running the same Soroban Auditor used by `app:soroban:decompile-contract`.
- Returns `wasmHash`, `sourceCode`, `type: decompiled`, and `cached`. This output is
  reconstructed code, not a source/build attestation. Existing indexed code is
  displayed without starting another decompilation.
- The process has a 20-second timeout and a 2 MiB input/output limit. Results are
  cached for 24 hours by WASM hash (failures for 30 seconds), without writing to
  the contracts DB. A nonblocking file lock permits one on-demand decompiler per
  API instance; concurrent requests receive 429 with Retry-After. The browser
  retries briefly while showing Decompiling, then offers a manual retry.
- Deploy with `composer install --no-dev` to include `symfony/process`, and ensure
  the existing platform-specific `bin/soroban-auditor-*` binary is executable.
  `SOROBAN_AUDITOR_BINARY` and `SOROBAN_AUDITOR_TMP_DIR` remain supported. PHP's
  process execution must be enabled; proxy/PHP request timeouts must exceed the
  process timeout plus the Orion retrieval budget (at least 30 seconds).
- SACs expose their asset identity, XDR-decoded native metadata and function
  interface. Asset identity is checked against the derived mainnet SAC address.
  SACs run inside Stellar's host and have no WASM to download or decompile.

Focused checks (no database or network required):

```bash
php vendor/bin/simple-phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Service/ContractRead
php vendor/bin/simple-phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/DataProvider/OrionContractProvidersTest.php
```

## Implemented

- API Platform with `/v1` docs and `/v1/accounts` collection.
- `Account` entity (address as API identifier, label, verified, timestamps).
- `AccountMetric` entity (one-to-one with Account) with:
  - `totalTransactions` (BIGINT)
  - `nativeBalance` (DECIMAL 36,7)
- `Asset` entity keyed by the `assetKey` string (format `SYMBOL-ISSUER` and, when provided by Stellar Expert, `SYMBOL-ISSUER-TYPE`). ApiPlatform now exposes `/v1/assets/{assetKey}` just like `/v1/accounts/{address}`; calling the collection with `?assetKey=` returns the matching asset stats. The stored `assetKey` should reuse exactly the `asset` value from Stellar Expert including any numeric suffix (e.g. `-2`) so every variant stays unique and predictable.
- Market API resource (custom endpoint):
  - `GET /v1/market/assets`
  - query params: `network` (`testnet|mainnet|futurenet`, default `testnet`), `limit` (max 200), `cursor` (`rank:id`)
  - response: `meta` + `market[]` (rank, score, price_xlm, price_change_1h/24h/7d, volume_xlm_24h, trades_24h, trustlines_total, supply)
  - optimized for refresh: keyset pagination + `ETag` + short cache-control
- Network metrics API resource:
  - `GET /v1/network-metrics`
  - query params: `network`, `metricKey`, `bucketMinutes`, `page`, `itemsPerPage`, required `windowDays` (1–30), optional bucket-aligned UTC `before`
  - response: paginated time-series rows and UTC window metadata; page totals are limited to that window
  - compatibility note: requests without `windowDays` now return `422 window_required`; update API clients before deploying this change. An implicit unbounded fallback is intentionally unavailable.
  - `max-transaction-fee` is collected as an additive V2 key only after the new extractor is deployed; it remains unavailable in the public API until source coverage is verified. Existing `max-fee` rows and meaning are unchanged.
- Investigator payment-flow API:
  - `GET /v1/payment-flow/investigation` returns page-scoped classic payment-flow evidence for an account, transaction hash, or exact asset key. Asset-only reads require the separately installed/populated `payment_flow_asset_side` model; indexed-history completeness is not verified.
  - `depth=2` is available only for account targets and requires either both `ledgerFrom`/`ledgerTo` or both `dateFrom`/`dateTo`. It returns bounded candidate paths with explicit fan-out/truncation metadata; it is not proof of funds continuity or account control.
  - `riskContext.score` and `riskContext.level` are legacy, page-dependent heuristics retained for response compatibility. Do not present them as account-level risk ratings or fraud/safety verdicts; use `signals`, `limitations`, and `coverage` as qualified evidence instead.
- Statistics overview:
  - `GET /v1/statistics/network` omits `active-addresses` from range cards because averaging five-minute distinct source counts cannot produce period-unique active accounts. The five-minute series remains available through `/v1/network-metrics`.
  - Contract creation/invocation cards count indexed detail-text matches, not verified complete contract activity.
- Filtering:
  - `label` partial
  - `address` exact
- Sorting:
  - `order[accountMetric.totalTransactions]`
  - `order[accountMetric.nativeBalance]`
- Import commands:
  - `app:import-known-accounts` from `resources/known_accounts.csv`
  - `app:import-account-metrics` from `resources/known_accounts_with_balance_and_transactions.csv`
  - `app:market:sync-snapshots` (reads Horizon DB with direct `SELECT` queries and persists local market snapshots)
  - `app:horizon:sync-network-metrics` (reads the currently ingested Horizon DB chunk and persists paginated network metric points)
  - `app:horizon:sync-payment-flow-events` (extracts compact payment/create/merge flow events from a Horizon DB chunk)
  - `app:horizon:sync-asset-market-history` (extracts complete asset/XLM market buckets; historical asset-state snapshots are disabled until a historical state source is available)
  - `app:horizon:sync-account-activity-summary` (extracts compact account activity summaries from a Horizon DB chunk)
  - `app:statistics:init-schema` (creates the historical statistics storage tables on the configured statistics database)
  - `app:directory:sync-stellar-expert` (imports missing Stellar Expert directory accounts and only updates existing accounts when Stellar Expert marks them `malicious`)
- API docs UI tweaks (logo/header removed, top margin removed, footer hidden).
- PHP extensions enabled: `bcmath`, `pcntl`, `gmp`, `pdo_mysql`, `intl`, `opcache`, `zip`, `apcu`.

## Quick Start

1. Build containers:
   ```bash
   docker compose build --no-cache php
   docker compose up -d
   ```
2. Run migrations:
   ```bash
   docker compose exec php bin/console doctrine:migrations:migrate
   ```
3. Import data:
   ```bash
   docker compose exec php bin/console app:import-known-accounts
   docker compose exec php bin/console app:import-account-metrics
   docker compose exec php php bin/console app:statistics:init-schema --no-debug
   docker compose exec php php bin/console app:market:sync-snapshots --network=testnet --top=1000 --no-debug
   docker compose exec php php bin/console app:horizon:sync-network-metrics --network=testnet --bucket-minutes=10 --no-debug
   docker compose exec php php bin/console app:directory:sync-stellar-expert --network=mainnet --no-debug
   ```
4. Historical unattended backfill:
   ```bash
   NETWORK=testnet \
   LATEST_LEDGER=12345678 \
   DATABASE_HORIZON_URL=postgresql://history-host/horizon_history \
   DATABASE_STATISTICS_URL=postgresql://history-host/horizon_statistics \
   bin/horizon-history-backfill.sh
   ```
4. Open API docs:
   - `https://api.stellarchain.dev/v1`
   - `https://api.stellarchain.dev/v1/accounts`
- `https://api.stellarchain.dev/v1/market/assets?network=testnet&limit=50`
- `https://api.stellarchain.dev/v1/network-metrics?network=testnet&metricKey=transactions&bucketMinutes=10&windowDays=30`

## Statistics Database

- `DATABASE_STATISTICS_URL` controls where `app:horizon:sync-network-metrics` and `app:horizon:sync-payment-flow-events` write historical rows.
- `DATABASE_HORIZON_URL` is the isolated Horizon database reingested and read by the historical worker.
- Both variables are required. Inside the worker, `DATABASE_URL` and `DATABASE_CONTRACTS_URL` are replaced with `DATABASE_STATISTICS_URL`, so the operational database is not reachable as a write target.
- Historical statistics backfill always disables contract scanning, CoinGecko warming, market snapshots, market overview and local application resets.
- Contract history uses a separate pipeline and `DATABASE_CONTRACTS_URL`; it is not part of `horizon-history-backfill.sh`.
- The DigitalOcean operational database is updated only by the live cron commands (`app:warm-coingecko-cache`, `app:market:sync-snapshots`, `app:horizon:sync-market-overview`) reading the current mainnet Horizon database.
- Run `app:statistics:init-schema` against the dedicated statistics database before starting the backfill.
- `RUN_PAYMENT_FLOW_EVENTS=1` can be added to `bin/horizon-history-backfill.sh` to preserve direct payment/path-payment/create-account/account-merge flow events before Horizon history tables are truncated.
- `RUN_ASSET_MARKET_HISTORY=1` preserves per-asset XLM market buckets, not historical supply/holder snapshots.
- `RUN_ACCOUNT_ACTIVITY_SUMMARY=1` preserves compact per-range account summaries for later account ranking/statistics imports.

### Historical statistics correctness

- Account activity dates come from `history_ledgers.closed_at`, never transaction ingestion timestamps. Missing ledger timestamps fail the sync before writing summaries.
- Explicit network/market ranges expand to the complete UTC time buckets intersecting the requested ledgers. A preceding and following ledger must prove the time boundaries, and the ledger sequence must be contiguous. Genesis history starting at ledger 2 is supported. Missing ranges or insufficient context fail rather than reporting a successful partial sync.
- `bin/horizon-range-stats.sh` ingests `HORIZON_CONTEXT_LEDGERS=1000` extra ledgers on each side when network or asset-market metrics are enabled. Account summaries and payment events still use the original requested range. Metric command output reports the expanded ledger range.
- The context size is a starting allowance, not proof of completeness. If validation fails, increase context and retry the same chunk. The padded end must already be available in the history source; do not use this historical worker as a live follower.
- Upserts replace complete bucket values; they do not increment partial totals. Adjacent chunks and retries therefore preserve counts, distinct active accounts and market volumes. Average ledger duration includes the predecessor ledger, and minute trade aggregates use a half-open `[bucket_start, bucket_end)` window.
- Network sync without explicit ledger bounds processes only proven complete inner buckets; it skips the unproven first/last buckets. No synthetic zeros are inserted for time periods without ledgers.
- Historical market sync no longer reads current `exp_asset_stats` or contract state as if it were historical. `asset_state_snapshots=0` means no snapshots were written, not that historical supply/holders were zero. Existing snapshot rows and schema remain unchanged.
- Use an isolated Horizon working database and a single range worker. Concurrent reingestion/cleanup of that database is unsupported. Any extraction failure stops the script before cleanup; retry does not advance the historical backfill checkpoint. Never apply its cleanup mode to the live Horizon database.
- These changes prevent new incorrect writes. Previously stored bucket values, account dates and asset snapshots still require a separately approved repair. They do not start catch-up or change the backfill direction/state.

Regression checks (no application kernel or `.env` is loaded):

```bash
php vendor/bin/simple-phpunit --do-not-cache-result tests/Command/HorizonRangeStatsScriptTest.php
STELLARCHAIN_TEST_PG_PORT=55439 php vendor/bin/simple-phpunit --do-not-cache-result tests/Service/Statistics/HistoricalStatisticsSyncTest.php
```

The integration suite requires an isolated PostgreSQL instance at `127.0.0.1:55439`, database/user `postgres`, configured for local test authentication. It creates only session-local TEMP tables with synthetic data and skips when the explicit test port is not set. Never point it at an application database. The shell suite uses stub executables, not Horizon or PostgreSQL cleanup commands.

## Sorting Examples

- `https://api.stellarchain.dev/v1/accounts?order[accountMetric.totalTransactions]=desc`
- `https://api.stellarchain.dev/v1/accounts?order[accountMetric.nativeBalance]=desc`

## Filtering Examples

- `https://api.stellarchain.dev/v1/accounts?label=coin`
- `https://api.stellarchain.dev/v1/accounts?address=G...`

## Market Examples

- First page:
  - `https://api.stellarchain.dev/v1/market/assets?network=testnet&limit=50`
- Next page:
  - `https://api.stellarchain.dev/v1/market/assets?network=testnet&limit=50&cursor=50:1234`

---

# Symfony Docker

![CI](https://github.com/dunglas/symfony-docker/workflows/CI/badge.svg)

## Getting Started

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Run `docker compose build --pull --no-cache` to build fresh images
3. Run `docker compose up --wait` to set up and start a fresh Symfony project
4. Open `https://localhost` in your favorite web browser and [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
5. Run `docker compose down --remove-orphans` to stop the Docker containers.

## Features

- Production, development and CI ready
- Just 1 service by default
- Blazing-fast performance thanks to [the worker mode of FrankenPHP](https://frankenphp.dev/docs/worker/)
- [Installation of extra Docker Compose services](docs/extra-services.md) with Symfony Flex
- Automatic HTTPS (in dev and prod)
- HTTP/3 and [Early Hints](https://symfony.com/blog/new-in-symfony-6-3-early-hints) support
- Real-time messaging thanks to a built-in [Mercure hub](https://symfony.com/doc/current/mercure.html)
- [Vulcain](https://vulcain.rocks) support
- Native [XDebug](docs/xdebug.md) integration
- Super-readable configuration

**Enjoy!**

## Docs

1. [Options available](docs/options.md)
2. [Using Symfony Docker with an existing project](docs/existing-project.md)
3. [Support for extra services](docs/extra-services.md)
4. [Deploying in production](docs/production.md)
5. [Debugging with Xdebug](docs/xdebug.md)
6. [TLS Certificates](docs/tls.md)
7. [Using MySQL instead of PostgreSQL](docs/mysql.md)
8. [Using Alpine Linux instead of Debian](docs/alpine.md)
9. [Using a Makefile](docs/makefile.md)
10. [Updating the template](docs/updating.md)
11. [Troubleshooting](docs/troubleshooting.md)

## License

Symfony Docker is available under the MIT License.
