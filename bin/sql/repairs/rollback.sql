-- Preview by default. Explicit apply=true restores an entire batch or nothing.
\set ON_ERROR_STOP on
\if :{?expected_database}
\else
  DO $$ BEGIN RAISE EXCEPTION 'expected_database is required'; END $$;
\endif
SELECT current_database() = :'expected_database' AS correct_database \gset
\if :correct_database
\else
  DO $$ BEGIN RAISE EXCEPTION 'Wrong repair database'; END $$;
\endif
\if :{?apply}
\else
  \set apply false
\endif
\if :{?batch_id}
\else
  \echo 'batch_id is required.'
  DO $$ BEGIN RAISE EXCEPTION 'batch_id is required'; END $$;
\endif
\if :apply
  BEGIN ISOLATION LEVEL REPEATABLE READ;
\else
  BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
\endif
SET LOCAL TIME ZONE 'UTC';
SET LOCAL statement_timeout = '20s';
SET LOCAL lock_timeout = '2s';
SET LOCAL idle_in_transaction_session_timeout = '30s';
SELECT table_name, row_id, old_values, new_values, rolled_back_at
FROM public.statistics_repair_log WHERE batch_id = :'batch_id' ORDER BY table_name, row_id;
\if :apply
  -- Concurrent changes are never overwritten. updated_at participates in the compare-and-set.
  WITH restored_accounts AS (
    UPDATE public.account_activity_summary a
    SET first_activity_at = (r.old_values->>'first_activity_at')::timestamp,
        last_activity_at = (r.old_values->>'last_activity_at')::timestamp,
        updated_at = (r.old_values->>'updated_at')::timestamp
    FROM public.statistics_repair_log r
    WHERE r.batch_id = :'batch_id' AND r.table_name = 'account_activity_summary' AND r.rolled_back_at IS NULL
      AND a.id = r.row_id
      AND jsonb_build_object('first_activity_at', a.first_activity_at, 'last_activity_at', a.last_activity_at,
                            'updated_at', a.updated_at) = r.new_values
    RETURNING a.id
  ), restored_metrics AS (
    UPDATE public.network_metric_point m
    SET value_decimal = (r.old_values->>'value_decimal')::numeric,
        updated_at = (r.old_values->>'updated_at')::timestamp
    FROM public.statistics_repair_log r
    WHERE r.batch_id = :'batch_id' AND r.table_name = 'network_metric_point' AND r.rolled_back_at IS NULL
      AND m.id = r.row_id
      AND jsonb_build_object('value_decimal', m.value_decimal, 'updated_at', m.updated_at) = r.new_values
    RETURNING m.id
  ) SELECT (SELECT COUNT(*) FROM restored_accounts) + (SELECT COUNT(*) FROM restored_metrics)
      = (SELECT COUNT(*) FROM public.statistics_repair_log WHERE batch_id = :'batch_id' AND rolled_back_at IS NULL)
      AS all_restored \gset
  \if :all_restored
    UPDATE public.statistics_repair_log SET rolled_back_at = clock_timestamp()
    WHERE batch_id = :'batch_id' AND rolled_back_at IS NULL;
    COMMIT;
  \else
    ROLLBACK;
    \echo 'Batch changed after repair; nothing restored. Inspect conflicts before retrying.'
    DO $$ BEGIN RAISE EXCEPTION 'Batch changed after repair; rollback aborted'; END $$;
  \endif
\else
  ROLLBACK;
\endif
