<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ContractStorageEntry;
use App\Service\ContractTransparency\ContractTransparencyCursor;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractStorageEntriesCollectionProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;
    private const MAX_ITEMS_PER_PAGE = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
        private readonly ContractTransparencyCursor $cursorCodec,
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
        $cursor = $request?->query->get('cursor', $filters['cursor'] ?? null);
        $beforeId = $this->normalizePositiveInt($request?->query->get('beforeId', $filters['beforeId'] ?? $filters['before_id'] ?? null))
            ?? $this->cursorCodec->decodeId($cursor);
        $ledgerStart = $this->normalizePositiveInt($request?->query->get('ledgerStart', $filters['ledgerStart'] ?? $filters['ledger_start'] ?? $filters['startLedger'] ?? null));
        $ledgerEnd = $this->normalizePositiveInt($request?->query->get('ledgerEnd', $filters['ledgerEnd'] ?? $filters['ledger_end'] ?? $filters['endLedger'] ?? null));
        if ($ledgerStart !== null && $ledgerEnd !== null && $ledgerStart > $ledgerEnd) {
            [$ledgerStart, $ledgerEnd] = [$ledgerEnd, $ledgerStart];
        }

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
        $whereSql = ' WHERE cse.contract_id = :contract_id';
        $params = ['contract_id' => (int) $contractDbId, 'limit' => $limitForFetch];
        $types = ['contract_id' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER];

        if ($ledgerStart !== null) {
            $whereSql .= ' AND cse.last_modified_ledger_seq >= :ledger_start';
            $params['ledger_start'] = $ledgerStart;
            $types['ledger_start'] = ParameterType::INTEGER;
        }
        if ($ledgerEnd !== null) {
            $whereSql .= ' AND cse.last_modified_ledger_seq <= :ledger_end';
            $params['ledger_end'] = $ledgerEnd;
            $types['ledger_end'] = ParameterType::INTEGER;
        }
        if ($beforeId !== null) {
            $whereSql .= ' AND cse.id < :before_id';
            $params['before_id'] = $beforeId;
            $types['before_id'] = ParameterType::INTEGER;
        }

        $paginationSql = ' ORDER BY cse.id DESC LIMIT :limit';
        if ($beforeId === null) {
            $paginationSql .= ' OFFSET :offset';
            $params['offset'] = $offset;
            $types['offset'] = ParameterType::INTEGER;
        }

        $entryIds = $this->connection->fetchFirstColumn(
            'SELECT cse.id FROM contract_storage_entries cse'.$whereSql.$paginationSql,
            $params,
            $types,
        );
        if ($entryIds === []) {
            $this->setMeta($page, $itemsPerPage, $cursor, $beforeId, $ledgerStart, $ledgerEnd, null, false);

            return [];
        }

        $entryIds = array_map(static fn (mixed $id): int => (int) $id, $entryIds);
        $hasMore = count($entryIds) > $itemsPerPage;
        if ($hasMore) {
            $entryIds = array_slice($entryIds, 0, $itemsPerPage);
        }
        $nextBeforeId = $entryIds !== [] ? end($entryIds) : null;
        $nextBeforeId = is_int($nextBeforeId) ? $nextBeforeId : null;

        $this->setMeta($page, $itemsPerPage, $cursor, $beforeId, $ledgerStart, $ledgerEnd, $hasMore ? $nextBeforeId : null, $hasMore);

        return $this->entityManager->getRepository(ContractStorageEntry::class)
            ->createQueryBuilder('cse')
            ->andWhere('cse.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->orderBy('cse.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function setMeta(
        int $page,
        int $itemsPerPage,
        mixed $cursor,
        ?int $beforeId,
        ?int $ledgerStart,
        ?int $ledgerEnd,
        ?int $nextBeforeId,
        bool $hasMore,
    ): void {
        $this->requestStack->getCurrentRequest()?->attributes->set('_cursor_meta', [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'limit' => $itemsPerPage,
            'cursor' => is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null,
            'beforeId' => $beforeId,
            'ledgerStart' => $ledgerStart,
            'ledgerEnd' => $ledgerEnd,
            'nextCursor' => $hasMore && $nextBeforeId !== null ? $this->cursorCodec->encodeId($nextBeforeId) : null,
            'nextBeforeId' => $nextBeforeId,
            'hasMore' => $hasMore,
            'mode' => $beforeId !== null ? 'keyset' : 'offset',
        ]);
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
