<?php

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Soneso\StellarSDK\Xdr\XdrContractExecutableType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractItemDataProvider implements ProviderInterface
{
    /** @var array<string,string> */
    private const OFFICIAL_RPC_URLS = [
        'mainnet' => 'https://soroban-rpc.mainnet.stellar.gateway.fm',
        'testnet' => 'https://soroban-testnet.stellar.org',
        'futurenet' => 'https://rpc-futurenet.stellar.org',
    ];

    /** @var array<string,string> */
    private const BACKUP_RPC_ENV_NAMES = [
        'mainnet' => 'SOROBAN_RPC_BACKUP_MAINNET_URL',
        'testnet' => 'SOROBAN_RPC_BACKUP_TESTNET_URL',
        'futurenet' => 'SOROBAN_RPC_BACKUP_FUTURENET_URL',
    ];

    public function __construct(
        private readonly ContractRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Contract
    {
        $contractId = trim((string) ($uriVariables['contractId'] ?? $uriVariables['id'] ?? ''));
        if ($contractId === '') {
            return null;
        }
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($context['filters']['network'] ?? null) ? $context['filters']['network'] : null,
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;

        $contract = $this->repository->findOneByContractIdAndNetwork($contractId, $networkCode);
        if (!$contract instanceof Contract) {
            $contract = $this->loadOrCreateFromSorobanRpcFallback($contractId, $network, $networkCode);
        }
        if (!$contract instanceof Contract) {
            return null;
        }
        $this->refreshContractExecutableMetadataIfMissing($contract, $network);

        $this->applyResolvedContractSourceSnapshot($contract);

        return $contract;
    }

    private function loadOrCreateFromSorobanRpcFallback(string $contractId, string $network, int $networkCode): ?Contract
    {
        $normalizedContractId = $this->sorobanContractInspector->normalizeContractId($contractId);
        if ($normalizedContractId === null) {
            return null;
        }

        $meta = $this->loadExecutableMetaWithFallback($normalizedContractId, $network);
        $executableType = isset($meta['executableType']) && is_int($meta['executableType']) ? (int) $meta['executableType'] : null;
        $wasmId = is_string($meta['wasmId'] ?? null) ? trim((string) $meta['wasmId']) : null;
        if ($wasmId === '') {
            $wasmId = null;
        }
        if ($executableType === null) {
            return null;
        }

        try {
            $contract = (new Contract())
                ->setContractId($normalizedContractId)
                ->setNetwork($networkCode)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setExecutableType($executableType)
                ->setIsSac($executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET);

            try {
                $contract->setContractIdHex(StrKey::decodeContractIdHex($normalizedContractId));
            } catch (\Throwable) {
                // keep null if decode fails unexpectedly
            }

            $this->entityManager->persist($contract);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // likely race on unique(contract_id, network): re-read below
        }

        $resolved = $this->repository->findOneByContractIdAndNetwork($normalizedContractId, $networkCode);
        if ($resolved instanceof Contract && $executableType !== XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET && $wasmId !== null) {
            $this->linkWasmIdToContract((int) $resolved->getId(), $wasmId);
        }

        return $resolved;
    }

    /**
     * @return array{wasmId:?string,executableType:?int}
     */
    private function loadExecutableMetaWithFallback(string $contractId, string $network): array
    {
        $servers = [];
        try {
            $servers[] = $this->sorobanServerFactory->create($network);
        } catch (\Throwable) {
        }

        foreach ($this->resolveFallbackRpcUrls($network) as $url) {
            try {
                $servers[] = new SorobanServer($url);
            } catch (\Throwable) {
            }
        }

        foreach ($servers as $server) {
            $meta = $this->safeLoadExecutableMeta($server, $contractId);
            if (is_int($meta['executableType'] ?? null)) {
                return $meta;
            }
        }

        return ['wasmId' => null, 'executableType' => null];
    }

    /**
     * @return list<string>
     */
    private function resolveFallbackRpcUrls(string $network): array
    {
        $urls = [];

        $direct = self::OFFICIAL_RPC_URLS[$network] ?? null;
        if (is_string($direct) && $direct !== '') {
            $urls[] = $direct;
        }

        $backup = $this->resolveBackupRpcUrl($network);
        if (is_string($backup) && $backup !== '') {
            $urls[] = $backup;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array{wasmId:?string,executableType:?int}
     */
    private function safeLoadExecutableMeta(SorobanServer $server, string $contractId): array
    {
        try {
            return $this->sorobanContractInspector->loadContractExecutableMetaForContractId($server, $contractId);
        } catch (\Throwable) {
            return ['wasmId' => null, 'executableType' => null];
        }
    }

    private function resolveBackupRpcUrl(string $network): ?string
    {
        $envName = self::BACKUP_RPC_ENV_NAMES[$network] ?? self::BACKUP_RPC_ENV_NAMES['mainnet'];
        $value = trim((string) (getenv($envName) ?: ''));

        if ($value !== '') {
            return $value;
        }

        return self::OFFICIAL_RPC_URLS[$network] ?? self::OFFICIAL_RPC_URLS['mainnet'];
    }

    private function applyResolvedContractSourceSnapshot(Contract $contract): void
    {
        $contractDbId = $contract->getId();
        if (!is_int($contractDbId) || $contractDbId <= 0) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT c.wasm_id AS wasm_id, cs.source_code AS source_code
             FROM contracts c
             LEFT JOIN contract_sources cs ON cs.wasm_id = c.wasm_id
             WHERE c.id = :id
             LIMIT 1',
            ['id' => $contractDbId],
            ['id' => ParameterType::INTEGER]
        );
        if (!is_array($row)) {
            return;
        }

        $wasmId = is_string($row['wasm_id'] ?? null) && trim((string) $row['wasm_id']) !== ''
            ? trim((string) $row['wasm_id'])
            : null;
        $sourceCode = is_string($row['source_code'] ?? null) ? (string) $row['source_code'] : null;

        $contract
            ->setResolvedWasmId($wasmId)
            ->setResolvedSourceCode($sourceCode);
    }

    private function refreshContractExecutableMetadataIfMissing(Contract $contract, string $network): void
    {
        $contractDbId = $contract->getId();
        $contractId = $contract->getContractId();
        if (!is_int($contractDbId) || $contractDbId <= 0 || !is_string($contractId) || trim($contractId) === '') {
            return;
        }

        $currentExecutableType = $contract->getExecutableType();
        $currentWasmId = $contract->getWasmId();
        $needsExecutable = $currentExecutableType === null;
        $needsWasm = $currentExecutableType !== XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET
            && (!is_string($currentWasmId) || trim($currentWasmId) === '');
        if (!$needsExecutable && !$needsWasm) {
            return;
        }

        $meta = $this->loadExecutableMetaWithFallback($contractId, $network);
        $executableType = isset($meta['executableType']) && is_int($meta['executableType'])
            ? (int) $meta['executableType']
            : null;
        $wasmId = is_string($meta['wasmId'] ?? null) ? trim((string) $meta['wasmId']) : null;
        if ($wasmId === '') {
            $wasmId = null;
        }

        if ($executableType === null) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'UPDATE contracts
                 SET executable_type = :executable_type,
                     is_sac = :is_sac
                 WHERE id = :id',
                [
                    'executable_type' => $executableType,
                    'is_sac' => $executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET ? 1 : 0,
                    'id' => $contractDbId,
                ],
                [
                    'executable_type' => ParameterType::INTEGER,
                    'is_sac' => ParameterType::INTEGER,
                    'id' => ParameterType::INTEGER,
                ]
            );
        } catch (\Throwable) {
            return;
        }

        if ($executableType !== XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET && $wasmId !== null) {
            $this->linkWasmIdToContract($contractDbId, $wasmId);
        }

        $contract
            ->setExecutableType($executableType)
            ->setIsSac($executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET);
        if ($wasmId !== null) {
            $contract->setResolvedWasmId($wasmId);
        }
    }

    private function linkWasmIdToContract(int $contractDbId, string $wasmId): void
    {
        if ($contractDbId <= 0 || trim($wasmId) === '') {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $this->connection->executeStatement(
                'INSERT INTO contract_sources (wasm_id, status, created_at, updated_at)
                 VALUES (:wasm_id, 0, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
                [
                    'wasm_id' => $wasmId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $this->connection->executeStatement(
                'UPDATE contracts SET wasm_id = :wasm_id WHERE id = :id',
                [
                    'wasm_id' => $wasmId,
                    'id' => $contractDbId,
                ],
                [
                    'id' => ParameterType::INTEGER,
                ]
            );
        } catch (\Throwable) {
        }
    }

}
