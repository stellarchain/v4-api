SET TIME ZONE 'UTC';
CREATE TEMP TABLE history_ledgers (
    sequence INT PRIMARY KEY, closed_at TIMESTAMP NOT NULL,
    transaction_count INT NOT NULL DEFAULT 0, successful_transaction_count INT NOT NULL DEFAULT 0,
    failed_transaction_count INT NOT NULL DEFAULT 0, operation_count INT NOT NULL DEFAULT 0
);
SET search_path TO pg_temp, public;
CREATE TEMP TABLE history_transactions (
    id BIGINT PRIMARY KEY, ledger_sequence INT NOT NULL, account TEXT,
    created_at TIMESTAMP NOT NULL, successful BOOLEAN, operation_count INT,
    fee_charged BIGINT, max_fee BIGINT
);
CREATE TEMP TABLE history_operations (
    id BIGINT PRIMARY KEY, transaction_id BIGINT, source_account TEXT, type INT, details JSONB
);
CREATE TEMP TABLE history_assets (id BIGINT PRIMARY KEY, asset_type TEXT, asset_code TEXT, asset_issuer TEXT);
CREATE TEMP TABLE history_trades_60000 (
    timestamp BIGINT, base_asset_id BIGINT, counter_asset_id BIGINT,
    count INT, base_volume NUMERIC, counter_volume NUMERIC, close_n BIGINT, close_d BIGINT
);
CREATE TEMP TABLE exp_asset_stats (asset_type INT, asset_code TEXT, asset_issuer TEXT, accounts JSONB, balances JSONB);
CREATE TEMP TABLE asset_contracts (asset_type INT, asset_code TEXT, asset_issuer TEXT, contract_id TEXT);
CREATE TEMP TABLE contract_asset_stats (contract_id TEXT, stat JSONB);
CREATE TEMP TABLE network_metric_point (
    id BIGSERIAL PRIMARY KEY, network INT, metric_group TEXT, metric_key TEXT, source TEXT,
    bucket_minutes INT, bucket_start TIMESTAMP, bucket_end TIMESTAMP, value_decimal NUMERIC(36,14),
    created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE (network, source, metric_key, bucket_minutes, bucket_start)
);
CREATE TEMP TABLE asset_market_metric_point (
    id BIGSERIAL PRIMARY KEY, network INT, bucket_minutes INT, bucket_start TIMESTAMP, bucket_end TIMESTAMP,
    asset_type TEXT, asset_code TEXT, asset_issuer TEXT, trades_count BIGINT,
    volume_xlm NUMERIC(36,14), volume_asset NUMERIC(36,14), open_price_xlm NUMERIC(36,14),
    high_price_xlm NUMERIC(36,14), low_price_xlm NUMERIC(36,14), close_price_xlm NUMERIC(36,14),
    first_trade_at TIMESTAMP, last_trade_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE (network, bucket_minutes, bucket_start, asset_type, asset_code, asset_issuer)
);
CREATE TEMP TABLE asset_state_snapshot (
    network INT, range_start_ledger INT, range_end_ledger INT, snapshot_at TIMESTAMP,
    asset_type TEXT, asset_code TEXT, asset_issuer TEXT, trustlines_authorized INT,
    trustlines_authorized_to_maintain_liabilities INT, trustlines_unauthorized INT, trustlines_total INT,
    supply NUMERIC(36,14), created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE (network, range_start_ledger, range_end_ledger, asset_type, asset_code, asset_issuer)
);
CREATE TEMP TABLE account_activity_summary (
    network INT, range_start_ledger INT, range_end_ledger INT, account_address TEXT,
    first_ledger INT, last_ledger INT, first_activity_at TIMESTAMP, last_activity_at TIMESTAMP,
    total_transactions BIGINT, successful_transactions BIGINT, failed_transactions BIGINT,
    operation_count BIGINT, fee_charged_sum NUMERIC, max_fee_sum NUMERIC, operation_source_count BIGINT,
    payment_sent_count BIGINT, payment_received_count BIGINT, native_sent NUMERIC, native_received NUMERIC,
    trade_operation_count BIGINT, asset_operation_count BIGINT, contract_operation_count BIGINT,
    account_created_count BIGINT, account_funded_count BIGINT, account_merged_count BIGINT,
    merge_destination_count BIGINT, created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE (network, range_start_ledger, range_end_ledger, account_address)
);
INSERT INTO history_ledgers (sequence, closed_at) VALUES
    (99, '2020-01-01 11:59:55'), (100, '2020-01-01 12:00:05'),
    (101, '2020-01-01 12:01:05'), (102, '2020-01-01 12:02:05'),
    (103, '2020-01-01 12:03:05'), (104, '2020-01-01 12:04:55'),
    (105, '2020-01-01 12:05:05'), (106, '2020-01-01 12:06:05'),
    (107, '2020-01-01 12:07:05'), (108, '2020-01-01 12:08:05'),
    (109, '2020-01-01 12:09:55'), (110, '2020-01-01 12:10:05');
UPDATE history_ledgers SET transaction_count = 1, successful_transaction_count = 1, operation_count = 1
WHERE sequence IN (100, 103, 105);
INSERT INTO history_transactions VALUES
    (1, 100, 'GSYNTHETICSOURCE', '2026-09-01 10:00:00', TRUE, 1, 100, 100),
    (2, 103, 'GSYNTHETICSOURCE', '2026-09-01 10:01:00', TRUE, 1, 200, 200),
    (3, 105, 'GSYNTHETICOTHER', '2026-09-01 10:02:00', TRUE, 1, 900, 900);
INSERT INTO history_operations VALUES
    (1, 1, 'GSYNTHETICSOURCE', 1, '{"from":"GSYNTHETICSOURCE","to":"GSYNTHETICDEST","asset_type":"native","amount":"1"}'),
    (2, 2, 'GSYNTHETICSOURCE', 1, '{"from":"GSYNTHETICSOURCE","to":"GSYNTHETICDEST","asset_type":"native","amount":"2"}'),
    (3, 3, 'GSYNTHETICOTHER', 1, '{"from":"GSYNTHETICOTHER","to":"GSYNTHETICDEST","asset_type":"native","amount":"9"}');
INSERT INTO history_assets VALUES (1, 'native', '', ''), (2, 'credit_alphanum4', 'USD', 'GSYNTHETICISSUER');
INSERT INTO history_trades_60000 VALUES
    (1577880000000, 1, 2, 2, 10000000, 20000000, 2, 1),
    (1577880180000, 1, 2, 3, 30000000, 120000000, 4, 1),
    (1577880300000, 1, 2, 7, 90000000, 90000000, 1, 1);
