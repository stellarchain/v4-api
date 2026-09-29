# Forwardfill and statistics repairs

Deployment and remote execution are separate approval gates. Publishing this package does **not** run repairs or start a forward worker.

## Launch gates

1. Reconfirm the historical final range and target DB identities read-only. The previous audit observed ledger **62584829**, closed **2026-05-15 21:35:47 UTC**; these are starting values to revalidate, not current live assertions. Proposed next logical ledger: **62584830**.
2. One dedicated Horizon history DB, one separate statistics DB, one host/checkout/worker. No other ingestion/cleanup/backward job may touch this worker's Horizon DB. Never reuse the backward state file.
3. Verify backups and free DB/WAL disk capacity. **No retention means unbounded storage growth**; this runner cannot inspect remote disks. Stop operationally on capacity pressure, never silently clean up.
4. Verify deployed Horizon/Core supports the target protocols, schemas already exist, all four extractors are available, and host PHP >=8.4 includes pcntl. No implicit migrations or installed supervisor.
5. Remote execution and SQL writes need separate explicit approval; SSH additionally requires dual approval.

## Runner configuration

The runner consumes exported environment variables, **not `.env`**. Keep secrets out of shell history/logs. Required:

- `HORIZON_DATABASE_URL`: isolated history PostgreSQL target.
- `DATABASE_STATISTICS_URL`: separate statistics PostgreSQL target.
- `ARCHIVE_STATE_URL`: HTTPS `.well-known/stellar-history.json` for that network. Mainnet: `https://history.stellar.org/prd/core-live/core_live_001/.well-known/stellar-history.json`.
- `START_LEDGER`: first unprocessed logical ledger; required only for a new forward checkpoint.

Keep `HORIZON_DATABASE_URL` compatible with native Horizon/libpq: do not add Doctrine-only `serverVersion` or `charset` parameters. The runner adds `charset=utf8` only to the Symfony connection URLs when missing, preserving existing options and explicit charsets. This avoids DoctrineBundle 3.2.2 initializing the platform with an empty version; PostgreSQL version detection remains available on connection. The native Horizon URL and checkpoint identity are unchanged.

Defaults: `NETWORK=mainnet`, `FORWARDFILL_MODE=once`, `APP_MODE=docker` (existing worker convention), `LEDGERS_PER_RANGE=10000`, `HORIZON_CONTEXT_LEDGERS=128`, `MIN_RANGE_LEDGERS=64`, `POLL_SECONDS=30`, `MAX_ATTEMPTS=3`, `RETRY_SECONDS=30`; metric buckets **5 minutes**. Use `APP_MODE=host` when Symfony runs on the host. Retain the deployment's existing `HORIZON_BIN`, `HORIZON_WORKERS`, captive-core and archive configuration.

Mainnet/testnet only; the archive's passphrase must match. Testnet resets require a reviewed state/DB transition. `ARCHIVE_HEAD_FILE` permits a local archive JSON for offline testing, not continuous follow.

### Pilot — only after launch approval

With the required verified connection/archive variables already exported:

```bash
NETWORK=mainnet START_LEDGER=62584830 STOP_LEDGER=62584893 \
  FORWARDFILL_MODE=once php bin/horizon-forwardfill.php
```

Check all four extractor outputs, checkpoint, complete metric buckets, account timestamps, payment rows and disk/WAL pressure. A zero-row result is not independently proof of missing data. On failure do not advance state manually. After a successful pilot and follow approval, preserve the state, remove `STOP_LEDGER`, then:

```bash
env -u STOP_LEDGER FORWARDFILL_MODE=follow php bin/horizon-forwardfill.php
```

`once` processes at most one range; `catchup` drains to the first observed safe archive head; `follow` continues polling. Send `SIGTERM` to the runner PID to request a stop after the active range. Terminal `Ctrl+C` can also interrupt child processes; the pending checkpoint remains available for retry. Repeated failure exits nonzero; an operator-configured supervisor must reuse state and apply restart backoff.

## Safety and limits

