# Q3 read models: compatibility and V2 gates

This is an implementation contract, not a production migration or a claim of complete historical coverage. Do not rewrite legacy statistics or run schema changes against `horizon_statistics` as part of this package. Historical backfill is complete; forwardfill and any independent repair have separate operational approval.

## 1. Bounded network-metric API rollout

`GET /v1/network-metrics` now requires `windowDays` from 1 to 30. Omitting it returns `422 window_required`; a larger or malformed value returns `400 invalid_window`. Existing clients that relied on an unbounded response must be updated before rollout. The in-repository chart client already sends `windowDays=30`; consumers outside this repository are not inventoried.

Example: `/v1/network-metrics?network=mainnet&metricKey=transactions&bucketMinutes=60&windowDays=7&page=1&itemsPerPage=100`. For an older window, set `before` to the previous response's `window.olderBefore` and reset `page=1`. The timestamp is an exclusive, bucket-aligned UTC end. Use `window.newerBefore` to move forward, or omit `before` for the latest indexed window. `totalItems` and page links refer only to the selected window; neither means complete or current-chain history.

Reader-owned PostgreSQL transactions use a repeatable-read, read-only snapshot and an 8-second per-statement timeout. A caller-owned transaction is not modified. This is a query safety bound, not a measured latency guarantee; multiple statements may take longer in total. A DB timeout is returned as `503 statistics_unavailable`. Before public rollout, notify external API consumers and check bounded count/page queries with a low-load, read-only plan and representative runtime samples. Do not run unbounded `EXPLAIN ANALYZE` on the historical tables.

QA still required: import/export and failure recovery in Investigator; chart empty/error/older-page behavior; narrow viewport and keyboard flows; exact CSV/JSON parity with the visible page; independent historical value reconciliation. A local 200 response or populated chart is not a correctness signoff.

## 2. Investigator V2

Current `payment_flow_event` is classic payment-flow evidence. It has address-, transaction- and ledger-leading indexes, but no asset-leading index. Account/transaction asset filters continue to use that source. Asset-only search is now implemented locally through the additive `payment_flow_asset_side` relation and never falls back to an `OR` scan over the ~3.2-billion-row estimated event table. It remains operationally unavailable until the schema is separately approved, installed, populated and checked on representative data. A contract identifier is not a classic payment-flow address. The separate contract index has `contract_transactions` indexed by `(contract_id, ledger)` and a `source_account` field, which may support scoped contract-activity search after coverage and caller-attribution audits. It is not yet an account-to-contract **funds flow** source: the transaction source is not necessarily the invocation authorizer or token sender, and indexed contract events are not currently joined to classic payment-flow edges.

Proposed asset search grain: one successful operation, with separate source and destination asset IDs and decimal amounts. A source-asset search and a destination-asset search need separate indexed branches or a derived side-specific relation; a single `OR` over both columns is not the release plan. Query must require network, exact asset key and a bounded UTC/ledger range; cap rows and use `(ledger,id)` keyset pagination. Include source side, destination side, operation ID, transaction hash, ledger, UTC close time and an explicit `coverage` object. Assess index size/write amplification against forwardfill before approving any index or derived table.

For a side-indexed implementation, use a unique `(network, operation_id, side)` identity and one row per known source/destination amount. An operation matching the same asset on both sides is one investigation event with two matching-side annotations, not two graph edges. Exclude `create_account` and `account_merge` from *payment* volume/rankings; any initial funding or merge flow must be named and counted separately. This avoids silently mixing different operation semantics. Pagination must be anchored to an observed high-water ledger so later forwardfill cannot move already-read pages.

Proposed multi-hop grain: a **candidate path of observed operations**, not proof of funds continuity or beneficial ownership. Start from an indexed account; permit at most two hops initially, bound the ledger/time window and fan-out, enforce nondecreasing event time and the same asset on consecutive sides, and return truncation/cursor metadata. Path payments may change asset, so they need explicit conversion-edge semantics; do not add their source and destination amounts. Self-transfers, cycles, missing amounts, failed operations and incomplete ingestion require explicit handling. Do not implement this by recursively calling the public first-hop endpoint: per-page truncation would silently fabricate an incomplete graph.

At a path-payment boundary, the next hop's source asset must equal the preceding operation's **destination** asset. Keep source and destination amounts in their own units; never add or compare them across an asset conversion. An internal frontier query must include every candidate within its declared ledger/fan-out cap or mark the branch truncated. The first public implementation must not infer a path across an unindexed contract invocation or an unverified gap in the event source.

Release gate: read-only source-coverage audit and bounded plans on representative high-volume accounts/assets; then tests for ordering, duplicate edges, truncation and path-payment units. Account depth 2 is implemented locally over the existing address-leading indexes, but remains pre-release until representative runtime plans and source coverage are checked. Contract-flow edges remain disabled.

