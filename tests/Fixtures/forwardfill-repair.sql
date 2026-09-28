-- Synthetic data ONLY. Loaded into the explicitly opted-in disposable test container.
DROP TABLE IF EXISTS public.statistics_repair_log, public.account_activity_summary,
    public.network_metric_point, public.payment_flow_event, public.payment_flow_transaction,
    public.payment_flow_asset, public.payment_flow_address;
CREATE TABLE public.payment_flow_transaction (
    id bigint PRIMARY KEY, network int NOT NULL, ledger int NOT NULL, closed_at timestamp NOT NULL,
    tx_hash text, memo_type text, memo text
);
CREATE INDEX ON public.payment_flow_transaction (network, ledger, id);
CREATE TABLE public.payment_flow_address (id bigint PRIMARY KEY, network int, address text);
CREATE TABLE public.payment_flow_asset (id bigint PRIMARY KEY, network int, asset_type text, asset_code text, asset_issuer text);
CREATE TABLE public.payment_flow_event (
    id bigint PRIMARY KEY, network int, ledger int, tx_id bigint, operation_id bigint, operation_index int,
    operation_type text, successful boolean, source_account_id bigint, from_address_id bigint, to_address_id bigint,
    source_asset_id bigint, destination_asset_id bigint, source_amount_decimal numeric, destination_amount_decimal numeric
);
CREATE INDEX ON public.payment_flow_event (network, from_address_id, ledger, id);
CREATE INDEX ON public.payment_flow_event (network, to_address_id, ledger, id);
CREATE TABLE public.account_activity_summary (
    id bigint PRIMARY KEY, network int, range_start_ledger int, range_end_ledger int, account_address text,
    first_ledger int, last_ledger int, first_activity_at timestamp(0), last_activity_at timestamp(0), updated_at timestamp(0)
);
CREATE TABLE public.network_metric_point (
    id bigint PRIMARY KEY, network int, source text, metric_key text, bucket_minutes int,
    bucket_start timestamp(0), value_decimal numeric(36,14), updated_at timestamp(0)
);
INSERT INTO public.payment_flow_transaction (id, network, ledger, closed_at, tx_hash, memo_type, memo) VALUES
    (1,1,99,'2020-01-01 11:59:55',repeat('1',64),'none',null),
    (2,1,100,'2020-01-01 12:00:05',repeat('2',64),'text','=UNTRUSTED'),
    (3,1,101,'2020-01-01 12:01:05',repeat('3',64),'none',null),
    (4,1,102,'2020-01-01 12:02:05',repeat('4',64),'none',null),
    (5,1,103,'2020-01-01 12:03:05',repeat('5',64),'none',null),
    (6,1,104,'2020-01-01 12:04:55',repeat('6',64),'none',null),
    (7,1,105,'2020-01-01 12:05:05',repeat('7',64),'none',null);
INSERT INTO public.payment_flow_address VALUES (1,1,'GFOCUS'),(2,1,'GOTHER');
INSERT INTO public.payment_flow_asset VALUES (1,1,'native',null,null);
INSERT INTO public.payment_flow_event VALUES
    (1001,1,100,2,10001,1,'payment',true,1,1,2,1,1,25.1234567,25.1234567),
    (1002,1,100,2,10002,2,'payment',true,2,2,1,1,1,26,26),
    (1003,1,100,2,10003,3,'payment',true,1,1,1,1,1,27,27),
    (1004,1,101,3,10004,1,'account_merge',true,1,1,2,1,1,null,null);
INSERT INTO public.account_activity_summary VALUES
    (1,1,100,104,'GA',100,104,'2026-09-01','2026-09-01','2026-09-01'),
    (2,1,100,104,'GB',100,999,'2026-09-01','2026-09-01','2026-09-01'),
    (3,1,200,204,'GC',100,104,'2026-09-01','2026-09-01','2026-09-01');
INSERT INTO public.network_metric_point VALUES
    (1,1,'horizon_db','ledgers',5,'2020-01-01 12:00:00',2,'2026-09-01'),
    (2,1,'horizon_db','avg-ledger-sec',5,'2020-01-01 12:00:00',1,'2026-09-01'),
    (3,1,'horizon_db','transactions',5,'2020-01-01 12:00:00',999,'2026-09-01');
