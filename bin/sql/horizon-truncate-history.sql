\set ON_ERROR_STOP on

BEGIN;

DO $$
DECLARE
    statement text;
BEGIN
    SELECT 'TRUNCATE TABLE '
        || string_agg(format('%I.%I', schemaname, tablename), ', ')
        || ' RESTART IDENTITY CASCADE'
    INTO statement
    FROM pg_tables
    WHERE schemaname = 'public'
      AND tablename LIKE 'history_%';

    IF statement IS NOT NULL THEN
        EXECUTE statement;
    END IF;
END $$;

COMMIT;
