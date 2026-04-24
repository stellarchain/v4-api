<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ContractTransaction;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractTransactionsCollectionProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;
    private const MAX_ITEMS_PER_PAGE = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $contractId = strtoupper(trim((string) ($uriVariables['contractId'] ?? '')));
        if ($contractId === '') {
            return [];
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $request = $this->requestStack->getCurrentRequest();
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($request?->query->get('network')) ? $request?->query->get('network') : (is_string($filters['network'] ?? null) ? $filters['network'] : null),
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            return [];
        }

        $page = max(1, (int) ($request?->query->get('page', $filters['page'] ?? 1) ?? 1));
        $itemsPerPage = (int) ($request?->query->get('itemsPerPage', $filters['itemsPerPage'] ?? self::DEFAULT_ITEMS_PER_PAGE) ?? self::DEFAULT_ITEMS_PER_PAGE);
        if ($itemsPerPage <= 0) {
            $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;
        }
        if ($itemsPerPage > self::MAX_ITEMS_PER_PAGE) {
            $itemsPerPage = self::MAX_ITEMS_PER_PAGE;
        }
        $offset = ($page - 1) * $itemsPerPage;
        $beforeId = $this->normalizePositiveInt($request?->query->get('beforeId', $filters['beforeId'] ?? $filters['before_id'] ?? null));
        $invocationsOnly = $this->parseNullableBool($request?->query->get('invocationsOnly', $filters['invocationsOnly'] ?? $filters['invocations_only'] ?? null)) === true;
        $limitForFetch = $itemsPerPage + 1;

        $contractDbId = $this->connection->fetchOne(
            'SELECT id
             FROM contracts
             WHERE contract_id = :contract_id
               AND network = :network
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );
        if ($contractDbId === false) {
            return [];
        }

        $whereSql = ' WHERE ct.contract_id = :contract_id';
        $params = ['contract_id' => (int) $contractDbId, 'limit' => $limitForFetch];
        $types = ['contract_id' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER];

        if ($beforeId !== null) {
            $whereSql .= ' AND ct.id < :before_id';
            $params['before_id'] = $beforeId;
            $types['before_id'] = ParameterType::INTEGER;
        }
        if ($invocationsOnly) {
            $whereSql .= ' AND ct.host_functions IS NOT NULL'
                .' AND ct.host_functions <> :empty_host_functions'
                .' AND ct.host_functions LIKE :invoke_contracts_any'
                .' AND ct.host_functions NOT LIKE :invoke_contracts_empty';
            $params['empty_host_functions'] = '';
            $params['invoke_contracts_any'] = '%"invokeContracts":[%';
            $params['invoke_contracts_empty'] = '%"invokeContracts":[]%';
        }

        $paginationSql = ' ORDER BY ct.id DESC LIMIT :limit';
        if ($beforeId === null) {
            $paginationSql .= ' OFFSET :offset';
            $params['offset'] = $offset;
            $types['offset'] = ParameterType::INTEGER;
        }

        $txIds = $this->connection->fetchFirstColumn(
            'SELECT ct.id FROM contract_transactions ct'.$whereSql.$paginationSql,
            $params,
            $types
        );
        if ($txIds === []) {
            $request?->attributes->set('_cursor_meta', [
                'page' => $page,
                'itemsPerPage' => $itemsPerPage,
                'beforeId' => $beforeId,
                'nextBeforeId' => null,
                'hasMore' => false,
                'mode' => $beforeId !== null ? 'keyset' : 'offset',
            ]);
            return [];
        }

        $txIds = array_map(static fn (mixed $id): int => (int) $id, $txIds);
        $hasMore = count($txIds) > $itemsPerPage;
        if ($hasMore) {
            $txIds = array_slice($txIds, 0, $itemsPerPage);
        }
        $nextBeforeId = $txIds !== [] ? end($txIds) : null;
        $nextBeforeId = is_int($nextBeforeId) ? $nextBeforeId : null;

        $request?->attributes->set('_cursor_meta', [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'beforeId' => $beforeId,
            'nextBeforeId' => $hasMore ? $nextBeforeId : null,
            'hasMore' => $hasMore,
            'mode' => $beforeId !== null ? 'keyset' : 'offset',
        ]);

        return $this->entityManager->getRepository(ContractTransaction::class)
            ->createQueryBuilder('ct')
            ->andWhere('ct.id IN (:ids)')
            ->setParameter('ids', $txIds)
            ->orderBy('ct.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function parseNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
