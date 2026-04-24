#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

NETWORK="${NETWORK:-testnet}"
START_LEDGER="${START_LEDGER:-}"
END_LEDGER="${END_LEDGER:-}"

HORIZON_BIN="${HORIZON_BIN:-stellar-horizon}"
HORIZON_MODE="${HORIZON_MODE:-reingest-range}"
HORIZON_WORKERS="${HORIZON_WORKERS:-4}"

APP_MODE="${APP_MODE:-docker}"
CONSOLE_BIN="${CONSOLE_BIN:-bin/console-no-debug}"

RUN_CONTRACT_SCAN="${RUN_CONTRACT_SCAN:-1}"
CONTRACT_BATCH_SIZE="${CONTRACT_BATCH_SIZE:-5000}"
RUN_NETWORK_METRICS="${RUN_NETWORK_METRICS:-1}"
NETWORK_METRICS_BUCKET_MINUTES="${NETWORK_METRICS_BUCKET_MINUTES:-10}"
RUN_COINGECKO_WARM="${RUN_COINGECKO_WARM:-1}"
RUN_MARKET_SNAPSHOTS="${RUN_MARKET_SNAPSHOTS:-1}"
MARKET_TOP="${MARKET_TOP:-0}"
RUN_MARKET_OVERVIEW="${RUN_MARKET_OVERVIEW:-1}"

RUN_LOCAL_RESET="${RUN_LOCAL_RESET:-0}"
ALLOW_MAINNET_RESET="${ALLOW_MAINNET_RESET:-0}"

# Optional host/container specific cleanup hook for the Horizon DB after one range.
POST_RANGE_CLEANUP_COMMAND="${POST_RANGE_CLEANUP_COMMAND:-}"

usage() {
    cat <<'EOF'
Usage:
  START_LEDGER=1 END_LEDGER=30000 bin/horizon-range-stats.sh

Environment:
  NETWORK=testnet|mainnet|futurenet
  START_LEDGER=...
  END_LEDGER=...
  HORIZON_BIN=stellar-horizon
  HORIZON_MODE=reingest-range|ingest-range
  HORIZON_WORKERS=4
  APP_MODE=docker|host
  CONSOLE_BIN=bin/console-no-debug
  RUN_CONTRACT_SCAN=1
  CONTRACT_BATCH_SIZE=5000
  RUN_NETWORK_METRICS=1
  NETWORK_METRICS_BUCKET_MINUTES=10
  RUN_COINGECKO_WARM=1
  RUN_MARKET_SNAPSHOTS=1
  MARKET_TOP=0
  RUN_MARKET_OVERVIEW=1
  RUN_LOCAL_RESET=0
  ALLOW_MAINNET_RESET=0
  POST_RANGE_CLEANUP_COMMAND='docker compose exec horizon psql ...'

Notes:
  - The script only runs statistics that exist today in v4.
  - It does not recreate all historical charts from v3 (TPS, OPS, ledgers, tx-success, tx-failed, dex-vol, etc.).
  - XLM/USD is loaded through app:warm-coingecko-cache.
EOF
}

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

require_value() {
    local name="$1"
    local value="$2"

    if [[ -z "$value" ]]; then
        echo "Missing required value: $name" >&2
        usage >&2
        exit 1
    fi
}

require_positive_int() {
    local name="$1"
    local value="$2"

    if ! [[ "$value" =~ ^[0-9]+$ ]] || [[ "$value" -lt 1 ]]; then
        echo "$name must be a positive integer. Got: $value" >&2
        exit 1
    fi
}

build_app_cmd() {
    case "$APP_MODE" in
        docker)
            APP_CMD=(docker compose exec -T php php)
            ;;
        host)
            APP_CMD=(php)
            ;;
        *)
            echo "Unsupported APP_MODE: $APP_MODE" >&2
            exit 1
            ;;
    esac
}

run_console() {
    build_app_cmd
    "${APP_CMD[@]}" "$CONSOLE_BIN" "$@"
}

