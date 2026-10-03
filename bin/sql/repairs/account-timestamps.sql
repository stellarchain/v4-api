-- Exact historical summary range, keyset batches; preview by default.
\ir setup.sql
\if :apply
  CREATE TEMP TABLE repair_candidates ON COMMIT DROP AS
\endif
WITH samples AS MATERIALIZED (
    SELECT id, account_address, first_ledger, last_ledger,
           first_activity_at, last_activity_at, updated_at
    FROM public.account_activity_summary
    WHERE network = :'network_id'::int
      AND range_start_ledger = :'ledger_from'::int AND range_end_ledger = :'ledger_to'::int
      AND account_address > :'after_account'
    ORDER BY account_address LIMIT :'batch_size'::int
), required_ledgers AS MATERIALIZED (
    SELECT first_ledger AS ledger FROM samples WHERE first_ledger IS NOT NULL
    UNION SELECT last_ledger FROM samples WHERE last_ledger IS NOT NULL
), ledger_dates AS MATERIALIZED (
    SELECT l.ledger, d.closed_at, d.consistent
    FROM required_ledgers l CROSS JOIN LATERAL (
        SELECT MIN(closed_at) AS closed_at, MIN(closed_at) = MAX(closed_at) AS consistent
        FROM public.payment_flow_transaction WHERE network = :'network_id'::int AND ledger = l.ledger
    ) d
), mapped AS (
    SELECT a.*,
      CASE WHEN f.consistent AND f.closed_at < :'repair_before'::timestamp
           THEN f.closed_at ELSE a.first_activity_at END AS corrected_first,
      CASE WHEN l.consistent AND l.closed_at < :'repair_before'::timestamp
           THEN l.closed_at ELSE a.last_activity_at END AS corrected_last,
      f.consistent AS first_proven, l.consistent AS last_proven
    FROM samples a LEFT JOIN ledger_dates f ON f.ledger = a.first_ledger
    LEFT JOIN ledger_dates l ON l.ledger = a.last_ledger
)
SELECT id, account_address, first_ledger, last_ledger, first_proven, last_proven,
       jsonb_build_object('first_activity_at', first_activity_at, 'last_activity_at', last_activity_at,
                          'updated_at', updated_at) AS old_values,
       jsonb_build_object('first_activity_at', corrected_first, 'last_activity_at', corrected_last,
                          'updated_at', date_trunc('second', statement_timestamp() AT TIME ZONE 'UTC')) AS new_values,
       (first_activity_at IS DISTINCT FROM corrected_first OR last_activity_at IS DISTINCT FROM corrected_last) AS changed
FROM mapped ORDER BY account_address;

\if :apply
  INSERT INTO public.statistics_repair_log (batch_id, table_name, row_id, old_values, new_values)
  SELECT :'batch_id', 'account_activity_summary', id, old_values, new_values FROM repair_candidates WHERE changed;
  WITH changed AS (
    UPDATE public.account_activity_summary a
    SET first_activity_at = (c.new_values->>'first_activity_at')::timestamp,
        last_activity_at = (c.new_values->>'last_activity_at')::timestamp,
        updated_at = (c.new_values->>'updated_at')::timestamp
    FROM repair_candidates c
    WHERE a.id = c.id AND c.changed
      AND a.network = :'network_id'::int AND a.range_start_ledger = :'ledger_from'::int AND a.range_end_ledger = :'ledger_to'::int
      AND a.first_ledger IS NOT DISTINCT FROM c.first_ledger AND a.last_ledger IS NOT DISTINCT FROM c.last_ledger
      AND a.first_activity_at IS NOT DISTINCT FROM (c.old_values->>'first_activity_at')::timestamp
      AND a.last_activity_at IS NOT DISTINCT FROM (c.old_values->>'last_activity_at')::timestamp
      AND a.updated_at IS NOT DISTINCT FROM (c.old_values->>'updated_at')::timestamp
    RETURNING a.id
  ) SELECT COUNT(*) = (SELECT COUNT(*) FROM repair_candidates WHERE changed) AS all_updated FROM changed \gset
  \if :all_updated
    SELECT COUNT(*) AS examined, COUNT(*) FILTER (WHERE changed) AS repaired, MAX(account_address) AS next_after_account FROM repair_candidates;
    COMMIT;
  \else
    ROLLBACK;
    \echo 'Concurrent modification; no changes committed.'
    DO $$ BEGIN RAISE EXCEPTION 'Concurrent modification; repair aborted'; END $$;
  \endif
\else
  ROLLBACK;
\endif
