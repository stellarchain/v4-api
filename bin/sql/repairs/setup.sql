-- Included by the repair scripts. No dotenv, default targets or implicit apply.
\set ON_ERROR_STOP on
\if :{?expected_database}
\else
  DO $$ BEGIN RAISE EXCEPTION 'expected_database is required'; END $$;
\endif
\if :{?historical_end}
\else
  DO $$ BEGIN RAISE EXCEPTION 'historical_end is required: last completed ledger before forwardfill'; END $$;
\endif
\if :{?apply}
\else
  \set apply false
\endif
\if :{?network_id}
\else
  \echo 'network_id is required (mainnet=1).'
  DO $$ BEGIN RAISE EXCEPTION 'network_id is required'; END $$;
\endif
\if :{?ledger_from}
\else
  \echo 'ledger_from is required.'
  DO $$ BEGIN RAISE EXCEPTION 'ledger_from is required'; END $$;
\endif
\if :{?ledger_to}
\else
  \echo 'ledger_to is required.'
  DO $$ BEGIN RAISE EXCEPTION 'ledger_to is required'; END $$;
\endif
\if :{?repair_before}
\else
  \echo 'repair_before is required: exclusive UTC boundary before forwardfill buckets.'
  DO $$ BEGIN RAISE EXCEPTION 'repair_before is required'; END $$;
\endif
\if :{?batch_id}
\else
  \set batch_id preview
\endif
\if :{?batch_size}
\else
  \set batch_size 500
\endif
\if :{?after_account}
\else
  \set after_account ''
\endif

SELECT (current_database() = :'expected_database' AND :'network_id'::int IN (0,1,2)
    AND :'ledger_from'::int > 0 AND :'ledger_to'::int >= :'ledger_from'::int
    AND :'ledger_to'::int <= :'historical_end'::int
    AND :'ledger_to'::bigint - :'ledger_from'::bigint <= 20000
    AND :'batch_size'::int BETWEEN 1 AND 1000
    AND (NOT :'apply'::boolean OR (:'batch_id' <> 'preview' AND length(:'batch_id') BETWEEN 1 AND 100))) AS valid \gset
\if :valid
\else
  \echo 'Invalid bounds or batch_id. Maximum ledger span=20001; account batch<=1000.'
  DO $$ BEGIN RAISE EXCEPTION 'Invalid repair bounds or batch_id'; END $$;
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
-- Fixed public schema: never accidentally repair FDW views or the optimized copy.
SET LOCAL search_path = public, pg_catalog;

\if :apply
  SELECT pg_try_advisory_xact_lock(1937001, :'network_id'::int) AS acquired \gset
  \if :acquired
  \else
    ROLLBACK;
    \echo 'Another historical repair is active.'
    DO $$ BEGIN RAISE EXCEPTION 'Another historical repair is active'; END $$;
  \endif
  CREATE TABLE IF NOT EXISTS public.statistics_repair_log (
    batch_id text NOT NULL, table_name text NOT NULL, row_id bigint NOT NULL,
    old_values jsonb NOT NULL, new_values jsonb NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT clock_timestamp(), rolled_back_at timestamptz,
    PRIMARY KEY (batch_id, table_name, row_id)
  );
  SELECT NOT EXISTS (SELECT 1 FROM public.statistics_repair_log WHERE batch_id = :'batch_id') AS fresh_batch \gset
  \if :fresh_batch
  \else
    ROLLBACK;
    \echo 'batch_id already exists; use preview to check remaining differences.'
    DO $$ BEGIN RAISE EXCEPTION 'batch_id already exists'; END $$;
  \endif
\endif
