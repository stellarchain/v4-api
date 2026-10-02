-- Explicit, additive PostgreSQL schema. Do not run as part of deployment or forwardfill.
-- Each payment/path-payment may contribute one source and one destination row.
BEGIN;

CREATE TABLE payment_flow_asset_side (
    network INT NOT NULL,
    ledger INT NOT NULL,
    event_id BIGINT NOT NULL,
    side SMALLINT NOT NULL CHECK (side IN (1, 2)),
    asset_id BIGINT NOT NULL,
    address_id BIGINT NOT NULL,
    amount_decimal NUMERIC(36, 14) NOT NULL CHECK (amount_decimal > 0),
    PRIMARY KEY (network, ledger, event_id, side)
);

-- Side-specific branches avoid an OR over source/destination asset columns.
CREATE INDEX idx_payment_flow_asset_side_lookup
    ON payment_flow_asset_side (network, asset_id, side, ledger DESC, event_id DESC);

CREATE TABLE payment_flow_asset_side_build_ledger (
    network INT NOT NULL,
    ledger INT NOT NULL,
    source_events BIGINT NOT NULL CHECK (source_events >= 0),
    source_rows BIGINT NOT NULL CHECK (source_rows >= 0),
    destination_rows BIGINT NOT NULL CHECK (destination_rows >= 0),
    excluded_source BIGINT NOT NULL CHECK (excluded_source >= 0),
    excluded_destination BIGINT NOT NULL CHECK (excluded_destination >= 0),
    built_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (network, ledger),
    CHECK (source_rows + excluded_source = source_events),
    CHECK (destination_rows + excluded_destination = source_events)
);

COMMENT ON TABLE payment_flow_asset_side_build_ledger IS
    'Derived coverage relative to payment_flow_event only; not proof of complete chain ingestion.';

COMMIT;
