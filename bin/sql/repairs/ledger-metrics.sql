-- Bounded source window includes witnesses on both sides. Only complete buckets qualify.
\ir setup.sql
\if :apply
  CREATE TEMP TABLE repair_candidates ON COMMIT DROP AS
\endif
WITH ledger_map AS MATERIALIZED (
    SELECT ledger, MIN(closed_at) AS closed_at, MIN(closed_at) = MAX(closed_at) AS consistent
    FROM public.payment_flow_transaction
    WHERE network = :'network_id'::int AND ledger BETWEEN :'ledger_from'::int AND :'ledger_to'::int
    GROUP BY ledger
), ordered AS MATERIALIZED (
    SELECT *, LAG(closed_at) OVER (ORDER BY ledger) AS previous_time FROM ledger_map
), buckets AS (
    SELECT date_bin(interval '5 minutes', closed_at, TIMESTAMP '1970-01-01') AS bucket_start,
           MIN(ledger) AS first_ledger, MAX(ledger) AS last_ledger, COUNT(*) AS ledgers,
           MAX(closed_at) AS last_closed_at,
           BOOL_AND(consistent AND previous_time IS NOT NULL AND closed_at > previous_time) AS consistent
    FROM ordered GROUP BY 1
), proven AS (
    SELECT b.*, EXTRACT(EPOCH FROM (b.last_closed_at - p.closed_at)) / b.ledgers AS avg_seconds
    FROM buckets b JOIN ledger_map p ON p.ledger = b.first_ledger - 1
    JOIN ledger_map n ON n.ledger = b.last_ledger + 1
    WHERE b.consistent AND p.consistent AND n.consistent
      AND b.ledgers = b.last_ledger - b.first_ledger + 1
      AND p.closed_at < b.bucket_start AND n.closed_at >= b.bucket_start + interval '5 minutes'
      AND b.bucket_start + interval '5 minutes' <= :'repair_before'::timestamp
), values_to_repair AS (
    SELECT bucket_start, 'ledgers'::text AS metric_key, ledgers::numeric(36,14) AS value FROM proven
    UNION ALL
    SELECT bucket_start, 'avg-ledger-sec', avg_seconds::numeric(36,14) FROM proven
)
SELECT m.id, m.metric_key, m.bucket_start,
       jsonb_build_object('value_decimal', m.value_decimal, 'updated_at', m.updated_at) AS old_values,
       jsonb_build_object('value_decimal', v.value, 'updated_at', date_trunc('second', statement_timestamp() AT TIME ZONE 'UTC')) AS new_values
FROM values_to_repair v JOIN public.network_metric_point m
  ON m.network = :'network_id'::int AND m.source = 'horizon_db' AND m.bucket_minutes = 5
 AND m.bucket_start = v.bucket_start AND m.metric_key = v.metric_key
WHERE m.value_decimal IS DISTINCT FROM v.value ORDER BY m.id;

\if :apply
  INSERT INTO public.statistics_repair_log (batch_id, table_name, row_id, old_values, new_values)
  SELECT :'batch_id', 'network_metric_point', id, old_values, new_values FROM repair_candidates;
  WITH changed AS (
    UPDATE public.network_metric_point m
    SET value_decimal = (c.new_values->>'value_decimal')::numeric,
        updated_at = (c.new_values->>'updated_at')::timestamp
    FROM repair_candidates c
    WHERE m.id = c.id AND m.network = :'network_id'::int AND m.source = 'horizon_db'
      AND m.metric_key = c.metric_key AND m.bucket_minutes = 5 AND m.bucket_start = c.bucket_start
      AND m.value_decimal IS NOT DISTINCT FROM (c.old_values->>'value_decimal')::numeric
      AND m.updated_at IS NOT DISTINCT FROM (c.old_values->>'updated_at')::timestamp
    RETURNING m.id
  ) SELECT COUNT(*) = (SELECT COUNT(*) FROM repair_candidates) AS all_updated FROM changed \gset
  \if :all_updated
    SELECT COUNT(*) AS repaired FROM repair_candidates;
    COMMIT;
  \else
    ROLLBACK;
    \echo 'Concurrent modification; no changes committed.'
    DO $$ BEGIN RAISE EXCEPTION 'Concurrent modification; repair aborted'; END $$;
  \endif
\else
  ROLLBACK;
\endif
