#!/usr/bin/env bash
set -euo pipefail

NETWORK="${NETWORK:-mainnet}"
NETWORK_CODE="${NETWORK_CODE:-1}"
BUCKET_MINUTES="${BUCKET_MINUTES:-5}"
STATUS_FLOW_RECENT_LEDGERS="${STATUS_FLOW_RECENT_LEDGERS:-10000}"
STATE_FILE="${STATE_FILE:-.tmp/horizon-history-backfill-${NETWORK}.state}"
LOG_FILE="${LOG_FILE:-var/log/horizon-network-metrics-backfill-${NETWORK}.log}"
export PSQL_PAGER="${PSQL_PAGER:-cat}"
export PAGER="${PAGER:-cat}"

: "${HORIZON_PG:?Missing HORIZON_PG}"
: "${STATS_PG:?Missing STATS_PG}"

require_positive_int() {
  local name="$1"
  local value="$2"

  if ! [[ "$value" =~ ^[0-9]+$ ]] || [[ "$value" -lt 1 ]]; then
    echo "$name must be a positive integer. Got: $value" >&2
    exit 1
  fi
}

require_positive_int "NETWORK_CODE" "$NETWORK_CODE"
require_positive_int "BUCKET_MINUTES" "$BUCKET_MINUTES"
require_positive_int "STATUS_FLOW_RECENT_LEDGERS" "$STATUS_FLOW_RECENT_LEDGERS"

echo "== Processes =="
ps -eo pid,ppid,%cpu,%mem,etime,cmd | grep -E 'stellar-horizon.*db reingest|horizon-history-backfill|horizon-range-stats|sync-network-metrics|sync-payment-flow-events|sync-asset-market-history|sync-account-activity-summary' | grep -v grep || echo "No backfill processes found"

echo
echo "== State =="
if [[ -f "$STATE_FILE" ]]; then
  cat "$STATE_FILE"
  (
    # shellcheck disable=SC1090
    source "$STATE_FILE"
    if [[ -n "${CURRENT_END_LEDGER:-}" && -n "${LEDGERS_PER_RANGE:-}" ]]; then
      current_start=$((CURRENT_END_LEDGER - LEDGERS_PER_RANGE + 1))
      if [[ "$current_start" -lt 1 ]]; then
        current_start=1
      fi
      echo
      echo "current_or_next_chunk=${current_start}..${CURRENT_END_LEDGER}"
    fi
  )
else
  echo "State file not found: $STATE_FILE"
fi

echo
echo "== Last log lines =="
if [[ -f "$LOG_FILE" ]]; then
  tail -n 30 "$LOG_FILE"
else
  echo "Log file not found: $LOG_FILE"
fi

echo
echo "== Horizon DB history rows =="
psql "$HORIZON_PG" -c "
SELECT relname, n_live_tup
FROM pg_stat_user_tables
WHERE relname LIKE 'history_%'
ORDER BY n_live_tup DESC
LIMIT 15;
"

echo
echo "== Horizon ledgers in working DB =="
psql "$HORIZON_PG" -c "
SELECT COUNT(*) AS ledgers, MIN(sequence) AS min_sequence, MAX(sequence) AS max_sequence
FROM history_ledgers;
"

echo
echo "== Horizon transactions/operations in working DB =="
psql "$HORIZON_PG" -c "
SELECT
  (SELECT COUNT(*) FROM history_transactions) AS transactions,
  (SELECT COUNT(*) FROM history_operations) AS operations;
"

echo
echo "== Statistics summary =="
psql "$STATS_PG" -c "
SELECT
  COUNT(*) AS rows,
  MIN(bucket_start) AS first_bucket,
  MAX(bucket_start) AS last_bucket
FROM network_metric_point
WHERE network = $NETWORK_CODE
  AND bucket_minutes = $BUCKET_MINUTES;
"

echo
echo "== Statistics DB size =="
psql "$STATS_PG" -c "
SELECT
  current_database() AS database,
  pg_size_pretty(pg_database_size(current_database())) AS total_size;
"

echo
echo "== Statistics table sizes =="
psql "$STATS_PG" -c "
SELECT
  relname AS table_name,
  pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
  pg_size_pretty(pg_relation_size(relid)) AS table_size,
  pg_size_pretty(pg_indexes_size(relid)) AS index_size,
  n_live_tup AS estimated_rows
