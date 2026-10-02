SET TIME ZONE 'UTC';
CREATE TEMP TABLE payment_flow_event (
    id BIGINT PRIMARY KEY,
    network INT NOT NULL,
    ledger INT NOT NULL,
    operation_type VARCHAR(48) NOT NULL,
    successful BOOLEAN NOT NULL,
    from_address_id BIGINT,
    to_address_id BIGINT,
    source_asset_id BIGINT,
    source_amount_decimal NUMERIC(36, 14),
    destination_asset_id BIGINT,
    destination_amount_decimal NUMERIC(36, 14)
);
CREATE INDEX ON payment_flow_event (network, ledger);
CREATE TEMP TABLE payment_flow_asset_side (
    network INT NOT NULL,
    ledger INT NOT NULL,
    event_id BIGINT NOT NULL,
    side SMALLINT NOT NULL CHECK (side IN (1, 2)),
    asset_id BIGINT NOT NULL,
    address_id BIGINT NOT NULL,
    amount_decimal NUMERIC(36, 14) NOT NULL CHECK (amount_decimal > 0),
    PRIMARY KEY (network, ledger, event_id, side)
);
CREATE INDEX ON payment_flow_asset_side (network, asset_id, side, ledger DESC, event_id DESC);
CREATE TEMP TABLE payment_flow_asset_side_build_ledger (
    network INT NOT NULL,
    ledger INT NOT NULL,
    source_events BIGINT NOT NULL,
    source_rows BIGINT NOT NULL,
    destination_rows BIGINT NOT NULL,
    excluded_source BIGINT NOT NULL,
    excluded_destination BIGINT NOT NULL,
    built_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (network, ledger),
    CHECK (source_rows + excluded_source = source_events),
    CHECK (destination_rows + excluded_destination = source_events)
);
SET search_path TO pg_temp, public;
INSERT INTO payment_flow_event
    (id, network, ledger, operation_type, successful, from_address_id, to_address_id,
     source_asset_id, source_amount_decimal, destination_asset_id, destination_amount_decimal)
VALUES
    (1, 1, 100, 'payment', TRUE, 10, 20, 1, 2.5000000, 1, 2.5000000),
    (2, 1, 100, 'path_payment_strict_send', TRUE, 20, 30, 1, 3.0000000, 2, 4.0000000),
    (3, 1, 100, 'account_merge', TRUE, 30, 40, NULL, NULL, NULL, NULL),
    (4, 1, 100, 'payment', FALSE, 40, 50, 1, 10.0000000, 1, 10.0000000),
    (5, 1, 101, 'payment', TRUE, 50, 60, 1, NULL, 1, 1.0000000),
    (6, 1, 101, 'payment', TRUE, 70, 70, 1, 0.2500000, 1, 0.2500000),
    (7, 1, 101, 'payment', TRUE, 80, 90, 1, 0.0000000, 1, 1.0000000),
    (8, 2, 100, 'payment', TRUE, 10, 20, 1, 99.0000000, 1, 99.0000000);
