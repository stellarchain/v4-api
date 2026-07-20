<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\ContractTransparency\ContractTransparencyCursor;
use App\Service\ContractTransparency\ContractVisibilitySql;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractStorageEntriesCollectionProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;
    private const MAX_ITEMS_PER_PAGE = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
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
            'SELECT c.id
             FROM contracts c
             WHERE c.contract_id = :contract_id
               AND c.network = :network
               AND '.ContractVisibilitySql::confirmedPredicate('c').'
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

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                id,
                storage_key,
                entry_xdr,
                entry_decoded,
                entry_raw,
                last_modified_ledger_seq,
                live_until_ledger_seq,
                updated_at
             FROM contract_storage_entries
             WHERE id IN (:ids)
             ORDER BY id DESC',
            ['ids' => $entryIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $this->setMeta($page, $itemsPerPage, $cursor, $beforeId, $ledgerStart, $ledgerEnd, $hasMore ? $nextBeforeId : null, $hasMore);

        return array_map(fn (array $row): array => $this->formatRow($row), $rows);
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'storageKey' => is_string($row['storage_key'] ?? null) ? $row['storage_key'] : null,
            'entryXdr' => is_string($row['entry_xdr'] ?? null) ? $row['entry_xdr'] : null,
            'entryDecoded' => $this->decodeJsonValue($row['entry_decoded'] ?? null),
            'entryRaw' => $this->decodeJsonValue($row['entry_raw'] ?? null),
            'lastModifiedLedgerSeq' => isset($row['last_modified_ledger_seq']) ? (int) $row['last_modified_ledger_seq'] : null,
            'liveUntilLedgerSeq' => isset($row['live_until_ledger_seq']) ? (int) $row['live_until_ledger_seq'] : null,
            'updatedAt' => $this->toAtom($row['updated_at'] ?? null),
        ];
    }

    private function toAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }
}
