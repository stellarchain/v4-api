<?php

declare(strict_types=1);

namespace App\Service\ContractRead;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContractReadGateway
{
    private const MAX_PAGE_READS = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ContractReadMapper $mapper,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(default:app.orion_base_url:ORION_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(bool:default:app.true_string:ORION_CONTRACT_READS_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function findContract(string $contractId, string $network, ?string $expectedWasmHash = null): ?array
    {
        if (!$this->supports($contractId, $network)) {
            return null;
        }
        try {
            $record = $this->get($contractId, '', []);

            if ($record === null) {
                return null;
            }
            $mapped = $this->mapper->contract($contractId, $record);
            if ($expectedWasmHash !== null && strtolower($expectedWasmHash) !== $mapped['wasm_id']) {
                throw new \UnexpectedValueException('Upstream WASM differs from the locally verified executable.');
            }

            return $mapped;
        } catch (\Throwable $exception) {
            $this->logFailure($contractId, 'details', $exception);

            return null;
        }
    }

    /**
     * Null selects the existing local provider. Local DB cursors and filters
     * retain their existing semantics; they must never be forwarded to Orion.
     *
     * @return array{items:array,meta:array}|null
     */
    public function collection(string $contractId, string $network, string $kind, array $options): ?array
    {
        if (!$this->supports($contractId, $network)) {
            return null;
        }
        if (!in_array($kind, ['events', 'transactions', 'storage'], true)) {
            throw new \InvalidArgumentException('Unsupported contract collection.');
        }
        $cursor = $options['cursor'] ?? null;
        $hasOrionCursor = is_string($cursor) && str_starts_with($cursor, 'orion:');
        foreach (['beforeId', 'ledgerStart', 'ledgerEnd', 'txHash'] as $filter) {
            if (($options[$filter] ?? null) !== null) {
                if ($hasOrionCursor) {
                    throw new BadRequestHttpException('Orion cursors cannot be combined with local contract filters.');
                }

                return null;
            }
        }
        if (is_string($cursor) && $cursor !== '' && !$hasOrionCursor) {
            return null;
        }
        $limit = max(1, min(200, (int) ($options['itemsPerPage'] ?? 30)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $scope = $contractId.':'.$kind.':'.$limit.':'.(int) ($options['invocationsOnly'] ?? false);
        $upstreamCursor = $hasOrionCursor ? $this->decodeCursor($cursor, $scope) : null;

        try {
            $result = $this->readPage($contractId, $kind, $page, $limit, $upstreamCursor, $scope, (bool) ($options['invocationsOnly'] ?? false));
            if ($result === null) {
                if ($hasOrionCursor || $page > 1) {
                    throw new ServiceUnavailableHttpException(null, 'Contract continuation is unavailable. Restart pagination.');
                }

                return null;
            }

            return ['items' => $result['items'], 'meta' => [
                'page' => $page,
                'itemsPerPage' => $limit,
                'limit' => $limit,
                'cursor' => $cursor,
                'beforeId' => null,
                'nextBeforeId' => null,
                'nextCursor' => $result['next'] !== null ? $this->encodeCursor($scope, $result['next']) : null,
                'hasMore' => isset($result['totalItems']) ? $page * $limit < $result['totalItems'] : $result['next'] !== null,
                'mode' => 'orion',
                'source' => 'orion',
                'latestLedger' => $result['latestLedger'],
                ...(isset($result['totalItems']) ? ['totalItems' => $result['totalItems']] : []),
            ]];
        } catch (ServiceUnavailableHttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logFailure($contractId, $kind, $exception);
            if ($hasOrionCursor || $page > 1) {
                // Do not silently switch history sources halfway through a page chain.
                throw new ServiceUnavailableHttpException(null, 'Contract history is temporarily unavailable. Restart pagination.', $exception);
            }

            return null;
        }
    }

    private function readPage(string $contractId, string $kind, int $page, int $limit, ?string $cursor, string $scope, bool $invocationsOnly): ?array
    {
        $suffix = $kind === 'storage' ? 'data' : $kind;
        $startPage = 1;
        if ($cursor === null && $kind !== 'storage' && $page > 1) {
            // Cache only continuation positions, so sequential page-number reads
            // do not rescan every preceding page on each click.
            $cached = $this->cache->getItem($this->pageKey($scope, $page));
            if ($cached->isHit() && is_string($cached->get())) {
                $cursor = $cached->get();
                $startPage = $page;
            }
        } elseif ($cursor !== null) {
            $startPage = $page;
        }
        if ($kind !== 'storage' && $page - $startPage >= self::MAX_PAGE_READS) {
            throw new ServiceUnavailableHttpException(null, 'Deep contract pages require a continuation cursor or sequential pagination.');
        }
        $deadline = microtime(true) + 10;
        for ($currentPage = $startPage; $currentPage <= $page; ++$currentPage) {
            if ($currentPage - $startPage >= self::MAX_PAGE_READS) {
                throw new ServiceUnavailableHttpException(null, 'Contract pagination exceeded its page budget.');
            }
            if (microtime(true) >= $deadline) {
                throw new ServiceUnavailableHttpException(null, 'Contract pagination exceeded its request budget.');
            }
            $query = ['limit' => $limit, 'order' => 'desc'];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $payload = $this->get($contractId, $suffix, $query, min(3.0, $deadline - microtime(true)));
            if ($payload === null) {
                return null;
            }
            $records = $payload['_embedded']['records'] ?? $payload['records'] ?? null;
            if (!is_array($records) || !array_is_list($records)) {
                throw new \UnexpectedValueException('Missing collection records.');
            }
            $next = $this->nextCursor($payload, $records, $limit, $cursor);
            if ($kind === 'storage' && !isset($payload['_links']['next']) && !isset($payload['next_cursor'])) {
                // The deployed data endpoint currently returns its entire storage
                // set and ignores limit. Paginate that finite response locally.
                return ['items' => $this->mapRecords($contractId, $kind, array_slice($records, ($page - 1) * $limit, $limit), $invocationsOnly), 'next' => null,
                    'latestLedger' => $payload['latest_ledger'] ?? null,
                    'totalItems' => count($records)];
            }
            if (count($records) > $limit) {
                throw new \UnexpectedValueException('Upstream exceeded the requested page size.');
            }
            $items = $this->mapRecords($contractId, $kind, $records, $invocationsOnly);
            if ($next !== null) {
                $cacheItem = $this->cache->getItem($this->pageKey($scope, $currentPage + 1));
                $cacheItem->set($next)->expiresAfter(30);
                $this->cache->save($cacheItem);
            }
            if ($currentPage === $page || $next === null) {
                return ['items' => $currentPage === $page ? $items : [], 'next' => $currentPage === $page ? $next : null,
                    'latestLedger' => $payload['latest_ledger'] ?? null];
            }
            $cursor = $next;
        }

        return null;
    }

    private function mapRecords(string $contractId, string $kind, array $records, bool $invocationsOnly): array
    {
        $items = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new \UnexpectedValueException('Invalid collection record.');
            }
            $items[] = match ($kind) {
                'events' => $this->mapper->event($contractId, $record),
                'transactions' => $this->mapper->transaction($contractId, $record, $invocationsOnly),
                'storage' => $this->mapper->storage($contractId, $record),
            };
        }

        return $items;
    }

    private function get(string $contractId, string $suffix, array $query, float $duration = 3.0): ?array
    {
        $url = rtrim($this->baseUrl, '/').'/contracts/'.$contractId.($suffix !== '' ? '/'.$suffix : '');
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'query' => $query,
            'timeout' => min(2.0, $duration),
            'max_duration' => $duration,
            'max_redirects' => 0,
            'on_progress' => [$this, 'checkResponseSize'],
        ]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Contract upstream returned HTTP '.$response->getStatusCode());
        }

        return $response->toArray();
    }

