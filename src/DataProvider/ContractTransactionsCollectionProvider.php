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
final class ContractTransactionsCollectionProvider implements ProviderInterface
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
        $invocationsOnly = $this->parseNullableBool($request?->query->get('invocationsOnly', $filters['invocationsOnly'] ?? $filters['invocations_only'] ?? null)) === true;
        $limitForFetch = $itemsPerPage + 1;

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

        $whereSql = ' WHERE ct.contract_id = :contract_id';
        $params = ['contract_id' => (int) $contractDbId, 'limit' => $limitForFetch];
        $types = ['contract_id' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER];

        if ($ledgerStart !== null) {
            $whereSql .= ' AND ct.ledger >= :ledger_start';
            $params['ledger_start'] = $ledgerStart;
            $types['ledger_start'] = ParameterType::INTEGER;
        }
        if ($ledgerEnd !== null) {
            $whereSql .= ' AND ct.ledger <= :ledger_end';
            $params['ledger_end'] = $ledgerEnd;
            $types['ledger_end'] = ParameterType::INTEGER;
        }
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
                'limit' => $itemsPerPage,
                'cursor' => is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null,
                'beforeId' => $beforeId,
                'ledgerStart' => $ledgerStart,
                'ledgerEnd' => $ledgerEnd,
                'nextCursor' => null,
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

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                id,
                tx_hash,
                source_account,
                host_functions,
                fee_charged,
                max_fee,
                ledger,
                total_operations,
                created_at,
                envelope_decoded,
                meta_decoded,
                return_value_decoded,
                resource_fee_charged
             FROM contract_transactions
             WHERE id IN (:ids)
             ORDER BY id DESC',
            ['ids' => $txIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $request?->attributes->set('_cursor_meta', [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'limit' => $itemsPerPage,
            'cursor' => is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null,
            'beforeId' => $beforeId,
            'ledgerStart' => $ledgerStart,
            'ledgerEnd' => $ledgerEnd,
            'nextCursor' => $hasMore && $nextBeforeId !== null ? $this->cursorCodec->encodeId($nextBeforeId) : null,
            'nextBeforeId' => $hasMore ? $nextBeforeId : null,
            'hasMore' => $hasMore,
            'mode' => $beforeId !== null ? 'keyset' : 'offset',
        ]);

        return array_map(fn (array $row): array => $this->formatRow($row), $rows);
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'txHash' => $this->normalizeNullableString($row['tx_hash'] ?? null),
            'sourceAccount' => $this->normalizeNullableString($row['source_account'] ?? null),
            'hostFunctions' => $this->decodeJsonValue($row['host_functions'] ?? null),
            'feeCharged' => isset($row['fee_charged']) ? (int) $row['fee_charged'] : 0,
            'maxFee' => isset($row['max_fee']) ? (int) $row['max_fee'] : 0,
            'ledger' => isset($row['ledger']) ? (int) $row['ledger'] : null,
            'totalOperations' => isset($row['total_operations']) ? (int) $row['total_operations'] : null,
            'createdAt' => $this->toAtom($row['created_at'] ?? null),
            'envelopeDecoded' => $this->decodeJsonValue($row['envelope_decoded'] ?? null),
            'metaDecoded' => $this->decodeJsonValue($row['meta_decoded'] ?? null),
            'returnValueDecoded' => $this->decodeJsonValue($row['return_value_decoded'] ?? null),
            'resourceFeeCharged' => isset($row['resource_fee_charged']) ? (int) $row['resource_fee_charged'] : null,
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
