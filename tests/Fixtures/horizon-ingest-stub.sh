#!/usr/bin/env bash
set -eu
if [[ -n "${STATISTICS_TEST_EXPECT_HORIZON_URL:-}" && "${DATABASE_URL:-}" != "$STATISTICS_TEST_EXPECT_HORIZON_URL" ]]; then
    echo 'Native Horizon database URL was changed.' >&2
    exit 1
fi
printf 'ingest %s\n' "$*" >> "$STATISTICS_TEST_LOG"
exit "${STATISTICS_TEST_INGEST_EXIT:-0}"
