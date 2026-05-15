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
HORIZON_DATABASE_URL="${HORIZON_DATABASE_URL:-}"
HORIZON_NETWORK="${HORIZON_NETWORK:-}"

APP_MODE="${APP_MODE:-docker}"
CONSOLE_BIN="${CONSOLE_BIN:-bin/console-no-debug}"
APP_ENV_PASSTHROUGH_VARS=(
    APP_ENV
    APP_DEBUG
    DATABASE_URL
    DATABASE_STATISTICS_URL
    DATABASE_HORIZON_URL
    DATABASE_HORIZON_URL_TESTNET
    DATABASE_HORIZON_URL_MAINNET
)

RUN_CONTRACT_SCAN="${RUN_CONTRACT_SCAN:-1}"
CONTRACT_BATCH_SIZE="${CONTRACT_BATCH_SIZE:-5000}"
RUN_NETWORK_METRICS="${RUN_NETWORK_METRICS:-1}"
NETWORK_METRICS_BUCKET_MINUTES="${NETWORK_METRICS_BUCKET_MINUTES:-10}"
RUN_PAYMENT_FLOW_EVENTS="${RUN_PAYMENT_FLOW_EVENTS:-0}"
PAYMENT_FLOW_BATCH_SIZE="${PAYMENT_FLOW_BATCH_SIZE:-5000}"
RUN_ASSET_MARKET_HISTORY="${RUN_ASSET_MARKET_HISTORY:-0}"
ASSET_MARKET_BUCKET_MINUTES="${ASSET_MARKET_BUCKET_MINUTES:-$NETWORK_METRICS_BUCKET_MINUTES}"
RUN_ACCOUNT_ACTIVITY_SUMMARY="${RUN_ACCOUNT_ACTIVITY_SUMMARY:-0}"
RUN_COINGECKO_WARM="${RUN_COINGECKO_WARM:-1}"
RUN_MARKET_SNAPSHOTS="${RUN_MARKET_SNAPSHOTS:-1}"
MARKET_TOP="${MARKET_TOP:-0}"
RUN_MARKET_OVERVIEW="${RUN_MARKET_OVERVIEW:-1}"

RUN_LOCAL_RESET="${RUN_LOCAL_RESET:-0}"
ALLOW_MAINNET_RESET="${ALLOW_MAINNET_RESET:-0}"

# Optional host/container specific cleanup hook for the Horizon DB after one range.
POST_RANGE_CLEANUP_COMMAND="${POST_RANGE_CLEANUP_COMMAND:-}"
HORIZON_RETENTION_MODE="${HORIZON_RETENTION_MODE:-none}"