### Local bounded two-hop slice

Account targets accept `depth=2` only with a complete ledger range or complete UTC date range. The reader expands at most 12 first-hop counterparties and at most 20 indexed events per frontier account, with a 200-event/200-path aggregate cap. It uses the existing source/destination address indexes, preserves `(ledger,event id)` time order, and requires the preceding destination asset to equal the following source asset. Self-transfers, cycles, failed rows, time-order violations and asset discontinuities are excluded and counted. Path-payment source/destination amounts remain in their own units and are never added. The response and UI label these as candidate paths, expose branch truncation, and keep the evidence table/export page-scoped. This has unit-fixture coverage only; it is not a production-sized latency result or proof of complete history.

### Local asset-side read-model slice (not installed)

`bin/sql/asset-side/schema.sql` defines two additive PostgreSQL tables: `payment_flow_asset_side` (one row per qualifying event side, keyed by network/ledger/event/side) and `payment_flow_asset_side_build_ledger` (source-relative counts and exclusions per ledger). The asset-leading lookup index serves exact asset/side/range predicates without an `OR` over the 3.2-billion-row source table. `event_id` is the indexed event identity; the source table independently enforces unique `(network, operation_id)`. No transaction, memo, address string or UTC timestamp is copied. The two sides of a self-transfer remain two role annotations of one event, not two distinct payments. The local public reader de-duplicates a same-asset two-sided event, anchors pagination to the latest built ledger, clamps reads to the first/latest built bounds and refuses asset-only reads when no built coverage exists. Those bounds do not prove that every interior ledger is present; the response and UI retain the gap warning.

`app:statistics:sync-payment-flow-asset-sides` previews a positive, ascending range of at most 64 ledgers in a repeatable-read, read-only transaction by default. It counts successful `payment` and `path_payment_strict_*` operations, qualifying source/destination rows and excluded sides, including zero-event ledgers. `--apply` additionally requires an operator-supplied `--completed-through-ledger` at or beyond the range end; this value is **not independently verified by the command**. Apply requires the explicit schema installation, serializes runs per network, and transactionally replaces only that network/range's derived rows and per-ledger counts. An insert/count mismatch or timeout rolls back the replacement. The eight-second statement and one-second lock timeouts are guardrails, not throughput evidence. Schema installation, every apply, and any production database write require separate approval; the code is not invoked by forwardfill.

The coverage table proves only how many sides were derived from currently present `payment_flow_event` rows. Zero source events can mean an empty ledger **or a source-ingestion gap**. Before public search/rankings, compare against a separate complete-ledger/operation source and define an externally anchored coverage interval. Also measure table/index growth and query latency on a representative asset and forwardfill range before any production installation/population. Local PostgreSQL fixture verification on 2026-10-02 passed 9 tests and 48 assertions (preview, idempotency, exact asset/role/network totals, corrections, rollback, range/checkpoint guards, and command behavior); it is not a production performance result. Asset-only API/UI code is present locally but cannot return data until that approved operational rollout; rankings and multi-hop routes remain disabled.

Local sizing probe on 2026-10-02: the schema installed cleanly on disposable PostgreSQL 14. With 100,000 **synthetic** side rows spanning 125 ledgers and 500 asset IDs, the heap occupied 7,659,520 bytes and its indexes 10,813,440 bytes (18,497,536 bytes total, about 185 bytes per inserted row). Plain `EXPLAIN` chose `idx_payment_flow_asset_side_lookup` for an exact network/asset/side/ledger-range lookup; a ranking query still required aggregation and sorting after that lookup. These figures are not a production capacity estimate: real asset skew, eligible-operation share, insertion order, bloat, WAL and forwardfill contention are unknown. The direct configured statistics connection could not be opened in this local session, so no new production coverage or runtime measurements were made. Do not infer contiguous history from the prior 64-ledger sample.

## 4. Historical metrics V2

The existing `output-value` key sums nominal amounts across assets and cannot be interpreted as a value in one unit. The existing `max-fee` key is a five-minute **sum of transaction fee limits**, not the largest transaction fee limit. Keep both keys and rows unchanged, with their current warnings; neither can be fixed by relabeling or mathematically inverting the stored aggregate after Horizon truncation.

