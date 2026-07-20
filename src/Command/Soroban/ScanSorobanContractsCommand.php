<?php

namespace App\Command\Soroban;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Soroban\Requests\EventFilter;
use Soneso\StellarSDK\Soroban\Requests\EventFilters;
use Soneso\StellarSDK\Soroban\Requests\GetEventsRequest;
use Soneso\StellarSDK\Soroban\Requests\PaginationOptions;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcErrorResponse;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcResponse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:soroban:scan-contracts',
    description: 'Scan Soroban RPC events and upsert discovered contracts into local contracts table.',
)]
final class ScanSorobanContractsCommand extends Command
{
    use NetworkOptionTrait;

    private const WINDOW_SIZE_LEDGERS = 15000;
    private const EVENTS_PAGE_SIZE = 200;
    private const FLUSH_EVERY = 500;
    private const PROGRESS_EVERY_PAGES = 20;

    public function __construct(
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'testnet')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger (optional)')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger (optional)')
            ->addOption('max-contracts', null, InputOption::VALUE_REQUIRED, 'Stop after discovering this many new contracts')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Scan only, do not persist into DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $validated = $this->validateInput($input, $io);
        if ($validated === null) {
            return Command::FAILURE;
        }

        $network = $validated['network'];
        $networkCode = $validated['networkCode'];
        $dryRun = $validated['dryRun'];
        $startLedger = $validated['startLedger'];
        $endLedger = $validated['endLedger'];
        $maxContracts = $validated['maxContracts'];

        $server = $this->sorobanServerFactory->create($network);
        $latestPayload = $this->sorobanCall(fn () => $server->getLatestLedger(), 'getLatestLedger');
        $latestLedger = isset($latestPayload['result']['sequence']) ? (int) $latestPayload['result']['sequence'] : null;
        if ($latestLedger === null || $latestLedger < 1) {
            $io->error('Could not read latest ledger from Soroban RPC.');
            return Command::FAILURE;
        }

        $scanStart = $startLedger ?? 1;
        $scanEnd = $endLedger ?? $latestLedger;
        if ($scanStart > $scanEnd) {
            [$scanStart, $scanEnd] = [$scanEnd, $scanStart];
        }
        $scanEnd = min($scanEnd, $latestLedger);
        $totalLedgers = max(0, $scanEnd - $scanStart + 1);
        $totalWindows = $totalLedgers > 0 ? (int) ceil($totalLedgers / self::WINDOW_SIZE_LEDGERS) : 0;

        $knownContracts = $this->loadKnownContracts($networkCode);
        $metrics = [
            'events' => 0,
            'pages' => 0,
            'windows' => 0,
            'unique_seen' => 0,
            'inserted' => 0,
            'existing' => 0,
            'invalid' => 0,
            'range_adjusted' => 0,
        ];

        $io->writeln(sprintf(
            'Network=%s, latestLedger=%d, requestedStart=%s, requestedEnd=%s, effectiveStart=%d, effectiveEnd=%d, windows=%d%s',
            $network,
            $latestLedger,
            $startLedger === null ? 'default(1)' : (string) $startLedger,
            $endLedger === null ? 'default(latest)' : (string) $endLedger,
            $scanStart,
            $scanEnd,
            $totalWindows,
            $dryRun ? ', dry-run=1' : ''
        ));

        $currentEnd = $scanEnd;
        $processedLedgers = 0;
        $pendingFlush = 0;
        $stopRequested = false;
        while ($currentEnd >= $scanStart) {
            $windowStart = max($scanStart, $currentEnd - self::WINDOW_SIZE_LEDGERS + 1);
            $windowEndExclusive = $currentEnd + 1;
            $cursor = null;
            $metrics['windows']++;
            $windowPages = 0;
            $windowEvents = 0;
            $windowContracts = 0;

            $io->writeln(sprintf(
                '[window-start %d/%d] %d..%d',
                $metrics['windows'],
                $totalWindows,
                $windowStart,
                $windowEndExclusive - 1
            ));

            while (true) {
                $request = $this->buildGetEventsRequest($windowStart, $windowEndExclusive, $cursor);

                $eventsPayload = $this->sorobanCall(fn () => $server->getEvents($request), 'getEvents');
                if (($eventsPayload['ok'] ?? false) !== true) {
                    $errorText = $this->buildSorobanErrorText($eventsPayload['error'] ?? null);
                    $allowedStart = $this->extractAllowedStartLedgerFromError($errorText);
                    if (is_int($allowedStart) && $allowedStart > $scanStart) {
                        $scanStart = $allowedStart;
                        $metrics['range_adjusted']++;
                        if ($scanStart > $scanEnd) {
                            break 2;
                        }
                        if ($currentEnd < $scanStart) {
                            $currentEnd = $scanStart;
                        }
                        $io->writeln(sprintf('[range-adjusted] startLedger=%d', $scanStart));
                        continue 2;
                    }

                    $io->error('Soroban getEvents failed: ' . $errorText);
                    return Command::FAILURE;
                }

                $events = is_array($eventsPayload['result']['events'] ?? null)
                    ? $eventsPayload['result']['events']
                    : [];
                if ($events === []) {
                    break;
                }

                $metrics['pages']++;
                $metrics['events'] += count($events);
                $windowPages++;
                $windowEvents += count($events);

                foreach ($events as $event) {
                    if (!is_array($event) || !is_string($event['contractId'] ?? null)) {
                        continue;
                    }

                    $normalized = $this->sorobanContractInspector->normalizeContractId(trim($event['contractId']));
                    if ($normalized === null) {
                        $metrics['invalid']++;
                        continue;
                    }

                    if (isset($knownContracts[$normalized])) {
                        $metrics['existing']++;
                        continue;
                    }

                    $knownContracts[$normalized] = true;
                    $metrics['unique_seen']++;

                    if ($dryRun) {
                        $metrics['inserted']++;
                        $windowContracts++;
                        if ($this->isMaxContractsReached($maxContracts, $metrics['unique_seen'])) {
                            $stopRequested = true;
                            break;
                        }
                        continue;
                    }

                    $this->persistDiscoveredContract($normalized, $networkCode);
                    $pendingFlush++;
                    $metrics['inserted']++;
                    $windowContracts++;
                    if ($this->isMaxContractsReached($maxContracts, $metrics['unique_seen'])) {
                        $stopRequested = true;
                        break;
                    }

                    if ($pendingFlush >= self::FLUSH_EVERY) {
                        $this->flushAndClear();
                        $pendingFlush = 0;
                    }
                }
                if ($stopRequested) {
                    break;
                }

                $nextCursor = $this->resolveNextCursor($eventsPayload, $events);

                if (count($events) < self::EVENTS_PAGE_SIZE || !is_string($nextCursor) || $nextCursor === $cursor) {
                    break;
                }
                $cursor = $nextCursor;

                if ($windowPages % self::PROGRESS_EVERY_PAGES === 0) {
                    $pageLedgerRange = $this->resolvePageLedgerRange($events);
                    $io->writeln(sprintf(
                        '[progress] window=%d/%d scanLedgers=%d..%d eventLedgers=%s pages=%d events=%d new=%d totalPages=%d totalEvents=%d discovered=%d inserted=%d',
                        $metrics['windows'],
                        $totalWindows,
                        $windowStart,
                        $windowEndExclusive - 1,
                        $pageLedgerRange,
                        $windowPages,
                        $windowEvents,
                        $windowContracts,
                        $metrics['pages'],
                        $metrics['events'],
                        $metrics['unique_seen'],
                        $metrics['inserted'],
                    ));
                }
            }
            if ($stopRequested) {
                break;
            }

            $processedLedgers += ($windowEndExclusive - $windowStart);

            $io->writeln(sprintf(
                '[window %d/%d done] %d..%d | ledgers=%d/%d pages=%d events=%d new=%d inserted=%d unique=%d',
                $metrics['windows'],
                $totalWindows,
                $windowStart,
                $windowEndExclusive - 1,
                $processedLedgers,
                $totalLedgers,
                $windowPages,
                $windowEvents,
                $windowContracts,
                $metrics['inserted'],
                $metrics['unique_seen'],
            ));

            $currentEnd = $windowStart - 1;
        }

        if (!$dryRun && $pendingFlush > 0) {
            $this->flushAndClear();
        }

        $io->newLine();
        $io->table(
            ['Metric', 'Value'],
            [
                ['events', (string) $metrics['events']],
                ['pages', (string) $metrics['pages']],
                ['windows', (string) $metrics['windows']],
                ['unique_seen', (string) $metrics['unique_seen']],
                ['inserted', (string) $metrics['inserted']],
                ['existing', (string) $metrics['existing']],
                ['invalid', (string) $metrics['invalid']],
                ['range_adjusted', (string) $metrics['range_adjusted']],
            ]
        );

        $io->success($dryRun ? 'Dry-run completed.' : 'Contracts sync completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string,bool>
     */
    private function loadKnownContracts(int $networkCode): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT contract_id FROM contracts WHERE network = :network',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER],
        );

        $known = [];
        foreach ($rows as $value) {
            if (is_string($value) && $value !== '') {
                $known[$value] = true;
            }
        }

        return $known;
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{network:string,networkCode:int,dryRun:bool,startLedger:?int,endLedger:?int,maxContracts:?int}|null
     */
    private function validateInput(InputInterface $input, SymfonyStyle $io): ?array
    {
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $startLedgerOption = $input->getOption('start-ledger');
        $endLedgerOption = $input->getOption('end-ledger');
        $maxContractsOption = $input->getOption('max-contracts');

        if (($startLedgerOption === null) !== ($endLedgerOption === null)) {
            $io->error('Use both --start-ledger and --end-ledger together.');
            return null;
        }

        $startLedger = $this->parsePositiveIntOption($startLedgerOption);
        if ($startLedgerOption !== null && $startLedger === null) {
            $io->error('Option --start-ledger must be a positive integer.');
            return null;
        }

        $endLedger = $this->parsePositiveIntOption($endLedgerOption);
        if ($endLedgerOption !== null && $endLedger === null) {
            $io->error('Option --end-ledger must be a positive integer.');
            return null;
        }

        $maxContracts = $this->parsePositiveIntOption($maxContractsOption);
        if ($maxContractsOption !== null && $maxContracts === null) {
            $io->error('Option --max-contracts must be a positive integer.');
            return null;
        }

        return [
            'network' => $network,
            'networkCode' => $networkCode,
            'dryRun' => (bool) $input->getOption('dry-run'),
            'startLedger' => $startLedger,
            'endLedger' => $endLedger,
            'maxContracts' => $maxContracts,
        ];
    }

    private function parsePositiveIntOption(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function sorobanCall(callable $callback, string $method): array
    {
        $payload = ['method' => $method];
        try {
            /** @var SorobanRpcResponse $response */
            $response = $callback();
            $result = $response->getJsonResponse()['result'] ?? null;
            $error = $response->getError();
            if ($error instanceof SorobanRpcErrorResponse) {
                $payload['ok'] = false;
                $payload['error'] = $this->sorobanErrorToArray($error);
            } else {
                $payload['ok'] = true;
                $payload['result'] = $result;
            }
        } catch (\Throwable $e) {
            $payload['ok'] = false;
            $payload['error'] = ['message' => $e->getMessage()];
        }

        return $payload;
    }

    /**
     * @param mixed $error
     */
    private function buildSorobanErrorText(mixed $error): string
    {
        if (!is_array($error)) {
            return 'Unknown Soroban RPC error';
        }

        $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        $data = $error['data'] ?? null;
        if (is_string($data) && $data !== '') {
            return $message !== '' ? ($message . ' | ' . $data) : $data;
        }

        return $message !== '' ? $message : 'Unknown Soroban RPC error';
    }

    /**
     * @param mixed $error
     * @return array<string,mixed>
     */
    private function sorobanErrorToArray(mixed $error): array
    {
        if (!$error instanceof SorobanRpcErrorResponse) {
            return ['message' => 'Unknown Soroban RPC error'];
        }

        return [
            'code' => $error->getCode(),
            'message' => $error->getMessage(),
            'data' => $error->getData(),
        ];
    }

    private function extractAllowedStartLedgerFromError(string $errorText): ?int
    {
        if (preg_match('/ledger range:\s*([0-9]+)\s*-\s*([0-9]+)/i', $errorText, $matches) !== 1) {
            return null;
        }

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    private function buildGetEventsRequest(int $windowStart, int $windowEndExclusive, ?string $cursor): GetEventsRequest
    {
        if ($cursor === null) {
            return new GetEventsRequest(
                startLedger: $windowStart,
                endLedger: $windowEndExclusive,
                filters: new EventFilters(new EventFilter(type: 'contract')),
                paginationOptions: new PaginationOptions(limit: self::EVENTS_PAGE_SIZE),
            );
        }

        return new GetEventsRequest(
            filters: new EventFilters(new EventFilter(type: 'contract')),
            paginationOptions: new PaginationOptions(cursor: $cursor, limit: self::EVENTS_PAGE_SIZE),
        );
    }

    /**
     * @param array<string,mixed> $eventsPayload
     * @param array<int,mixed> $events
     */
    private function resolveNextCursor(array $eventsPayload, array $events): ?string
    {
        $nextCursor = $eventsPayload['result']['cursor'] ?? null;
        if (is_string($nextCursor) && $nextCursor !== '') {
            return $nextCursor;
        }

        $lastEvent = $events[array_key_last($events)] ?? null;
        if (is_array($lastEvent) && is_string($lastEvent['id'] ?? null) && $lastEvent['id'] !== '') {
            return $lastEvent['id'];
        }

        return null;
    }

    private function persistDiscoveredContract(string $contractId, int $networkCode): void
    {
        $hex = $this->decodeContractIdHexOrNull($contractId);

        $this->connection->executeStatement(
            $this->buildInsertContractIgnoreSql(),
            [
                'contract_id' => $contractId,
                'contract_id_hex' => $hex,
                'network' => $networkCode,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            [
                'contract_id_hex' => $hex !== null ? ParameterType::STRING : ParameterType::NULL,
                'network' => ParameterType::INTEGER,
                'created_at' => ParameterType::STRING,
            ]
        );
    }

    private function flushAndClear(): void
    {
        // No-op: contracts are inserted immediately through DBAL for the dedicated contracts DB.
    }

    private function buildInsertContractIgnoreSql(): string
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return 'INSERT INTO contracts (contract_id, contract_id_hex, network, created_at)
                    VALUES (:contract_id, :contract_id_hex, :network, :created_at)
                    ON CONFLICT (contract_id, network) DO NOTHING';
        }

        return 'INSERT INTO contracts (contract_id, contract_id_hex, network, created_at)
                VALUES (:contract_id, :contract_id_hex, :network, :created_at)
                ON DUPLICATE KEY UPDATE contract_id = contract_id';
    }

    private function isMaxContractsReached(?int $maxContracts, int $uniqueSeen): bool
    {
        return $maxContracts !== null && $uniqueSeen >= $maxContracts;
    }

    /**
     * @param array<int,mixed> $events
     */
    private function resolvePageLedgerRange(array $events): string
    {
        $min = null;
        $max = null;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $ledger = $event['ledger'] ?? $event['ledgerSeq'] ?? null;
            if (!is_numeric($ledger)) {
                continue;
            }

            $value = (int) $ledger;
            if ($value <= 0) {
                continue;
            }

            $min = $min === null ? $value : min($min, $value);
            $max = $max === null ? $value : max($max, $value);
        }

        if ($min === null || $max === null) {
            return 'n/a';
        }

        return sprintf('%d..%d', $min, $max);
    }
}
