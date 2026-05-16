<?php

declare(strict_types=1);

namespace App\Service\Trace;

use App\Exception\StatisticsUnavailableException;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentFlowInvestigationReadService implements PaymentFlowInvestigationReadServiceInterface
{
    private const REQUIRED_TABLES = [
        'payment_flow_event',
        'payment_flow_transaction',
        'payment_flow_address',
        'payment_flow_asset',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly StellarNetworkResolver $networkResolver,
        private readonly PaymentFlowAccountMetadataReadServiceInterface $accountMetadataReadService,
    ) {
    }

    public function read(
        string $network,
        ?string $address,
        ?string $txHash,
        ?int $ledgerFrom,
        ?int $ledgerTo,
        string $direction,
        int $limit
    ): array {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'mainnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $address = $this->normalizeNullableString($address);
        $txHash = $this->normalizeNullableString($txHash);

        try {
            foreach (self::REQUIRED_TABLES as $table) {
                if (!$this->tableExists($table)) {
                    throw new StatisticsUnavailableException(sprintf('Statistics table %s is not available.', $table));
                }
            }

            $addressId = $address !== null ? $this->loadAddressId($networkCode, $address) : null;
            $txId = $txHash !== null ? $this->loadTransactionId($networkCode, $txHash) : null;

            if (($address !== null && $addressId === null) || ($txHash !== null && $txId === null)) {
                [$accounts, $accountMetadataUnavailable] = $this->loadAccountMetadata($networkCode, $address !== null ? [$address] : []);

                return $this->emptyPayload($normalizedNetwork, $address, $txHash, $ledgerFrom, $ledgerTo, $direction, $limit, $accounts, $accountMetadataUnavailable);
            }

            $rows = $this->loadEvents($networkCode, $addressId, $txId, $ledgerFrom, $ledgerTo, $direction, $limit + 1);
        } catch (StatisticsUnavailableException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new StatisticsUnavailableException('Payment flow statistics database is unavailable.', 0, $exception);
        }

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $events = array_map(fn (array $row): array => $this->normalizeEvent($row, $address), $rows);
        [$accounts, $accountMetadataUnavailable] = $this->loadAccountMetadata($networkCode, $this->collectAccountAddresses($events, $address));
        $events = $this->enrichEventsWithAccountMetadata($events, $accounts);
        $summary = $this->buildSummary($events, $address);
        $riskContext = $this->buildRiskContext($summary, $events, $address !== null, $accountMetadataUnavailable);

        return [
            'network' => $normalizedNetwork,
            'query' => [
                'address' => $address,
                'txHash' => $txHash,
                'ledgerFrom' => $ledgerFrom,
                'ledgerTo' => $ledgerTo,
                'direction' => $direction,
                'limit' => $limit,
            ],
            'coverage' => [
                'rowsReturned' => count($events),
                'hasMore' => $hasMore,
                'firstLedger' => $summary['firstLedger'],
                'lastLedger' => $summary['lastLedger'],
                'firstClosedAt' => $summary['firstClosedAt'],
                'lastClosedAt' => $summary['lastClosedAt'],
                'isPartial' => $hasMore,
            ],
            'summary' => $summary,
            'riskContext' => $riskContext,
            'accounts' => $accounts,
            'accountContext' => $this->buildAccountContext($address, $accounts, $accountMetadataUnavailable),
            'graph' => $this->buildGraph($events, $address, $accounts),
            'counterparties' => $this->buildCounterparties($events, $address, $accounts),
            'events' => $events,
        ];
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->statisticsConnection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }

    private function loadAddressId(int $networkCode, string $address): ?int
    {
        $id = $this->statisticsConnection->fetchOne(
            'SELECT id FROM payment_flow_address WHERE network = :network AND address = :address',
            [
                'network' => $networkCode,
                'address' => $address,
            ],
            [
                'network' => ParameterType::INTEGER,
                'address' => ParameterType::STRING,
            ]
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    private function loadTransactionId(int $networkCode, string $txHash): ?int
    {
        $id = $this->statisticsConnection->fetchOne(
            'SELECT id FROM payment_flow_transaction WHERE network = :network AND tx_hash = :tx_hash',
            [
                'network' => $networkCode,
                'tx_hash' => strtolower($txHash),
            ],
            [
                'network' => ParameterType::INTEGER,
                'tx_hash' => ParameterType::STRING,
            ]
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadEvents(
        int $networkCode,
        ?int $addressId,
        ?int $txId,
        ?int $ledgerFrom,
        ?int $ledgerTo,
        string $direction,
        int $limit
    ): array {
        $where = ['e.network = :network'];
        $params = [
            'network' => $networkCode,
            'limit' => $limit,
        ];
        $types = [
            'network' => ParameterType::INTEGER,
            'limit' => ParameterType::INTEGER,
        ];

        if ($addressId !== null) {
            $params['address_id'] = $addressId;
            $types['address_id'] = ParameterType::INTEGER;

            if ($direction === 'incoming') {
                $where[] = 'e.to_address_id = :address_id';
            } elseif ($direction === 'outgoing') {
                $where[] = 'e.from_address_id = :address_id';
            } else {
                $where[] = '(e.from_address_id = :address_id OR e.to_address_id = :address_id)';
            }
        }

        if ($txId !== null) {
            $where[] = 'e.tx_id = :tx_id';
            $params['tx_id'] = $txId;
            $types['tx_id'] = ParameterType::INTEGER;
        }

        if ($ledgerFrom !== null) {
            $where[] = 'e.ledger >= :ledger_from';
            $params['ledger_from'] = $ledgerFrom;
            $types['ledger_from'] = ParameterType::INTEGER;
        }

        if ($ledgerTo !== null) {
            $where[] = 'e.ledger <= :ledger_to';
            $params['ledger_to'] = $ledgerTo;
            $types['ledger_to'] = ParameterType::INTEGER;
        }

        $sql = sprintf(
            <<<'SQL'
SELECT
    e.id,
    e.ledger,
    e.operation_id,
    e.operation_index,
    e.operation_type,
    e.successful,
    e.source_amount_decimal,
    e.destination_amount_decimal,
    t.closed_at,
    t.tx_hash,
    t.memo_type,
    t.memo,
    source_account.address AS source_account,
    from_address.address AS from_address,
    to_address.address AS to_address,
    source_asset.asset_type AS source_asset_type,
    source_asset.asset_code AS source_asset_code,
    source_asset.asset_issuer AS source_asset_issuer,
    destination_asset.asset_type AS destination_asset_type,
    destination_asset.asset_code AS destination_asset_code,
    destination_asset.asset_issuer AS destination_asset_issuer
FROM payment_flow_event e
INNER JOIN payment_flow_transaction t ON t.id = e.tx_id
LEFT JOIN payment_flow_address source_account ON source_account.id = e.source_account_id
LEFT JOIN payment_flow_address from_address ON from_address.id = e.from_address_id
LEFT JOIN payment_flow_address to_address ON to_address.id = e.to_address_id
LEFT JOIN payment_flow_asset source_asset ON source_asset.id = e.source_asset_id
LEFT JOIN payment_flow_asset destination_asset ON destination_asset.id = e.destination_asset_id
WHERE %s
ORDER BY e.ledger DESC, e.id DESC
LIMIT :limit
SQL,
            implode(' AND ', $where)
        );

        return $this->statisticsConnection->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeEvent(array $row, ?string $focusAddress): array
    {
        $fromAddress = $this->normalizeNullableString($row['from_address'] ?? null);
        $toAddress = $this->normalizeNullableString($row['to_address'] ?? null);
        $sourceAsset = $this->formatAsset(
            $this->normalizeNullableString($row['source_asset_type'] ?? null) ?? 'native',
            $this->normalizeNullableString($row['source_asset_code'] ?? null),
            $this->normalizeNullableString($row['source_asset_issuer'] ?? null)
        );
        $destinationAsset = $this->formatAsset(
            $this->normalizeNullableString($row['destination_asset_type'] ?? null) ?? 'native',
            $this->normalizeNullableString($row['destination_asset_code'] ?? null),
            $this->normalizeNullableString($row['destination_asset_issuer'] ?? null)
        );

        $direction = 'related';
        $counterparty = null;
        if ($focusAddress !== null) {
            if ($toAddress === $focusAddress) {
                $direction = 'incoming';
                $counterparty = $fromAddress;
            } elseif ($fromAddress === $focusAddress) {
                $direction = 'outgoing';
                $counterparty = $toAddress;
            }
        }

        return [
            'id' => (string) $row['id'],
            'ledger' => (int) $row['ledger'],
            'closedAt' => $this->formatAtom($row['closed_at'] ?? null),
            'txHash' => (string) ($row['tx_hash'] ?? ''),
            'operationId' => (string) ($row['operation_id'] ?? ''),
            'operationIndex' => $row['operation_index'] === null ? null : (int) $row['operation_index'],
            'operationType' => (string) ($row['operation_type'] ?? ''),
            'successful' => (bool) $row['successful'],
            'direction' => $direction,
            'counterparty' => $counterparty,
            'sourceAccount' => $this->normalizeNullableString($row['source_account'] ?? null),
            'fromAddress' => $fromAddress,
            'toAddress' => $toAddress,
            'sourceAsset' => $sourceAsset,
            'sourceAmount' => $this->normalizeDecimal($row['source_amount_decimal'] ?? null),
            'destinationAsset' => $destinationAsset,
            'destinationAmount' => $this->normalizeDecimal($row['destination_amount_decimal'] ?? null),
            'memoType' => $this->normalizeNullableString($row['memo_type'] ?? null),
            'memo' => $this->normalizeNullableString($row['memo'] ?? null),
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildSummary(array $events, ?string $focusAddress): array
    {
        $ledgerValues = [];
        $closedAtValues = [];
        $transactions = [];
        $counterparties = [];
        $assets = [];
        $incoming = 0;
        $outgoing = 0;
        $pathPayments = 0;
        $accountMerges = 0;
        $createAccounts = 0;
        $memoCount = 0;
        $nativeReceived = 0.0;
        $nativeSent = 0.0;

        foreach ($events as $event) {
            $ledgerValues[] = (int) $event['ledger'];
            if (is_string($event['closedAt'])) {
                $closedAtValues[] = $event['closedAt'];
            }
            $transactions[(string) $event['txHash']] = true;

            if ($event['direction'] === 'incoming') {
                $incoming++;
                if (($event['destinationAsset']['type'] ?? '') === 'native') {
                    $nativeReceived += (float) ((string) ($event['destinationAmount'] ?? '0'));
                }
            } elseif ($event['direction'] === 'outgoing') {
                $outgoing++;
                if (($event['sourceAsset']['type'] ?? '') === 'native') {
                    $nativeSent += (float) ((string) ($event['sourceAmount'] ?? '0'));
                }
            }

            if (is_string($event['counterparty']) && $event['counterparty'] !== '') {
                $counterparties[$event['counterparty']] = true;
            }

            foreach (['sourceAsset', 'destinationAsset'] as $assetField) {
                $asset = $event[$assetField] ?? null;
                if (is_array($asset)) {
                    $assets[(string) $asset['key']] = true;
                }
            }

            $operationType = (string) $event['operationType'];
            if (str_starts_with($operationType, 'path_payment')) {
                $pathPayments++;
            } elseif ($operationType === 'account_merge') {
                $accountMerges++;
            } elseif ($operationType === 'create_account') {
                $createAccounts++;
            }

            if (($event['memo'] ?? null) !== null) {
                $memoCount++;
            }
        }

        sort($closedAtValues);

        return [
            'focusAddress' => $focusAddress,
            'events' => count($events),
            'transactions' => count($transactions),
            'incomingEvents' => $incoming,
            'outgoingEvents' => $outgoing,
            'uniqueCounterparties' => count($counterparties),
            'uniqueAssets' => count($assets),
            'pathPayments' => $pathPayments,
            'accountMerges' => $accountMerges,
            'createAccounts' => $createAccounts,
            'memoCount' => $memoCount,
            'nativeReceived' => $this->normalizeNumber($nativeReceived),
            'nativeSent' => $this->normalizeNumber($nativeSent),
            'firstLedger' => $ledgerValues === [] ? null : min($ledgerValues),
            'lastLedger' => $ledgerValues === [] ? null : max($ledgerValues),
            'firstClosedAt' => $closedAtValues[0] ?? null,
            'lastClosedAt' => $closedAtValues === [] ? null : $closedAtValues[array_key_last($closedAtValues)],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildRiskContext(array $summary, array $events, bool $hasAddressFocus, bool $accountMetadataUnavailable = false): array
    {
        $score = 0;
        $signals = [];
        $eventCount = (int) $summary['events'];
        $incoming = (int) $summary['incomingEvents'];
        $outgoing = (int) $summary['outgoingEvents'];
        $uniqueCounterparties = (int) $summary['uniqueCounterparties'];
        $pathPayments = (int) $summary['pathPayments'];
        $accountMerges = (int) $summary['accountMerges'];

        if ($eventCount === 0) {
            return [
                'level' => 'limited',
                'score' => 0,
                'signals' => [[
                    'severity' => 'info',
                    'label' => 'No collected payment-flow rows',
                    'description' => 'This address or transaction is not present in the currently indexed payment-flow dataset.',
                ]],
                'guidance' => $this->guidance(),
                'limitations' => $this->limitations($accountMetadataUnavailable),
            ];
        }

        if ($uniqueCounterparties >= 25) {
            $score += 25;
            $signals[] = [
                'severity' => 'medium',
                'label' => 'Many counterparties',
                'description' => sprintf('%d distinct counterparties appear in the returned sample.', $uniqueCounterparties),
            ];
        }

        if ($hasAddressFocus && $outgoing >= 10 && $incoming > 0 && $outgoing >= $incoming * 3) {
            $score += 20;
            $signals[] = [
                'severity' => 'medium',
                'label' => 'Outgoing distribution pattern',
                'description' => 'Outgoing transfers substantially outnumber incoming transfers in this sample.',
            ];
        }

        if ($hasAddressFocus && $incoming >= 10 && $outgoing > 0 && $incoming >= $outgoing * 3) {
            $score += 20;
            $signals[] = [
                'severity' => 'medium',
                'label' => 'Incoming collection pattern',
                'description' => 'Incoming transfers substantially outnumber outgoing transfers in this sample.',
            ];
        }

        if ($eventCount > 0 && ($pathPayments / $eventCount) >= 0.35) {
            $score += 15;
            $signals[] = [
                'severity' => 'info',
                'label' => 'High path-payment usage',
                'description' => 'A large share of returned events involves path payments or asset conversion.',
            ];
        }

        if ($accountMerges > 0) {
            $score += 10;
            $signals[] = [
                'severity' => 'info',
                'label' => 'Account merge observed',
                'description' => sprintf('%d account merge event%s found in this sample.', $accountMerges, $accountMerges === 1 ? '' : 's'),
            ];
        }

        $spanHours = $this->spanHours($summary['firstClosedAt'], $summary['lastClosedAt']);
        if ($spanHours !== null && $spanHours <= 6.0 && $eventCount >= 25) {
            $score += 20;
            $signals[] = [
                'severity' => 'medium',
                'label' => 'Dense activity window',
                'description' => 'Many payment events are clustered into a short time window.',
            ];
        }

        if ($signals === []) {
            $signals[] = [
                'severity' => 'info',
                'label' => 'No strong pattern in returned sample',
                'description' => 'The returned payment-flow rows do not trigger the current heuristic risk signals.',
            ];
        }

        $level = $score >= 60 ? 'elevated' : ($score >= 30 ? 'review' : 'context');

        return [
            'level' => $level,
            'score' => min($score, 100),
            'signals' => $signals,
            'guidance' => $this->guidance(),
            'limitations' => $this->limitations($accountMetadataUnavailable),
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildGraph(array $events, ?string $focusAddress, array $accounts): array
    {
        $nodes = [];
        $edges = [];

        if ($focusAddress !== null) {
            $nodes[$focusAddress] = [
                'id' => $focusAddress,
                'label' => $this->accountDisplayLabel($focusAddress, $accounts),
                'role' => 'focus',
                'events' => 0,
                'incoming' => 0,
                'outgoing' => 0,
                'account' => $this->accountMetadataFor($focusAddress, $accounts),
            ];
        }

        foreach ($events as $event) {
            $from = $event['fromAddress'] ?? null;
            $to = $event['toAddress'] ?? null;
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                continue;
            }

            foreach ([[$from, 'source'], [$to, 'destination']] as [$address, $role]) {
                $nodes[$address] ??= [
                    'id' => $address,
                    'label' => $this->accountDisplayLabel($address, $accounts),
                    'role' => $address === $focusAddress ? 'focus' : $role,
                    'events' => 0,
                    'incoming' => 0,
                    'outgoing' => 0,
                    'account' => $this->accountMetadataFor($address, $accounts),
                ];
                $nodes[$address]['events']++;
            }

            $nodes[$from]['outgoing']++;
            $nodes[$to]['incoming']++;

            $edgeKey = sprintf('%s>%s', $from, $to);
            $edges[$edgeKey] ??= [
                'source' => $from,
                'target' => $to,
                'count' => 0,
                'totalXlm' => 0.0,
                'assets' => [],
                'latestLedger' => 0,
                'latestClosedAt' => null,
            ];

            $edges[$edgeKey]['count']++;
            $asset = $event['destinationAsset'] ?? null;
            if (is_array($asset)) {
                $edges[$edgeKey]['assets'][(string) $asset['display']] = true;
                if (($asset['type'] ?? '') === 'native') {
                    $edges[$edgeKey]['totalXlm'] += (float) ((string) ($event['destinationAmount'] ?? '0'));
                }
            }
            if ((int) $event['ledger'] > (int) $edges[$edgeKey]['latestLedger']) {
                $edges[$edgeKey]['latestLedger'] = (int) $event['ledger'];
                $edges[$edgeKey]['latestClosedAt'] = $event['closedAt'];
            }
        }

        $edgeRows = array_values($edges);
        usort($edgeRows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $edgeRows = array_slice($edgeRows, 0, 60);

        return [
            'nodes' => array_values($nodes),
            'edges' => array_map(function (array $edge): array {
                $edge['totalXlm'] = $this->normalizeNumber((float) $edge['totalXlm']);
                $edge['assets'] = array_keys($edge['assets']);

                return $edge;
            }, $edgeRows),
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function buildCounterparties(array $events, ?string $focusAddress, array $accounts): array
    {
        if ($focusAddress === null) {
            return [];
        }

        $counterparties = [];
        foreach ($events as $event) {
            $address = $event['counterparty'] ?? null;
            if (!is_string($address) || $address === '') {
                continue;
            }

            $counterparties[$address] ??= [
                'address' => $address,
                'events' => 0,
                'incoming' => 0,
                'outgoing' => 0,
                'firstLedger' => null,
                'lastLedger' => null,
                'firstClosedAt' => null,
                'lastClosedAt' => null,
                'nativeReceived' => 0.0,
                'nativeSent' => 0.0,
                'assets' => [],
            ];

            $counterparties[$address]['events']++;
            $ledger = (int) $event['ledger'];
            $counterparties[$address]['firstLedger'] = min($counterparties[$address]['firstLedger'] ?? $ledger, $ledger);
            $counterparties[$address]['lastLedger'] = max($counterparties[$address]['lastLedger'] ?? $ledger, $ledger);

            if (($event['closedAt'] ?? null) !== null) {
                $closedAt = (string) $event['closedAt'];
                $counterparties[$address]['firstClosedAt'] = $this->minDateString($counterparties[$address]['firstClosedAt'], $closedAt);
                $counterparties[$address]['lastClosedAt'] = $this->maxDateString($counterparties[$address]['lastClosedAt'], $closedAt);
            }

            if ($event['direction'] === 'incoming') {
                $counterparties[$address]['incoming']++;
                if (($event['destinationAsset']['type'] ?? '') === 'native') {
                    $counterparties[$address]['nativeReceived'] += (float) ((string) ($event['destinationAmount'] ?? '0'));
                }
            } elseif ($event['direction'] === 'outgoing') {
                $counterparties[$address]['outgoing']++;
                if (($event['sourceAsset']['type'] ?? '') === 'native') {
                    $counterparties[$address]['nativeSent'] += (float) ((string) ($event['sourceAmount'] ?? '0'));
                }
            }

            foreach (['sourceAsset', 'destinationAsset'] as $assetField) {
                $asset = $event[$assetField] ?? null;
                if (is_array($asset)) {
                    $counterparties[$address]['assets'][(string) $asset['display']] = true;
                }
            }
        }

        $rows = array_values($counterparties);
        usort($rows, static fn (array $a, array $b): int => $b['events'] <=> $a['events']);

        return array_map(function (array $row) use ($accounts): array {
            $row['nativeReceived'] = $this->normalizeNumber((float) $row['nativeReceived']);
            $row['nativeSent'] = $this->normalizeNumber((float) $row['nativeSent']);
            $row['assets'] = array_slice(array_keys($row['assets']), 0, 8);
            $row['account'] = $this->accountMetadataFor((string) $row['address'], $accounts);

            return $row;
        }, array_slice($rows, 0, 25));
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPayload(
        string $network,
        ?string $address,
        ?string $txHash,
        ?int $ledgerFrom,
        ?int $ledgerTo,
        string $direction,
        int $limit,
        array $accounts = [],
        bool $accountMetadataUnavailable = false
    ): array {
        $summary = $this->buildSummary([], $address);

        return [
            'network' => $network,
            'query' => [
                'address' => $address,
                'txHash' => $txHash,
                'ledgerFrom' => $ledgerFrom,
                'ledgerTo' => $ledgerTo,
                'direction' => $direction,
                'limit' => $limit,
            ],
            'coverage' => [
                'rowsReturned' => 0,
                'hasMore' => false,
                'firstLedger' => null,
                'lastLedger' => null,
                'firstClosedAt' => null,
                'lastClosedAt' => null,
                'isPartial' => false,
            ],
            'summary' => $summary,
            'riskContext' => $this->buildRiskContext($summary, [], $address !== null, $accountMetadataUnavailable),
            'accounts' => $accounts,
            'accountContext' => $this->buildAccountContext($address, $accounts, $accountMetadataUnavailable),
            'graph' => ['nodes' => [], 'edges' => []],
            'counterparties' => [],
            'events' => [],
        ];
    }

    /**
     * @return array{key:string,type:string,code:string,issuer:?string,display:string}
     */
    private function formatAsset(string $type, ?string $code, ?string $issuer): array
    {
        $code = $code ?? '';
        $issuer = $issuer ?? '';
        if ($type === 'native') {
            return [
                'key' => 'native:XLM',
                'type' => 'native',
                'code' => 'XLM',
                'issuer' => null,
                'display' => 'XLM',
            ];
        }

        return [
            'key' => sprintf('%s:%s:%s', $type, $code, $issuer),
            'type' => $type,
            'code' => $code,
            'issuer' => $issuer === '' ? null : $issuer,
            'display' => $code !== '' ? $code : $type,
        ];
    }

    /**
     * @return list<string>
     */
    private function guidance(): array
    {
        return [
            'Preserve the transaction hash, destination address, memo, amount, and timestamp before contacting an exchange, wallet provider, or law-enforcement channel.',
            'Do not send additional funds to a destination until the operator is verified through an independent trusted channel.',
            'For misdirected payments, use the memo and transaction hash as the evidence packet when contacting the destination service.',
        ];
    }

    /**
     * @return list<string>
     */
    private function limitations(bool $accountMetadataUnavailable = false): array
    {
        $limitations = [
            'Signals are heuristic and are not a final fraud determination.',
            'Coverage depends on how much historical payment-flow data has already been backfilled.',
            'Asset values are not converted to USD in this view.',
        ];

        if ($accountMetadataUnavailable) {
            $limitations[] = 'Account labels and first/last transaction metadata are temporarily unavailable from the main application database.';
        }

        return $limitations;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<string>
     */
    private function collectAccountAddresses(array $events, ?string $focusAddress): array
    {
        $addresses = [];
        if ($focusAddress !== null) {
            $addresses[$focusAddress] = true;
        }

        foreach ($events as $event) {
            foreach (['sourceAccount', 'fromAddress', 'toAddress', 'counterparty'] as $field) {
                $address = $event[$field] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[$address] = true;
                }
            }
        }

        return array_keys($addresses);
    }

    /**
     * @param list<string> $addresses
     * @return array{0:array<string,array<string,mixed>>,1:bool}
     */
    private function loadAccountMetadata(int $networkCode, array $addresses): array
    {
        if ($addresses === []) {
            return [[], false];
        }

        try {
            return [$this->accountMetadataReadService->readByAddresses($networkCode, $addresses), false];
        } catch (\Throwable) {
            return [[], true];
        }
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,array<string,mixed>> $accounts
     * @return list<array<string,mixed>>
     */
    private function enrichEventsWithAccountMetadata(array $events, array $accounts): array
    {
        return array_map(function (array $event) use ($accounts): array {
            $event['sourceAccountMetadata'] = $this->accountMetadataFor($event['sourceAccount'] ?? null, $accounts);
            $event['fromAccount'] = $this->accountMetadataFor($event['fromAddress'] ?? null, $accounts);
            $event['toAccount'] = $this->accountMetadataFor($event['toAddress'] ?? null, $accounts);
            $event['counterpartyAccount'] = $this->accountMetadataFor($event['counterparty'] ?? null, $accounts);

            return $event;
        }, $events);
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     * @return array<string,mixed>
     */
    private function buildAccountContext(?string $focusAddress, array $accounts, bool $accountMetadataUnavailable): array
    {
        $labeled = 0;
        $verified = 0;
        foreach ($accounts as $account) {
            if (($account['label'] ?? null) !== null) {
                $labeled++;
            }
            if (($account['verified'] ?? false) === true) {
                $verified++;
            }
        }

        return [
            'metadataAvailable' => !$accountMetadataUnavailable,
            'metadataUnavailable' => $accountMetadataUnavailable,
            'knownAccounts' => count($accounts),
            'labeledAccounts' => $labeled,
            'verifiedAccounts' => $verified,
            'focusAccount' => $this->accountMetadataFor($focusAddress, $accounts),
            'note' => 'Account labels are directory context only and do not change the risk score.',
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     * @return array<string,mixed>|null
     */
    private function accountMetadataFor(mixed $address, array $accounts): ?array
    {
        if (!is_string($address) || trim($address) === '') {
            return null;
        }

        $address = strtoupper(trim($address));

        return $accounts[$address] ?? null;
    }

    /**
     * @param array<string,array<string,mixed>> $accounts
     */
    private function accountDisplayLabel(string $address, array $accounts): string
    {
        $metadata = $this->accountMetadataFor($address, $accounts);
        $label = is_array($metadata) ? $this->normalizeNullableString($metadata['label'] ?? null) : null;

        return $label ?? $this->shortenAddress($address);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeNumber((float) (string) $value);
    }

    private function normalizeNumber(float $value): string
    {
        $normalized = number_format($value, 14, '.', '');
        $normalized = rtrim($normalized, '0');

        return rtrim($normalized, '.') ?: '0';
    }

    private function formatAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function spanHours(mixed $start, mixed $end): ?float
    {
        if (!is_string($start) || !is_string($end)) {
            return null;
        }

        try {
            $startDate = new \DateTimeImmutable($start);
            $endDate = new \DateTimeImmutable($end);
        } catch (\Throwable) {
            return null;
        }

        return max(0, $endDate->getTimestamp() - $startDate->getTimestamp()) / 3600;
    }

    private function minDateString(?string $current, string $candidate): string
    {
        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function maxDateString(?string $current, string $candidate): string
    {
        return $current === null || $candidate > $current ? $candidate : $current;
    }

    private function shortenAddress(string $address): string
    {
        return strlen($address) <= 16 ? $address : sprintf('%s...%s', substr($address, 0, 6), substr($address, -6));
    }
}
