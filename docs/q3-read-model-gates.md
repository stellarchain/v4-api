# Q3 read models: compatibility and V2 gates

This is an implementation contract, not a production migration or a claim of complete historical coverage. Do not rewrite legacy statistics or run schema changes against `horizon_statistics` as part of this package. Historical backfill is complete; forwardfill and any independent repair have separate operational approval.

## 1. Bounded network-metric API rollout

`GET /v1/network-metrics` now requires `windowDays` from 1 to 30. Omitting it returns `422 window_required`; a larger or malformed value returns `400 invalid_window`. Existing clients that relied on an unbounded response must be updated before rollout. The in-repository chart client already sends `windowDays=30`; consumers outside this repository are not inventoried.

Example: `/v1/network-metrics?network=mainnet&metricKey=transactions&bucketMinutes=60&windowDays=7&page=1&itemsPerPage=100`. For an older window, set `before` to the previous response's `window.olderBefore` and reset `page=1`. The timestamp is an exclusive, bucket-aligned UTC end. Use `window.newerBefore` to move forward, or omit `before` for the latest indexed window. `totalItems` and page links refer only to the selected window; neither means complete or current-chain history.

Reader-owned PostgreSQL transactions use a repeatable-read, read-only snapshot and an 8-second per-statement timeout. A caller-owned transaction is not modified. This is a query safety bound, not a measured latency guarantee; multiple statements may take longer in total. A DB timeout is returned as `503 statistics_unavailable`. Before public rollout, notify external API consumers and check bounded count/page queries with a low-load, read-only plan and representative runtime samples. Do not run unbounded `EXPLAIN ANALYZE` on the historical tables.

QA still required: import/export and failure recovery in Investigator; chart empty/error/older-page behavior; narrow viewport and keyboard flows; exact CSV/JSON parity with the visible page; independent historical value reconciliation. A local 200 response or populated chart is not a correctness signoff.

## 2. Investigator V2

Current `payment_flow_event` is classic payment-flow evidence. It has address-, transaction- and ledger-leading indexes, but no asset-leading index. The existing asset filter is supported **only with an account or transaction target**. Asset-only search across the ~3.2-billion-row estimated event table must remain unavailable until a bounded access path is proven. A contract identifier is not a classic payment-flow address; a separate indexed Soroban invocation/transfer source and coverage contract are needed before contract search or account-to-contract edges can be advertised.

Proposed asset search grain: one successful operation, with separate source and destination asset IDs and decimal amounts. A source-asset search and a destination-asset search need separate indexed branches or a derived side-specific relation; a single `OR` over both columns is not the release plan. Query must require network, exact asset key and a bounded UTC/ledger range; cap rows and use `(ledger,id)` keyset pagination. Include source side, destination side, operation ID, transaction hash, ledger, UTC close time and an explicit `coverage` object. Assess index size/write amplification against forwardfill before approving any index or derived table.

Proposed multi-hop grain: a **candidate path of observed operations**, not proof of funds continuity or beneficial ownership. Start from an indexed account; permit at most two hops initially, bound the ledger/time window and fan-out, enforce nondecreasing event time and the same asset on consecutive sides, and return truncation/cursor metadata. Path payments may change asset, so they need explicit conversion-edge semantics; do not add their source and destination amounts. Self-transfers, cycles, missing amounts, failed operations and incomplete ingestion require explicit handling. Do not implement this by recursively calling the public first-hop endpoint: per-page truncation would silently fabricate an incomplete graph.

Release gate: read-only source-coverage audit and bounded plans on representative high-volume accounts/assets; then a separately approved additive index/read model and tests for ordering, duplicate edges, truncation and path-payment units. Until then, `/v1/trace/*` correctly rejects `depth>1`.

## 4. Historical metrics V2

The existing `output-value` key sums nominal amounts across assets and cannot be interpreted as a value in one unit. The existing `max-fee` key is a five-minute **sum of transaction fee limits**, not the largest transaction fee limit. Keep both keys and rows unchanged, with their current warnings; neither can be fixed by relabeling or mathematically inverting the stored aggregate after Horizon truncation.

| V2 output | Exact grain and unit | Source required | Publication gate |
| --- | --- | --- | --- |
| Asset payment volume | network, asset, UTC bucket, side (`source` or `destination`); decimal asset units | successful operation-side records with known asset and amount | source/destination semantics, source coverage, additive asset-keyed store and reconciliation |
| Maximum transaction fee limit | network, UTC bucket; raw fee units, `MAX` over individual `history_transactions.max_fee` | retained Horizon transactions or another verified per-transaction source | versioned key and complete claimed interval; never replace old `max-fee` in place |
| Top payers/receivers | network, exact asset, UTC period, account, role; amount in that asset plus operation count | deduplicated successful flow-operation sides | bounded asset/date read model, deterministic tie order, cursor and coverage |
| Top contract callers | network, contract, UTC period, caller identity; invocation count | independently indexed Soroban invocation records | caller attribution and contract-history completeness audit |

Suggested tie order for rankings is metric descending, then stable account/contract ID ascending. Rank only within one network, asset, role and period; never sum cross-asset nominal values. A top list is a different identity-bearing read model, not another scalar `network_metric_point` key. A Soroban invocation count cannot be inferred from Horizon operation detail-text markers alone.

First implementation slice after the gates: derive a new, versioned five-minute per-transaction maximum from a verified source; test units and bucket boundaries against raw transactions; expose it only for a proven interval. In parallel, specify the source/destination asset-volume relation and its indexes before any 2-TB-scale backfill or production write. Historical gaps remain gaps and must be visible to clients.
