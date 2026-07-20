# StellarChain API

Symfony 8 + API Platform project for StellarChain data (accounts + metrics).

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
  - query params: `network`, `metricKey`, `metricGroup`, `source`, `bucketMinutes`, `bucketStart[before|after]`, `bucketEnd[before|after]`
  - response: paginated time-series rows for blockchain/network charts
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
  - `app:horizon:sync-asset-market-history` (extracts asset/XLM market buckets and active asset state snapshots from a Horizon DB chunk)
  - `app:horizon:sync-account-activity-summary` (extracts compact account activity summaries from a Horizon DB chunk)
  - `app:statistics:init-schema` (creates the historical statistics storage tables on the configured statistics database)
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
   ```
4. Historical unattended backfill:
   ```bash
   NETWORK=testnet LATEST_LEDGER=12345678 bin/horizon-history-backfill.sh
   ```
4. Open API docs:
   - `https://api.stellarchain.dev/v1`
   - `https://api.stellarchain.dev/v1/accounts`
- `https://api.stellarchain.dev/v1/market/assets?network=testnet&limit=50`
- `https://api.stellarchain.dev/v1/network-metrics?network=testnet&metricKey=transactions&bucketMinutes=10`

## Statistics Database

- `DATABASE_STATISTICS_URL` controls where `app:horizon:sync-network-metrics` and `app:horizon:sync-payment-flow-events` write historical rows.
- By default it falls back to `DATABASE_URL`, preserving the existing app behavior.
- For an isolated historical backfill worker, point it at a separate MySQL or PostgreSQL database, then run `app:statistics:init-schema` before starting the backfill.
- `RUN_PAYMENT_FLOW_EVENTS=1` can be added to `bin/horizon-history-backfill.sh` to preserve direct payment/path-payment/create-account/account-merge flow events before Horizon history tables are truncated.
- `RUN_ASSET_MARKET_HISTORY=1` preserves per-asset XLM market buckets and active asset state snapshots.
- `RUN_ACCOUNT_ACTIVITY_SUMMARY=1` preserves compact per-range account summaries for later account ranking/statistics imports.

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