| V2 output | Exact grain and unit | Source required | Publication gate |
| --- | --- | --- | --- |
| Asset payment volume | network, asset, UTC bucket, side (`source` or `destination`); decimal asset units | successful operation-side records with known asset and amount | source/destination semantics, source coverage, additive asset-keyed store and reconciliation |
| Maximum transaction fee limit | network, UTC bucket; raw fee units, `MAX` over individual `history_transactions.max_fee` | retained Horizon transactions or another verified per-transaction source | versioned key and complete claimed interval; never replace old `max-fee` in place |
| Top payers/receivers | network, exact asset, UTC period, account, role; amount in that asset plus operation count | deduplicated successful flow-operation sides | bounded asset/date read model, deterministic tie order, cursor and coverage |
| Top contract callers | network, contract, UTC period, caller identity; invocation count | independently indexed Soroban invocation records | caller attribution and contract-history completeness audit |

For asset volume and account rankings, count `payment` and the two `path_payment_*` operation types only. Source-side volume uses `source_asset_id/source_amount_decimal`; receiver-side volume uses `destination_asset_id/destination_amount_decimal`. Unknown asset, amount, or address is excluded from the corresponding aggregate and reported as an explicit exclusion count. A path payment is one operation on each side, not one cross-asset amount. Rank by exact decimal amount descending, then account ID ascending; use the same tuple plus a stable period key for cursor pagination. Do not publish historical completeness from row presence or a maximum ledger alone.

Suggested tie order for rankings is metric descending, then stable account/contract ID ascending. Rank only within one network, asset, role and period; never sum cross-asset nominal values. A top list is a different identity-bearing read model, not another scalar `network_metric_point` key. A Soroban invocation count cannot be inferred from Horizon operation detail-text markers alone.

Local implementation slice: the network-metric extractor now derives `max-transaction-fee` with `MAX(history_transactions.max_fee)` in the same bounded source query as the legacy `max-fee` sum. A bucket with missing fee limits fails before any metric write. The new key uses the existing idempotent metric-point upsert and is deliberately disabled in the public catalog; it is not a retroactive correction. The isolated PostgreSQL fixture passed locally on 2026-10-02 (21 tests, 72 assertions); it covers complete-bucket retries, dry-run, rejection of missing fee values before writes, raw-to-indexed comparison across two buckets, and legacy/V2 values, while DB-free tests cover the extractor contract. A production rollout will add one metric row per complete five-minute transaction bucket and must be approved separately after a bounded dry run and storage/coverage check.

Ranges already processed and truncated before this collector is deployed cannot be recovered from the legacy five-minute sums. They need an independently verified transaction-level archive if V2 history is required; the historical backfill is not restarted by this change.

Next: compare raw transactions with the V2 series over a proven interval, then decide whether to publish it with explicit coverage metadata. The local asset-side relation above is a candidate source for asset volume/rankings; it still needs independent coverage reconciliation, representative query plans, storage/write-cost estimates and separately approved installation before any 2-TB-scale population or production write. Historical gaps remain gaps and must be visible to clients.

## Bounded read-only source audit before V2 activation

Run the following only in a read-only transaction with a short statement timeout. They inspect metadata or bounded index endpoints; they do not create indexes or scan the full event table. Check the *actual* production schema rather than assuming the initializer was applied verbatim.

```sql
BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '5s';
SELECT indexname, indexdef FROM pg_indexes
WHERE schemaname = current_schema() AND tablename = 'payment_flow_event'
ORDER BY indexname;
SELECT tablename FROM pg_tables
WHERE schemaname = current_schema()
  AND (tablename LIKE '%soroban%' OR tablename LIKE '%contract%' OR tablename LIKE '%invocation%')
ORDER BY tablename;
SELECT network, ledger FROM payment_flow_event WHERE network = 1
ORDER BY ledger ASC, id ASC LIMIT 1;
SELECT network, ledger FROM payment_flow_event WHERE network = 1
ORDER BY ledger DESC, id DESC LIMIT 1;
ROLLBACK;
```

Follow with plain `EXPLAIN` (no `ANALYZE`) for *specific* representative ledger/asset and high-volume-address predicates. Inspect both source and destination asset branches independently. Before any additive index or side table, estimate on-disk growth and forwardfill write amplification, and identify a gap-aware coverage source for each claimed interval. The retained Horizon transaction/operation ranges must overlap the statistics interval selected for raw-value reconciliation; if they do not, mark that check unavailable rather than calling bucket presence a pass. Contract search may use the existing contract transaction index only after its source/coverage semantics are shown to be sufficient and the UI labels it as contract activity, not a proven transfer trace. Account-to-contract edges and `top-contract-callers` remain disabled until a Soroban invocation/transfer source with defensible caller attribution is demonstrated. Schema installation and historical population require separate approval.

### Production-sized read-only audit, 2026-10-02