- Network metrics, payment flow, market history and account activity must all succeed before advancement. Exact pending start/end is saved **before work**; retry/resume keeps account-summary range keys stable even if chunk size/head changes.
- State defaults to `.tmp/horizon-forwardfill-mainnet.json`: JSON, atomic replacement after `fsync`, target/network/bucket validation. Password rotation does not change target identity. Per-DB-pair file locking includes inherited child fd 9. This is **single-host locking**, not distributed leader election; another host/checkout must not run concurrently.
- Cleanup hooks, retention purge, reset and unrelated pipelines are rejected. Native `HISTORY_RETENTION_COUNT=0` is enforced for the child, but independent Horizon services must also have purging disabled.
- Logical ranges advance; physical ingestion overlaps for bucket context. Idempotent extraction corrects existing aggregates. No project cleanup deletes history. Horizon itself can replace rows when reingesting overlapping ledgers; this is not an append-only primitive. [Official ingestion behavior](https://developers.stellar.org/docs/data/apis/horizon/admin-guide/ingestion).
- Follow lag = archive publication + context buffer + batching + processing. Defaults retain 128 context ledgers and up to 63 batching ledgers; **not live-stream latency**. Measure throughput and processed-ledger age. Insufficient bucket context fails closed: increase context and retry, never weaken completeness checks.
- Historical asset-state writes stay disabled; existing snapshots remain untouched. Missing supply/holders, partial OHLC and all-transaction/fee metrics are not reconstructed from payment-only data.

## SQL repair — preview by default

`bin/sql/repairs/` requires PostgreSQL/psql >=14. Nothing runs automatically alongside forwardfill. Required parameters: `expected_database`, `historical_end`, `network_id`, `ledger_from`, `ledger_to`, `repair_before`. Max source span: 20001 ledgers; account batch: 500 default, 1000 maximum. Timeout: 20s statement, 2s lock, 30s idle transaction.

Separate writes by **bucket**, not just ledger. For the audited boundary, use `repair_before='2026-05-15 21:35:00'` to exclude the shared bucket and `historical_end=62584829` to exclude forward account ranges. Revalidate before launch.

```bash
PGOPTIONS='-c default_transaction_read_only=on' \
psql "$DATABASE_STATISTICS_URL" --no-psqlrc \
  -v expected_database=horizon_statistics -v historical_end=62584829 \
  -v network_id=1 -v ledger_from=62574830 -v ledger_to=62584829 \
  -v repair_before='2026-05-15 21:35:00' -v batch_size=500 \
  -f bin/sql/repairs/account-timestamps.sql
```

- Accounts use an **exact stored range** and exclusive `after_account` keyset cursor. Preview returns all inspected rows, including unchanged/unmapped; advance by the greatest inspected address. Missing/conflicting source times remain unchanged; endpoints are corrected independently when proven.
- For ledger repair substitute `ledger-metrics.sql` with a bounded window containing preceding/following witnesses. Only `ledgers` and `avg-ledger-sec`, source `horizon_db`, bucket 5, qualify. Gaps, conflicting/nonmonotonic times and incomplete edges exclude the bucket. No transactions, OPS/TPS, fees, XLM-value or market metrics are inferred.
- **Apply requires separate approval.** Use the reviewed command without read-only PGOPTIONS, adding `-v apply=true -v batch_id=account-times-62574830-001` (choose a unique reviewed ID for every batch). It creates `public.statistics_repair_log` if absent and saves original/repaired values atomically with repeatable-read and compare-and-set. Reusing a nonempty batch ID fails. Re-preview after commit; throttle between batches, giving forwardfill I/O priority.
- **Rollback preview:** `psql "$DATABASE_STATISTICS_URL" --no-psqlrc -v expected_database=horizon_statistics -v batch_id=account-times-62574830-001 -f bin/sql/repairs/rollback.sql`. After separate approval add `-v apply=true`. It restores the whole batch only if values/updated_at still match; otherwise no changes commit. The audit log remains. Never delete it to rerun a batch.

## Local verification

Verified on 2026-09-28: the four targeted suites below passed **40 tests / 197 assertions** against PHP 8.5 and isolated PostgreSQL 14. PHP/Bash syntax checks and `git diff --check` also passed. This is local verification, not a production-ingestion certification.

The runner and shell suites use stub executables, not real Horizon ingestion. The statistics suite uses session-local TEMP tables in an explicitly configured isolated PostgreSQL instance. The SQL repair suite requires the named disposable container `stellarchain-forwardfill-regression-20260928`, PostgreSQL 14 on `127.0.0.1:55439`, and `bin/sql/repairs` mounted read-only at `/tmp/repairs`. Its fixture replaces synthetic tables in that disposable database; never use an application database.

```bash
php vendor/bin/simple-phpunit --do-not-cache-result tests/Command/HorizonForwardfillRunnerTest.php
php vendor/bin/simple-phpunit --do-not-cache-result tests/Command/HorizonRangeStatsScriptTest.php
STELLARCHAIN_TEST_PG_PORT=55439 php vendor/bin/simple-phpunit --do-not-cache-result tests/Service/Statistics/HistoricalStatisticsSyncTest.php
STELLARCHAIN_REPAIR_TEST_CONTAINER=stellarchain-forwardfill-regression-20260928 \
  php vendor/bin/simple-phpunit --do-not-cache-result tests/Service/Statistics/ForwardfillRepairIntegrationTest.php
```

No application dotenv or remote DB is loaded by these integration tests. Successful synthetic tests do not certify real archive ingestion; the separately approved pilot remains required. Investigator API/frontend changes are a separate delivery and are not included in this package.
