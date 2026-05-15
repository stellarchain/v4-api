#!/usr/bin/env bash
set -euo pipefail

NETWORK="${NETWORK:-mainnet}"
NETWORK_CODE="${NETWORK_CODE:-1}"
BUCKET_MINUTES="${BUCKET_MINUTES:-5}"
STATE_FILE="${STATE_FILE:-.tmp/horizon-history-backfill-${NETWORK}.state}"
LOG_FILE="${LOG_FILE:-var/log/horizon-network-metrics-backfill-${NETWORK}.log}"

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
SELECT
  COUNT(*) AS rows,
  COUNT(DISTINCT tx_hash) AS transactions,
  COUNT(DISTINCT from_address) AS from_addresses,
  COUNT(DISTINCT to_address) AS to_addresses,
  MIN(ledger) AS min_ledger,
  MAX(ledger) AS max_ledger,
  MIN(closed_at) AS first_closed_at,
  MAX(closed_at) AS last_closed_at
FROM payment_flow_event
WHERE network = $NETWORK_CODE;
"

echo
echo "== Payment flow by operation type =="
psql "$STATS_PG" -c "
SELECT operation_type, COUNT(*) AS rows
FROM payment_flow_event
WHERE network = $NETWORK_CODE
GROUP BY operation_type
ORDER BY rows DESC, operation_type ASC;
"

echo
echo "== Payment flow by asset =="
psql "$STATS_PG" -c "
SELECT
  asset_type,
  COALESCE(asset_code, 'XLM') AS asset_code,
  COALESCE(asset_issuer, '') AS asset_issuer,
  COUNT(*) AS rows,
  MIN(closed_at) AS first_closed_at,
  MAX(closed_at) AS last_closed_at
FROM payment_flow_event
WHERE network = $NETWORK_CODE
GROUP BY asset_type, asset_code, asset_issuer
ORDER BY rows DESC
LIMIT 20;
"

echo
echo "== Recent payment flow rows =="
psql "$STATS_PG" -c "
SELECT
  ledger,
  closed_at,
  operation_type,
  from_address,
  to_address,
  asset_type,
  COALESCE(asset_code, 'XLM') AS asset_code,
  amount_decimal
FROM payment_flow_event
WHERE network = $NETWORK_CODE
ORDER BY ledger DESC, operation_id DESC
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
