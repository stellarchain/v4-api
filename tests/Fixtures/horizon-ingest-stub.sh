#!/usr/bin/env bash
set -eu
printf 'ingest %s\n' "$*" >> "$STATISTICS_TEST_LOG"
exit "${STATISTICS_TEST_INGEST_EXIT:-0}"
