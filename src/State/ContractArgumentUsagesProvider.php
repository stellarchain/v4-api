<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ContractArgumentUsage;
use App\Repository\ContractArgumentUsageReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<list<ContractArgumentUsage>>
 */
final class ContractArgumentUsagesProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;
    private const MAX_ITEMS_PER_PAGE = 200;
    private const MAX_PARSE_SCAN_MULTIPLIER = 8;

    public function __construct(
        private readonly ContractArgumentUsageReadRepository $readRepository,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $contractId = strtoupper(trim((string) ($uriVariables['contractId'] ?? '')));
        if ($contractId === '') {
            return [];
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $request = $this->requestStack->getCurrentRequest();
        $networkCode = $this->resolveNetworkCode($request, $filters);
        if ($networkCode === null) {
            return [];
        }
        if (!$this->readRepository->contractExists($contractId, $networkCode)) {
            return [];
        }

        $page = max(1, (int) ($request?->query->get('page', $filters['page'] ?? 1) ?? 1));
        $itemsPerPage = $this->normalizeItemsPerPage($request?->query->get('itemsPerPage', $filters['itemsPerPage'] ?? self::DEFAULT_ITEMS_PER_PAGE));
        $beforeId = $this->normalizePositiveInt($request?->query->get('beforeId', $filters['beforeId'] ?? $filters['before_id'] ?? null));

        $rawRows = $this->readRepository->loadCandidateRows(
            $contractId,
            $networkCode,
            $itemsPerPage,
            $page,
            $beforeId,
            self::MAX_PARSE_SCAN_MULTIPLIER
        );

        $usages = [];
        foreach ($rawRows as $row) {
            $matches = $this->mapRowMatches($row);

            $id = (int) ($row['id'] ?? 0);
            $txHash = trim((string) ($row['tx_hash'] ?? ''));
            $targetContractId = trim((string) ($row['target_contract_id'] ?? ''));
            if ($id <= 0 || $txHash === '' || $targetContractId === '') {
                continue;
            }

            $usages[] = new ContractArgumentUsage(
                $id,
                $txHash,
                $targetContractId,
                $this->normalizeNullableString($row['source_account'] ?? null),
                isset($row['ledger']) ? (int) $row['ledger'] : null,
                $this->normalizeCreatedAt($row['created_at'] ?? null),
                isset($row['matches_count']) ? max(1, (int) $row['matches_count']) : count($matches),
                $matches,
            );
        }

        $hasMore = count($usages) > $itemsPerPage;
        if ($hasMore) {
            $usages = array_slice($usages, 0, $itemsPerPage);
        }

        $nextBeforeId = null;
        if ($hasMore && $usages !== []) {
            $last = end($usages);
            if ($last instanceof ContractArgumentUsage) {
                $nextBeforeId = $last->getId();
            }
        }

        $request?->attributes->set('_cursor_meta', [
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'beforeId' => $beforeId,
            'nextBeforeId' => $hasMore ? $nextBeforeId : null,
            'hasMore' => $hasMore,
            'mode' => $beforeId !== null ? 'keyset' : 'offset',
        ]);

        return $usages;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function resolveNetworkCode(?Request $request, array $filters): ?int
    {
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($request?->query->get('network'))
                ? $request?->query->get('network')
                : (is_string($filters['network'] ?? null) ? $filters['network'] : null),
            'mainnet'
        );

        return $this->stellarNetworkResolver->resolveNetworkCode($network);
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array{functionName:string,matchedPaths:list<string>}>
     */
    private function mapRowMatches(array $row): array
    {
        $functionName = trim((string) ($row['function_name'] ?? ''));
        $hostFunctionsRaw = is_string($row['host_functions'] ?? null) ? trim((string) $row['host_functions']) : '';
        if ($functionName === '' && $hostFunctionsRaw !== '') {
            $fallbackMatches = $this->extractMatchesFromHostFunctions($hostFunctionsRaw);
            if ($fallbackMatches !== []) {
                return $fallbackMatches;
            }
        }
        if ($functionName === '') {
            $functionName = 'unknown';
        }

        $decodedPaths = json_decode((string) ($row['matched_paths'] ?? '[]'), true);
        $paths = [];
        if (is_array($decodedPaths)) {
            foreach ($decodedPaths as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $trimmed = trim($path);
                if ($trimmed !== '') {
                    $paths[] = $trimmed;
                }
            }
        }
        $paths = array_values(array_unique($paths));

        if ($paths === []) {
            $paths = ['$.args'];
        }

        return [[
            'functionName' => $functionName,
            'matchedPaths' => $paths,
        ]];
    }

    /**
     * @return list<array{functionName:string,matchedPaths:list<string>}>
     */
    private function extractMatchesFromHostFunctions(string $hostFunctionsJson): array
    {
        $decoded = json_decode($hostFunctionsJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $invokeContracts = is_array($decoded['invokeContracts'] ?? null) ? $decoded['invokeContracts'] : [];
        if ($invokeContracts === []) {
            return [];
        }

        $result = [];
        foreach ($invokeContracts as $call) {
            if (!is_array($call)) {
                continue;
            }
            $functionName = trim((string) ($call['functionName'] ?? ''));
            if ($functionName === '') {
                $functionName = 'unknown';
            }
            $paths = ['$.args'];
            $result[] = [
                'functionName' => $functionName,
                'matchedPaths' => $paths,
            ];
        }

        return $result;
    }

    private function normalizeItemsPerPage(mixed $value): int
    {
        $itemsPerPage = (int) ($value ?? self::DEFAULT_ITEMS_PER_PAGE);
        if ($itemsPerPage <= 0) {
            $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;
        }
        if ($itemsPerPage > self::MAX_ITEMS_PER_PAGE) {
            $itemsPerPage = self::MAX_ITEMS_PER_PAGE;
        }

        return $itemsPerPage;
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

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeCreatedAt(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