The SSH session inspected `horizon_statistics` in read-only transactions with a five-second statement timeout. `payment_flow_event` had ~3.197 billion **planner-estimated** rows, 472 GB table data and 758 GB of indexes; these are not exact row counts. The actual indexes lead on network plus from-address, to-address, transaction or ledger, never asset. There was no Soroban/contract/invocation table in this statistics schema. `network_metric_point` had ~21.24 million estimated rows, 2.6 GB table data and 4.5 GB of indexes.

The latest observed mainnet payment-flow event and transaction were at ledger 62,604,861 (transaction close time 2026-05-17 05:22:00 UTC). The forwardfill checkpoint said `last_completed_ledger=62604861`, `next_ledger=62604862`, `pending_end=62604925` when read. A maximum row or checkpoint is **not** proof of contiguous coverage or present-chain freshness. The published `max-transaction-fee` series had no rows; the legacy `max-fee` series had a latest five-minute point at 2026-05-17 05:20 UTC. Real-source V2 reconciliation is therefore not yet possible.

In the bounded 64-ledger sample 62,604,798–62,604,861, 13,228 payment/path-payment operation IDs were distinct. All had payer and receiver IDs, source and destination asset IDs and decimal amounts. This only verifies the sampled records, not prior intervals, ingestion completeness or correct amount semantics. The highest-volume sample source asset appeared in 6,624 operations; a one-day asset search could therefore inspect millions of event rows at this local density.

Plain `EXPLAIN` for a one-day bounded source-asset branch, destination-asset branch, and address-plus-asset branch chose `idx_payment_flow_event_ledger` followed by a filter and sort. An unbounded address-only lookup chose `idx_payment_flow_event_from`. The event table's last autoanalyze was 2026-08-26 and its ledger histogram ended at 62,584,516, below the audited range. Consequently the one-day plans estimated one row and cannot be used as credible runtime or storage-cost evidence. A combined two-key latest-metric lookup timed out at five seconds; separate single-key lookups used `idx_network_metric_point_query` and completed. Do not infer all bounded readers perform well from the synthetic fixture or these stale-cost plans.

No `EXPLAIN ANALYZE`, `ANALYZE`, migration, index creation, write or job control was run. A fresh statistics refresh and representative runtime checks need a separately reviewed load window and approval; `ANALYZE` changes planner state and can alter live plans. The Horizon transaction source and separate contract index were not accessible from this shell environment, so overlap with retained Horizon rows, contract caller attribution, and full interval completeness remain unverified. Do not publish asset-only search, two-hop tracing, historical asset rankings or `top-contract-callers` on this evidence.

### Direct-connection metric follow-up, 2026-10-02

The configured direct PostgreSQL connections worked in read-only transactions with three- or five-second per-statement timeouts. The retained mainnet Horizon `history_ledgers` endpoints were ledger 64,667,008 at 2026-09-28 18:40:21 UTC and ledger 64,734,905 at 2026-10-02 16:58:27 UTC. The latest observed `network_metric_point` five-minute `transactions` bucket was 2026-05-17 08:15 UTC. These databases still have no overlapping retained interval for raw transaction reconciliation. The earlier inaccessible-connection statement above describes that earlier SSH environment, not this direct-connection follow-up.

The official public Horizon ledger API provided an independent, bounded comparison. Each 200-ledger page covered the complete five-minute bucket, with transaction totals calculated as successful plus failed transactions:

| UTC bucket | Public ledgers | Public transactions | Public operations | Indexed comparison |
| --- | ---: | ---: | ---: | --- |
| 2026-05-14 22:00 | 51 | 17,057 | 36,843 | All three exact |
| 2026-05-17 05:15 | 51 | 16,603 | 25,715 | All three exact |
| 2026-05-17 05:20 | 53 | 16,218 | 25,921 | All three exact |

The public ledger anchors are [62,570,000](https://horizon.stellar.org/ledgers/62570000) and [62,604,750](https://horizon.stellar.org/ledgers/62604750); page by their returned `paging_token`, not by ledger sequence. This is a three-bucket check of ledger/transaction/operation aggregates only. It does not establish continuous coverage, payment-flow correctness, fee values, or forwardfill freshness.

A fixed 2026-05-15 UTC sample contained 288 distinct five-minute rows each for `transactions`, `max-fee`, and `active-addresses`. For the `transactions` metric at one-hour grain, actual read-only COUNT/first-page query samples took 221/199 ms over one day (25 grouped rows) and 285/195 ms over 30 days (721 grouped rows), with the page capped at 100 rows. Plain `EXPLAIN` for the fixed 30-day range chose `idx_network_metric_point_query` and estimated 9,989 source rows. These are single measurements of a single metric, not an API SLO or evidence for other readers or asset-skewed event queries. The local API was not listening on port 443, so the HTTP path was not timed. Keep the external-client `windowDays` migration and broader historical/representative-runtime checks open before release.