usage() {
    cat <<'EOF'
Usage:
  START_LEDGER=1 END_LEDGER=30000 bin/horizon-range-stats.sh

Environment:
  NETWORK=testnet|mainnet|futurenet
  START_LEDGER=...
  END_LEDGER=...
  HORIZON_BIN=stellar-horizon
  HORIZON_DATABASE_URL=postgresql://.../horizon
  HORIZON_NETWORK=pubnet|testnet|futurenet|passphrase
  HORIZON_MODE=reingest-range|ingest-range
  HORIZON_WORKERS=4
  APP_MODE=docker|host
  CONSOLE_BIN=bin/console-no-debug
  RUN_CONTRACT_SCAN=1
  CONTRACT_BATCH_SIZE=5000
  RUN_NETWORK_METRICS=1
  NETWORK_METRICS_BUCKET_MINUTES=10
  RUN_PAYMENT_FLOW_EVENTS=0
  PAYMENT_FLOW_BATCH_SIZE=5000
  RUN_ASSET_MARKET_HISTORY=0
  ASSET_MARKET_BUCKET_MINUTES=10
  RUN_ACCOUNT_ACTIVITY_SUMMARY=0
  RUN_COINGECKO_WARM=1
  RUN_MARKET_SNAPSHOTS=1
  MARKET_TOP=0
  RUN_MARKET_OVERVIEW=1
  RUN_LOCAL_RESET=0
  ALLOW_MAINNET_RESET=0
  HORIZON_RETENTION_MODE=none|processed-range|truncate-history
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
            APP_CMD=(docker compose exec -T)
            for env_name in "${APP_ENV_PASSTHROUGH_VARS[@]}"; do
                if [[ -n "${!env_name:-}" ]]; then
                    APP_CMD+=(-e "$env_name=${!env_name}")
                fi
            done
            APP_CMD+=(php php)
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
    local horizon_network="$HORIZON_NETWORK"

    if [[ -z "$horizon_network" ]]; then
        case "$NETWORK" in
            mainnet)
                horizon_network="pubnet"
                ;;
            testnet|futurenet)
                horizon_network="$NETWORK"
                ;;
        esac
    fi
    if [[ "$horizon_network" == "passphrase" ]]; then
        horizon_network=""
    fi

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
    if [[ -n "$HORIZON_DATABASE_URL" && -n "$horizon_network" ]]; then
        env -u NETWORK_PASSPHRASE DATABASE_URL="$HORIZON_DATABASE_URL" NETWORK="$horizon_network" "${cmd[@]}"
        return
    fi
    if [[ -n "$HORIZON_DATABASE_URL" ]]; then
        env -u NETWORK DATABASE_URL="$HORIZON_DATABASE_URL" "${cmd[@]}"
        return
    fi
    if [[ -n "$horizon_network" ]]; then
        env -u NETWORK_PASSPHRASE NETWORK="$horizon_network" "${cmd[@]}"
        return
    fi

    env -u NETWORK "${cmd[@]}"
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

    if [[ "$RUN_PAYMENT_FLOW_EVENTS" == "1" ]]; then
        log "Extracting payment flow events"
        run_console app:horizon:sync-payment-flow-events \
            --network="$NETWORK" \
            --start-ledger="$START_LEDGER" \
            --end-ledger="$END_LEDGER" \
            --batch-size="$PAYMENT_FLOW_BATCH_SIZE"
    fi

    if [[ "$RUN_ASSET_MARKET_HISTORY" == "1" ]]; then
        log "Extracting asset market history"
        run_console app:horizon:sync-asset-market-history \
            --network="$NETWORK" \
            --bucket-minutes="$ASSET_MARKET_BUCKET_MINUTES" \
            --start-ledger="$START_LEDGER" \
            --end-ledger="$END_LEDGER"
    fi

    if [[ "$RUN_ACCOUNT_ACTIVITY_SUMMARY" == "1" ]]; then
        log "Extracting account activity summaries"
        run_console app:horizon:sync-account-activity-summary \
            --network="$NETWORK" \
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
    run_horizon_retention

    if [[ -z "$POST_RANGE_CLEANUP_COMMAND" ]]; then
        return 0
    fi

    log "Running post-range cleanup hook"
    /bin/bash -lc "$POST_RANGE_CLEANUP_COMMAND"
}

run_horizon_retention() {
    if [[ "$HORIZON_RETENTION_MODE" == "none" ]]; then
        return 0
    fi
    if [[ -z "$HORIZON_DATABASE_URL" ]]; then
        echo "HORIZON_RETENTION_MODE requires HORIZON_DATABASE_URL." >&2
        exit 1
    fi
    if ! command -v psql >/dev/null 2>&1; then
        echo "HORIZON_RETENTION_MODE requires psql on PATH." >&2
        exit 1
    fi

    case "$HORIZON_RETENTION_MODE" in
        processed-range)
            log "Deleting processed Horizon history range $START_LEDGER..$END_LEDGER"
            psql "$HORIZON_DATABASE_URL" \
                -v ON_ERROR_STOP=1 \
                -v start_ledger="$START_LEDGER" \
                -v end_ledger="$END_LEDGER" \
                -f "$ROOT_DIR/bin/sql/horizon-delete-processed-range.sql"
            ;;
        truncate-history)
            log "Truncating all Horizon history tables after processed range $START_LEDGER..$END_LEDGER"
            psql "$HORIZON_DATABASE_URL" \
                -v ON_ERROR_STOP=1 \
                -f "$ROOT_DIR/bin/sql/horizon-truncate-history.sql"
            ;;
        *)
            echo "Unsupported HORIZON_RETENTION_MODE: $HORIZON_RETENTION_MODE" >&2
            exit 1
            ;;
    esac
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
    require_positive_int "PAYMENT_FLOW_BATCH_SIZE" "$PAYMENT_FLOW_BATCH_SIZE"
    require_positive_int "ASSET_MARKET_BUCKET_MINUTES" "$ASSET_MARKET_BUCKET_MINUTES"

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