FROM pg_stat_user_tables
WHERE relname IN (
  'network_metric_point',
  'payment_flow_address',
  'payment_flow_asset',
  'payment_flow_transaction',
  'payment_flow_event',
  'asset_market_metric_point',
  'asset_state_snapshot',
  'account_activity_summary'
)
ORDER BY pg_total_relation_size(relid) DESC;
"

echo
echo "== Statistics by metric =="
psql "$STATS_PG" -c "
SELECT metric_key, COUNT(*) AS rows, MIN(bucket_start) AS first_bucket, MAX(bucket_start) AS last_bucket
FROM network_metric_point
WHERE network = $NETWORK_CODE
  AND bucket_minutes = $BUCKET_MINUTES
GROUP BY metric_key
ORDER BY metric_key;
"

echo
echo "== Payment flow summary =="
psql "$STATS_PG" -c "
WITH row_estimate AS (
  SELECT COALESCE(n_live_tup, 0) AS estimated_rows
  FROM pg_stat_user_tables
  WHERE relname = 'payment_flow_event'
),
first_event AS (
  SELECT p.ledger, t.closed_at
  FROM payment_flow_event p
  INNER JOIN payment_flow_transaction t ON t.id = p.tx_id
  WHERE p.network = $NETWORK_CODE
  ORDER BY p.ledger ASC, p.operation_id ASC
  LIMIT 1
),
last_event AS (
  SELECT p.ledger, t.closed_at
  FROM payment_flow_event p
  INNER JOIN payment_flow_transaction t ON t.id = p.tx_id
  WHERE p.network = $NETWORK_CODE
  ORDER BY p.ledger DESC, p.operation_id DESC
  LIMIT 1
)
SELECT
  COALESCE((SELECT estimated_rows FROM row_estimate), 0) AS estimated_rows,
  (SELECT ledger FROM first_event) AS min_ledger,
  (SELECT ledger FROM last_event) AS max_ledger,
  (SELECT closed_at FROM first_event) AS first_closed_at,
  (SELECT closed_at FROM last_event) AS last_closed_at;
"

echo
echo "== Payment flow by operation type (last $STATUS_FLOW_RECENT_LEDGERS ledgers) =="
psql "$STATS_PG" -c "
WITH bounds AS (
  SELECT ledger AS max_ledger
  FROM payment_flow_event
  WHERE network = $NETWORK_CODE
  ORDER BY ledger DESC, operation_id DESC
  LIMIT 1
)
SELECT operation_type, COUNT(*) AS rows, MIN(ledger) AS min_ledger, MAX(ledger) AS max_ledger
FROM payment_flow_event p
CROSS JOIN bounds b
WHERE p.network = $NETWORK_CODE
  AND p.ledger BETWEEN GREATEST(1, b.max_ledger - $STATUS_FLOW_RECENT_LEDGERS + 1) AND b.max_ledger
GROUP BY operation_type
ORDER BY rows DESC, operation_type ASC;
"

echo
echo "== Payment flow dictionaries =="
psql "$STATS_PG" -c "
SELECT
  (SELECT COUNT(*) FROM payment_flow_address WHERE network = $NETWORK_CODE) AS addresses,
  (SELECT COUNT(*) FROM payment_flow_asset WHERE network = $NETWORK_CODE) AS assets,
  (SELECT COUNT(*) FROM payment_flow_transaction WHERE network = $NETWORK_CODE) AS transactions;
"

echo
echo "== Payment flow by destination asset (last $STATUS_FLOW_RECENT_LEDGERS ledgers) =="
psql "$STATS_PG" -c "
WITH bounds AS (
  SELECT ledger AS max_ledger
  FROM payment_flow_event
  WHERE network = $NETWORK_CODE
  ORDER BY ledger DESC, operation_id DESC
  LIMIT 1
)
SELECT
  da.asset_type,
  COALESCE(NULLIF(da.asset_code, ''), 'XLM') AS asset_code,
  da.asset_issuer,
  COUNT(*) AS rows,
  MIN(t.closed_at) AS first_closed_at,
  MAX(t.closed_at) AS last_closed_at
