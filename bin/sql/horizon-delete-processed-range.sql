\set ON_ERROR_STOP on

BEGIN;

CREATE TEMP TABLE IF NOT EXISTS tmp_processed_history_transaction_ids (
    id bigint PRIMARY KEY
) ON COMMIT DROP;

CREATE TEMP TABLE IF NOT EXISTS tmp_processed_history_operation_ids (
    id bigint PRIMARY KEY
) ON COMMIT DROP;

TRUNCATE tmp_processed_history_transaction_ids;
TRUNCATE tmp_processed_history_operation_ids;

INSERT INTO tmp_processed_history_transaction_ids (id)
SELECT id
FROM history_transactions
WHERE ledger_sequence BETWEEN :start_ledger AND :end_ledger
ON CONFLICT DO NOTHING;

INSERT INTO tmp_processed_history_operation_ids (id)
SELECT id
FROM history_operations
WHERE transaction_id IN (SELECT id FROM tmp_processed_history_transaction_ids)
ON CONFLICT DO NOTHING;

DELETE FROM history_effects
WHERE history_operation_id IN (SELECT id FROM tmp_processed_history_operation_ids);

DELETE FROM history_operation_participants
WHERE history_operation_id IN (SELECT id FROM tmp_processed_history_operation_ids);

DELETE FROM history_operations
WHERE id IN (SELECT id FROM tmp_processed_history_operation_ids);

DELETE FROM history_transaction_participants
WHERE history_transaction_id IN (SELECT id FROM tmp_processed_history_transaction_ids);

DELETE FROM history_transactions
WHERE id IN (SELECT id FROM tmp_processed_history_transaction_ids);

DELETE FROM history_ledgers
WHERE sequence BETWEEN :start_ledger AND :end_ledger;

COMMIT;