    public function checkResponseSize(int $downloaded, int $downloadSize, array $info): void
    {
        if (max($downloaded, $downloadSize) > 4 * 1024 * 1024) {
            throw new \RuntimeException('Contract response exceeds the size limit.');
        }
    }

    private function supports(string $contractId, string $network): bool
    {
        if (!$this->enabled || $network !== 'mainnet' || preg_match('/^C[A-Z2-7]{55}$/', $contractId) !== 1) {
            return false;
        }
        try {
            StrKey::decodeContractIdHex($contractId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nextCursor(array $payload, array $records, int $limit, ?string $cursor): ?string
    {
        if ($records === []) {
            return null;
        }
        $next = $payload['next_cursor'] ?? null;
        if ($next === null && count($records) >= $limit) {
            $href = $payload['_links']['next']['href'] ?? null;
            if (is_string($href)) {
                parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
                $next = $query['cursor'] ?? null;
            }
        }
        if (!is_string($next) || $next === '') {
            return null;
        }
        if ($next === $cursor || strlen($next) > 2048) {
            throw new \UnexpectedValueException('Invalid upstream continuation cursor.');
        }

        return $next;
    }

    private function pageKey(string $scope, int $page): string
    {
        return 'contract.orion.page.'.hash('sha256', $this->baseUrl.':'.$scope.':'.$page);
    }

    private function encodeCursor(string $scope, string $cursor): string
    {
        return 'orion:'.rtrim(strtr(base64_encode(json_encode([$scope, $cursor], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor, string $scope): string
    {
        $encoded = substr($cursor, 6);
        $decoded = strlen($encoded) <= 4096 ? base64_decode(strtr($encoded, '-_', '+/'), true) : false;
        $parts = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($parts) || ($parts[0] ?? null) !== $scope || !is_string($parts[1] ?? null) || $parts[1] === '' || strlen($parts[1]) > 2048) {
            throw new BadRequestHttpException('Invalid contract continuation cursor or changed page size.');
        }

        return $parts[1];
    }

    private function logFailure(string $contractId, string $section, \Throwable $exception): void
    {
        $this->logger->warning('Contract upstream read rejected; using local data when available.', [
            'contractId' => $contractId, 'section' => $section, 'reason' => $exception->getMessage(),
        ]);
    }
}
