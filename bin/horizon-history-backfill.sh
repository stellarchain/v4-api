#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

NETWORK="${NETWORK:-testnet}"
LATEST_LEDGER="${LATEST_LEDGER:-}"
LEDGERS_PER_RANGE="${LEDGERS_PER_RANGE:-250000}"
STATE_FILE="${STATE_FILE:-$ROOT_DIR/.tmp/horizon-history-backfill-${NETWORK}.state}"
SLEEP_SECONDS="${SLEEP_SECONDS:-0}"

usage() {
    cat <<'EOF'
Usage:
  NETWORK=testnet LATEST_LEDGER=12345678 bin/horizon-history-backfill.sh

Environment:
  NETWORK=testnet|mainnet|futurenet
  LATEST_LEDGER=...          Required on first run. Ignored after the state file is created.
  LEDGERS_PER_RANGE=250000   Backfill chunk size in ledgers.
  STATE_FILE=.tmp/...        Persistent state file for unattended resume.
  SLEEP_SECONDS=0            Optional pause between chunks.

Behavior:
  - Walks backwards from the latest ledger to ledger 1.
  - For each range it calls bin/horizon-range-stats.sh.
  - The inner script handles Horizon ingest, metric sync, market snapshots and cleanup hooks.

Important:
  - Chunking is ledger-based for determinism.
  - Time-series buckets remain time-based because app:horizon:sync-network-metrics groups by ledger closed_at.
EOF
}

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

require_positive_int() {
    local name="$1"
    local value="$2"

    if ! [[ "$value" =~ ^[0-9]+$ ]] || [[ "$value" -lt 1 ]]; then
        echo "$name must be a positive integer. Got: $value" >&2
        exit 1
    fi
}

load_state() {
    if [[ -f "$STATE_FILE" ]]; then
        # shellcheck disable=SC1090
        source "$STATE_FILE"
    fi
}

save_state() {
    mkdir -p "$(dirname "$STATE_FILE")"
    cat >"$STATE_FILE" <<EOF
NETWORK=${NETWORK}
CURRENT_END_LEDGER=${CURRENT_END_LEDGER}
LEDGERS_PER_RANGE=${LEDGERS_PER_RANGE}
UPDATED_AT=$(date '+%Y-%m-%d %H:%M:%S')
EOF
}

main() {
    if [[ "${1:-}" == "--help" ]] || [[ "${1:-}" == "-h" ]]; then
        usage
        exit 0
    fi

    require_positive_int "LEDGERS_PER_RANGE" "$LEDGERS_PER_RANGE"
    load_state

    if [[ -z "${CURRENT_END_LEDGER:-}" ]]; then
        if [[ -z "$LATEST_LEDGER" ]]; then
            echo "LATEST_LEDGER is required on the first run." >&2
            usage >&2
            exit 1
        fi
        require_positive_int "LATEST_LEDGER" "$LATEST_LEDGER"
        CURRENT_END_LEDGER="$LATEST_LEDGER"
        save_state
    fi

    require_positive_int "CURRENT_END_LEDGER" "$CURRENT_END_LEDGER"

    while [[ "$CURRENT_END_LEDGER" -ge 1 ]]; do
        local_start=$((CURRENT_END_LEDGER - LEDGERS_PER_RANGE + 1))
        if [[ "$local_start" -lt 1 ]]; then
            local_start=1
        fi

        log "Backfill chunk: network=$NETWORK ledgers=$local_start..$CURRENT_END_LEDGER"
        START_LEDGER="$local_start" END_LEDGER="$CURRENT_END_LEDGER" NETWORK="$NETWORK" \
            "$ROOT_DIR/bin/horizon-range-stats.sh"

        if [[ "$local_start" -le 1 ]]; then
            CURRENT_END_LEDGER=0
            save_state
            log "Historical backfill completed."
            exit 0
        fi

        CURRENT_END_LEDGER=$((local_start - 1))
        save_state

        if [[ "$SLEEP_SECONDS" -gt 0 ]]; then
            sleep "$SLEEP_SECONDS"
        fi
    done

    log "Historical backfill completed."
}

main "$@"