FROM payment_flow_event p
CROSS JOIN bounds b
LEFT JOIN payment_flow_asset da ON da.id = p.destination_asset_id
INNER JOIN payment_flow_transaction t ON t.id = p.tx_id
WHERE p.network = $NETWORK_CODE
  AND p.ledger BETWEEN GREATEST(1, b.max_ledger - $STATUS_FLOW_RECENT_LEDGERS + 1) AND b.max_ledger
GROUP BY da.asset_type, da.asset_code, da.asset_issuer
ORDER BY rows DESC
LIMIT 20;
"

echo
echo "== Recent payment flow rows =="
psql "$STATS_PG" -c "
SELECT
  p.ledger,
  t.closed_at,
  p.operation_type,
  fa.address AS from_address,
  ta.address AS to_address,
  sa.asset_type AS source_asset_type,
  COALESCE(NULLIF(sa.asset_code, ''), 'XLM') AS source_asset_code,
  p.source_amount_decimal,
  da.asset_type AS destination_asset_type,
  COALESCE(NULLIF(da.asset_code, ''), 'XLM') AS destination_asset_code,
  p.destination_amount_decimal
FROM payment_flow_event p
INNER JOIN payment_flow_transaction t ON t.id = p.tx_id
LEFT JOIN payment_flow_address fa ON fa.id = p.from_address_id
LEFT JOIN payment_flow_address ta ON ta.id = p.to_address_id
LEFT JOIN payment_flow_asset sa ON sa.id = p.source_asset_id
LEFT JOIN payment_flow_asset da ON da.id = p.destination_asset_id
WHERE p.network = $NETWORK_CODE
ORDER BY p.ledger DESC, p.operation_id DESC
LIMIT 10;
"

echo
echo "== Asset market history summary =="
psql "$STATS_PG" -c "
SELECT
  COUNT(*) AS rows,
  COUNT(DISTINCT asset_code || ':' || asset_issuer) AS assets,
  MIN(bucket_start) AS first_bucket,
  MAX(bucket_start) AS last_bucket,
  SUM(trades_count) AS trades,
  SUM(volume_xlm) AS volume_xlm
FROM asset_market_metric_point
WHERE network = $NETWORK_CODE
  AND bucket_minutes = $BUCKET_MINUTES;
"

echo
echo "== Top asset market history rows =="
psql "$STATS_PG" -c "
SELECT asset_code, asset_issuer, COUNT(*) AS buckets, SUM(trades_count) AS trades, SUM(volume_xlm) AS volume_xlm
FROM asset_market_metric_point
WHERE network = $NETWORK_CODE
  AND bucket_minutes = $BUCKET_MINUTES
GROUP BY asset_code, asset_issuer
ORDER BY trades DESC
LIMIT 20;
"

echo
echo "== Asset state snapshots summary =="
psql "$STATS_PG" -c "
SELECT
  COUNT(*) AS rows,
  COUNT(DISTINCT asset_code || ':' || asset_issuer) AS assets,
  MIN(snapshot_at) AS first_snapshot,
  MAX(snapshot_at) AS last_snapshot
FROM asset_state_snapshot
WHERE network = $NETWORK_CODE;
"

echo
echo "== Account activity summary =="
psql "$STATS_PG" -c "
SELECT
  COUNT(*) AS rows,
  COUNT(DISTINCT account_address) AS accounts,
  MIN(first_ledger) AS min_ledger,
  MAX(last_ledger) AS max_ledger,
  SUM(total_transactions) AS transactions,
  SUM(payment_sent_count) AS sent_payments,
  SUM(payment_received_count) AS received_payments,
  SUM(trade_operation_count) AS trade_operations,
  SUM(contract_operation_count) AS contract_operations
FROM account_activity_summary
WHERE network = $NETWORK_CODE;
"

echo
echo "== Top account activity rows =="
psql "$STATS_PG" -c "
SELECT account_address, SUM(total_transactions) AS transactions, SUM(payment_sent_count) AS sent, SUM(payment_received_count) AS received, SUM(native_sent) AS native_sent, SUM(native_received) AS native_received
FROM account_activity_summary
WHERE network = $NETWORK_CODE
GROUP BY account_address
ORDER BY transactions DESC
LIMIT 20;
"
