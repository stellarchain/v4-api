\set ON_ERROR_STOP on

BEGIN;

DROP TABLE IF EXISTS
    account_activity_summary,
    asset_state_snapshot,
    asset_market_metric_point,
    payment_flow_event,
    payment_flow_transaction,
    payment_flow_asset,
    payment_flow_address,
    network_metric_point
CASCADE;

COMMIT;
