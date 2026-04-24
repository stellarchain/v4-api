<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ContractEvent;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractEventsCollectionProvider implements ProviderInterface
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

        $limitForFetch = $itemsPerPage + 1;

        if ($beforeId !== null) {
            $eventIds = $this->connection->fetchFirstColumn(
                'SELECT ce.id
                 FROM contract_events ce
                 WHERE ce.contract_id = :contract_id
                   AND ce.id < :before_id
                 ORDER BY ce.id DESC
                 LIMIT :limit',
                [
                    'contract_id' => (int) $contractDbId,
                    'before_id' => $beforeId,
                    'limit' => $limitForFetch,
                ],
                [
                    'contract_id' => ParameterType::INTEGER,
                    'before_id' => ParameterType::INTEGER,
                    'limit' => ParameterType::INTEGER,
                ]
            );
        } else {
            $eventIds = $this->connection->fetchFirstColumn(
                'SELECT ce.id
                 FROM contract_events ce
                 WHERE ce.contract_id = :contract_id
                 ORDER BY ce.id DESC
                 LIMIT :limit OFFSET :offset',
                [
                    'contract_id' => (int) $contractDbId,
                    'limit' => $limitForFetch,
                    'offset' => $offset,
                ],
                [
                    'contract_id' => ParameterType::INTEGER,
                    'limit' => ParameterType::INTEGER,
                    'offset' => ParameterType::INTEGER,
                ]
            );
        }
        if ($eventIds === []) {
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

        $eventIds = array_map(static fn (mixed $id): int => (int) $id, $eventIds);
        $hasMore = count($eventIds) > $itemsPerPage;
        if ($hasMore) {
            $eventIds = array_slice($eventIds, 0, $itemsPerPage);
        }
        $nextBeforeId = $eventIds !== [] ? end($eventIds) : null;
        $nextBeforeId = is_int($nextBeforeId) ? $nextBeforeId : null;

        $request?->attributes->set('_cursor_meta', [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'beforeId' => $beforeId,
            'nextBeforeId' => $hasMore ? $nextBeforeId : null,
            'hasMore' => $hasMore,
            'mode' => $beforeId !== null ? 'keyset' : 'offset',
        ]);

        return $this->entityManager->getRepository(ContractEvent::class)
            ->createQueryBuilder('ce')
            ->andWhere('ce.id IN (:ids)')
            ->setParameter('ids', $eventIds)
            ->orderBy('ce.id', 'DESC')
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
}