run_horizon_ingest() {
    local start_ledger="$1"
    local end_ledger="$2"
    local cmd=()

    case "$HORIZON_MODE" in
        reingest-range)
            cmd=("$HORIZON_BIN" db reingest range "$start_ledger" "$end_ledger" --parallel-workers "$HORIZON_WORKERS")
            ;;
        ingest-range)
            cmd=("$HORIZON_BIN" db ingest --start-ledger "$start_ledger" --end-ledger "$end_ledger" --parallel-workers "$HORIZON_WORKERS")
            ;;
        *)
            echo "Unsupported HORIZON_MODE: $HORIZON_MODE" >&2
            exit 1
            ;;
    esac

    log "Running Horizon ingest: ${cmd[*]}"
    "${cmd[@]}"
}

run_local_reset() {
    if [[ "$RUN_LOCAL_RESET" != "1" ]]; then
        return 0
    fi

    local args=(app:network:reset-data --network="$NETWORK" --force)
    if [[ "$NETWORK" == "mainnet" ]]; then
        if [[ "$ALLOW_MAINNET_RESET" != "1" ]]; then
            echo "Refusing local mainnet reset without ALLOW_MAINNET_RESET=1" >&2
            exit 1
        fi
        args+=(--allow-mainnet)
    fi

    log "Resetting local data for network=$NETWORK"
    run_console "${args[@]}"
}

run_stats_pipeline() {
    if [[ "$RUN_CONTRACT_SCAN" == "1" ]]; then
        log "Running contract range scan for ledgers $START_LEDGER..$END_LEDGER"
        run_console app:horizon:scan-contract-range-db \
            --network="$NETWORK" \
            --start-ledger="$START_LEDGER" \
            --end-ledger="$END_LEDGER" \
            --batch-size="$CONTRACT_BATCH_SIZE" \
            --derive-events
    fi

    if [[ "$RUN_NETWORK_METRICS" == "1" ]]; then
        log "Building paginated network metric points"
        run_console app:horizon:sync-network-metrics \
            --network="$NETWORK" \
            --bucket-minutes="$NETWORK_METRICS_BUCKET_MINUTES" \
            --start-ledger="$START_LEDGER" \
            --end-ledger="$END_LEDGER"
    fi

    if [[ "$RUN_COINGECKO_WARM" == "1" ]]; then
        log "Refreshing XLM/USD and global CoinGecko cache"
        run_console app:warm-coingecko-cache
    fi

    if [[ "$RUN_MARKET_SNAPSHOTS" == "1" ]]; then
        log "Building market asset snapshots"
        run_console app:market:sync-snapshots --network="$NETWORK" --top="$MARKET_TOP"
    fi

    if [[ "$RUN_MARKET_OVERVIEW" == "1" ]]; then
        log "Building market overview snapshot"
        run_console app:horizon:sync-market-overview --network="$NETWORK"
    fi
}

run_cleanup_hook() {
    if [[ -z "$POST_RANGE_CLEANUP_COMMAND" ]]; then
        return 0
    fi

    log "Running post-range cleanup hook"
    /bin/bash -lc "$POST_RANGE_CLEANUP_COMMAND"
}

main() {
    if [[ "${1:-}" == "--help" ]] || [[ "${1:-}" == "-h" ]]; then
        usage
        exit 0
    fi

    require_value "START_LEDGER" "$START_LEDGER"
    require_value "END_LEDGER" "$END_LEDGER"
    require_positive_int "START_LEDGER" "$START_LEDGER"
    require_positive_int "END_LEDGER" "$END_LEDGER"
    require_positive_int "HORIZON_WORKERS" "$HORIZON_WORKERS"
    require_positive_int "CONTRACT_BATCH_SIZE" "$CONTRACT_BATCH_SIZE"
    require_positive_int "NETWORK_METRICS_BUCKET_MINUTES" "$NETWORK_METRICS_BUCKET_MINUTES"

    if [[ "$START_LEDGER" -gt "$END_LEDGER" ]]; then
        echo "START_LEDGER must be <= END_LEDGER" >&2
        exit 1
    fi

    log "Range start: network=$NETWORK ledgers=$START_LEDGER..$END_LEDGER app_mode=$APP_MODE horizon_mode=$HORIZON_MODE"
    run_local_reset
    run_horizon_ingest "$START_LEDGER" "$END_LEDGER"
    run_stats_pipeline
    run_cleanup_hook
    log "Range finished successfully"
}

main "$@"
