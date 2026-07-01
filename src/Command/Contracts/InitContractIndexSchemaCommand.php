<?php

declare(strict_types=1);

namespace App\Command\Contracts;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:contracts:init-schema',
    description: 'Create contract index tables in the dedicated contracts database.',
)]
final class InitContractIndexSchemaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $contractsConnection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $platform = $this->contractsConnection->getDatabasePlatform();

        if (!$platform instanceof PostgreSQLPlatform) {
            $io->error(sprintf('Unsupported contracts database platform: %s. Use PostgreSQL for horizon_contracts.', $platform::class));

            return Command::FAILURE;
        }

        foreach ($this->postgresStatements() as $sql) {
            $this->contractsConnection->executeStatement($sql);
        }

        $io->success('Contract index schema is ready.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function postgresStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_sources (
    id BIGSERIAL PRIMARY KEY,
    wasm_id VARCHAR(64) NOT NULL,
    source_code TEXT DEFAULT NULL,
    source_code_sha256 VARCHAR(64) DEFAULT NULL,
    wasm_blob BYTEA DEFAULT NULL,
    wasm_blob_sha256 VARCHAR(64) DEFAULT NULL,
    status INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    decompiled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_sources_wasm_id ON contract_sources (wasm_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contracts (
    id BIGSERIAL PRIMARY KEY,
    contract_id VARCHAR(300) NOT NULL,
    contract_id_hex VARCHAR(64) DEFAULT NULL,
    asset_code VARCHAR(255) DEFAULT NULL,
    asset_address VARCHAR(255) DEFAULT NULL,
    asset_issuer VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    contract_code TEXT DEFAULT NULL,
    source_code_verified BOOLEAN NOT NULL DEFAULT FALSE,
    sep55_verified BOOLEAN NOT NULL DEFAULT FALSE,
    github_address VARCHAR(255) DEFAULT NULL,
    sep55_commit_hash VARCHAR(64) DEFAULT NULL,
    sep55_attestation_url VARCHAR(255) DEFAULT NULL,
    sep55_error TEXT DEFAULT NULL,
    sep55_last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    contract_type INT DEFAULT NULL,
    network INT NOT NULL DEFAULT 1,
    executable_type INT DEFAULT NULL,
    is_sac BOOLEAN NOT NULL DEFAULT FALSE,
    wasm_id VARCHAR(64) DEFAULT NULL,
    total_transactions INT NOT NULL DEFAULT 0,
    total_operations INT NOT NULL DEFAULT 0,
    total_events INT NOT NULL DEFAULT 0,
    total_effects INT NOT NULL DEFAULT 0,
    total_storage_entries INT NOT NULL DEFAULT 0,
    total_invokes INT NOT NULL DEFAULT 0
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_id_network ON contracts (contract_id, network)',
            'CREATE INDEX IF NOT EXISTS idx_contracts_wasm_id ON contracts (wasm_id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_network_total_invokes ON contracts (network, total_invokes, id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_network_total_transactions ON contracts (network, total_transactions, id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_network_created ON contracts (network, created_at, id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_transactions (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    tx_hash VARCHAR(300) NOT NULL,
    source_account VARCHAR(300) DEFAULT NULL,
    host_functions TEXT DEFAULT NULL,
    fee_charged BIGINT NOT NULL DEFAULT 0,
    max_fee BIGINT NOT NULL DEFAULT 0,
    ledger INT DEFAULT NULL,
    total_operations INT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    enrichment_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    enrichment_status VARCHAR(16) DEFAULT NULL,
    enrichment_attempts INT NOT NULL DEFAULT 0,
    envelope_decoded JSONB DEFAULT NULL,
    meta_decoded JSONB DEFAULT NULL,
    return_value_decoded JSONB DEFAULT NULL,
    resource_fee_charged BIGINT DEFAULT NULL
)
SQL,
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS envelope_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS meta_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS return_value_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS resource_fee_charged BIGINT DEFAULT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_tx_hash ON contract_transactions (contract_id, tx_hash)',
            'CREATE INDEX IF NOT EXISTS idx_contract_tx_contract_ledger ON contract_transactions (contract_id, ledger)',
            'CREATE INDEX IF NOT EXISTS idx_contract_tx_enrichment_scan ON contract_transactions (contract_id, enrichment_checked_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_tx_hash ON contract_transactions (tx_hash)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_events (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    tx_hash VARCHAR(300) NOT NULL,
    event_idx INT NOT NULL,
    ledger INT DEFAULT NULL,
    ledger_closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    event_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
    topic_decoded JSONB DEFAULT NULL,
    value_decoded JSONB DEFAULT NULL,
    addresses JSONB DEFAULT NULL,
    amount_raw VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    event_raw JSONB DEFAULT NULL,
    is_diagnostic BOOLEAN NOT NULL DEFAULT FALSE
)
SQL,
            'ALTER TABLE contract_events ADD COLUMN IF NOT EXISTS event_raw JSONB DEFAULT NULL',
            'ALTER TABLE contract_events ADD COLUMN IF NOT EXISTS is_diagnostic BOOLEAN NOT NULL DEFAULT FALSE',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_event_idx ON contract_events (contract_id, tx_hash, event_idx)',
            'CREATE INDEX IF NOT EXISTS idx_contract_events_contract_ledger ON contract_events (contract_id, ledger)',
            'CREATE INDEX IF NOT EXISTS idx_contract_events_contract_tx ON contract_events (contract_id, tx_hash)',
            'CREATE INDEX IF NOT EXISTS idx_contract_events_contract_type ON contract_events (contract_id, event_type)',
            'CREATE INDEX IF NOT EXISTS idx_contract_events_contract_id_id ON contract_events (contract_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_events_contract_ledger_closed ON contract_events (contract_id, ledger_closed_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_storage_entries (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    entry_xdr TEXT DEFAULT NULL,
    entry_decoded JSONB DEFAULT NULL,
    entry_raw JSONB DEFAULT NULL,
    last_modified_ledger_seq INT DEFAULT NULL,
    live_until_ledger_seq INT DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'ALTER TABLE contract_storage_entries ADD COLUMN IF NOT EXISTS entry_raw JSONB DEFAULT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_storage_key ON contract_storage_entries (contract_id, storage_key)',
            'CREATE INDEX IF NOT EXISTS idx_contract_storage_contract_ledger ON contract_storage_entries (contract_id, last_modified_ledger_seq)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_verified_metadata (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    metadata_type VARCHAR(64) DEFAULT NULL,
    is_sep41 BOOLEAN DEFAULT NULL,
    symbol VARCHAR(32) DEFAULT NULL,
    decimals INT DEFAULT NULL,
    is_verified BOOLEAN DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    icon_url VARCHAR(255) DEFAULT NULL,
    added_at DATE DEFAULT NULL,
    raw_payload JSONB DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL DEFAULT 'verified_contracts_json',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_verified_metadata_contract ON contract_verified_metadata (contract_id)',
            'CREATE INDEX IF NOT EXISTS idx_contract_verified_metadata_symbol ON contract_verified_metadata (symbol)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_argument_usages (
    id BIGSERIAL PRIMARY KEY,
    contract_transaction_id BIGINT NOT NULL,
    target_contract_id BIGINT NOT NULL,
    target_contract_address VARCHAR(300) NOT NULL,
    referenced_contract_id VARCHAR(300) NOT NULL,
    network INT NOT NULL,
    tx_hash VARCHAR(300) NOT NULL,
    source_account VARCHAR(300) DEFAULT NULL,
    ledger INT DEFAULT NULL,
    function_name VARCHAR(128) NOT NULL,
    matched_paths JSONB DEFAULT NULL,
    matches_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_cau_referenced_network_id ON contract_argument_usages (referenced_contract_id, network, id)',
            'CREATE INDEX IF NOT EXISTS idx_cau_target_network_id ON contract_argument_usages (target_contract_id, network, id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_cau_tx_ref_fn ON contract_argument_usages (contract_transaction_id, referenced_contract_id, function_name)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_holder_balances (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    network INT NOT NULL,
    holder_address VARCHAR(300) NOT NULL,
    balance_raw NUMERIC(65, 0) NOT NULL,
    inflow_raw NUMERIC(65, 0) NOT NULL,
    outflow_raw NUMERIC(65, 0) NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_chb_holder_network_balance ON contract_holder_balances (holder_address, network, balance_raw)',
            'CREATE INDEX IF NOT EXISTS idx_chb_contract_network_balance ON contract_holder_balances (contract_id, network, balance_raw)',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_chb_contract_holder ON contract_holder_balances (contract_id, holder_address)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_index_maintenance_queue (
    contract_id BIGINT PRIMARY KEY,
    network INT NOT NULL,
    needs_derived BOOLEAN NOT NULL DEFAULT TRUE,
    needs_metrics BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_contract_index_maintenance_network ON contract_index_maintenance_queue (network, updated_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_ingest_checkpoints (
    id BIGSERIAL PRIMARY KEY,
    source_name VARCHAR(128) NOT NULL,
    network INT NOT NULL,
    start_ledger INT NOT NULL,
    end_ledger INT DEFAULT NULL,
    last_processed_ledger INT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'running',
    last_error TEXT DEFAULT NULL,
    stats JSONB DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_ingest_checkpoint_source_network ON contract_ingest_checkpoints (source_name, network)',
            'CREATE INDEX IF NOT EXISTS idx_contract_ingest_checkpoints_status ON contract_ingest_checkpoints (status, updated_at)',
        ];
    }
}
