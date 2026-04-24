<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AssetTomlEnrichmentService
{
    private const DEFAULT_LOGO_URL = 'https://stellarchain.dev/stellarchain-logo.svg';
    private const TOML_FETCH_TIMEOUT_SECONDS = 2.0;
    private const IMAGE_FETCH_TIMEOUT_SECONDS = 1.0;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   processed:int,
     *   updated:int,
     *   skipped:int,
     *   failed:int,
     *   dry_run:bool
     * }
     */
    public function enrich(
        string $network,
        bool $dryRun,
        int $batchSize,
        ?int $limit,
        bool $onlyMissing,
        ?int $marketTop = 1000,
        bool $checkLogos = true
    ): array
    {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 2;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $lastId = 0;
        $imageReachabilityCache = [];
        $targetAssetIds = $marketTop !== null ? $this->loadTopMarketAssetIds($networkCode, $marketTop) : null;

        if (is_array($targetAssetIds)) {
            foreach (array_chunk($targetAssetIds, $batchSize) as $idChunk) {
                if ($limit !== null && $processed >= $limit) {
                    break;
                }
                $rows = $this->loadAssetsByIds($idChunk);
                $remaining = $limit !== null ? $limit - $processed : null;
                if ($remaining !== null && $remaining < count($rows)) {
                    $rows = array_slice($rows, 0, max(0, $remaining));
                }
                if ($rows === []) {
                    continue;
                }
                $this->processRows(
                    $rows,
                    $horizonConnection,
                    $onlyMissing,
                    $dryRun,
                    $now,
                    $imageReachabilityCache,
                    $checkLogos,
                    $processed,
                    $updated,
                    $skipped,
                    $failed
                );
            }

            return [
                'network' => $normalizedNetwork,
                'processed' => $processed,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'dry_run' => $dryRun,
            ];
        }

        while (true) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $queryLimit = $batchSize;
            if ($limit !== null) {
                $queryLimit = min($queryLimit, $limit - $processed);
            }

            $rows = $this->connection->fetchAllAssociative(
                <<<SQL
SELECT id, asset_key, code, issuer, toml_info
FROM asset
WHERE network = :network
  AND is_native = 0
  AND issuer IS NOT NULL
  AND id > :last_id
ORDER BY id ASC
LIMIT :limit
SQL,
                [
                    'network' => $networkCode,
                    'last_id' => $lastId,
                    'limit' => $queryLimit,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'last_id' => ParameterType::INTEGER,
                    'limit' => ParameterType::INTEGER,
                ]
            );

            if ($rows === []) {
                break;
            }

            $this->processRows(
                $rows,
                $horizonConnection,
                $onlyMissing,
                $dryRun,
                $now,
                $imageReachabilityCache,
                $checkLogos,
                $processed,
                $updated,
                $skipped,
                $failed
            );
            $lastId = (int) ($rows[count($rows) - 1]['id'] ?? $lastId);
        }

        return [
            'network' => $normalizedNetwork,
            'processed' => $processed,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAssetsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
SELECT id, asset_key, code, issuer, toml_info
FROM asset
WHERE id IN (:ids)
ORDER BY id ASC
SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }

    /**
     * @return list<int>
     */
    private function loadTopMarketAssetIds(int $networkCode, int $top): array
    {
        if ($top < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT a.id
FROM market_asset_snapshot m
JOIN asset a ON a.id = m.asset_id
WHERE m.network = :network
  AND a.is_native = 0
  AND a.issuer IS NOT NULL
ORDER BY m.rank_position ASC
LIMIT :top
SQL,
            [
                'network' => $networkCode,
                'top' => $top,
            ],
            [
                'network' => ParameterType::INTEGER,
                'top' => ParameterType::INTEGER,
            ]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,bool> $imageReachabilityCache
     */
    private function processRows(
        array $rows,
        Connection $horizonConnection,
        bool $onlyMissing,
        bool $dryRun,
        \DateTimeImmutable $now,
        array &$imageReachabilityCache,
        bool $checkLogos,
        int &$processed,
        int &$updated,
        int &$skipped,
        int &$failed
    ): void {
        $issuerToDomain = $this->loadIssuerHomeDomains(
            $horizonConnection,
            array_values(array_unique(array_filter(array_map(
                static fn (array $row): ?string => is_string($row['issuer'] ?? null) ? trim((string) $row['issuer']) : null,
                $rows
            ))))
        );
        $tomlByDomain = [];

        foreach ($rows as $row) {
            try {
                $processed++;
                $assetKey = (string) $row['asset_key'];
                $issuer = is_string($row['issuer'] ?? null) ? trim((string) $row['issuer']) : null;

                $existingToml = $this->decodeToml($row['toml_info'] ?? null);
                if ($onlyMissing && $this->hasTomlCoreFields($existingToml)) {
                    $skipped++;
                    continue;
                }

                if ($issuer === null || $issuer === '') {
                    $failed++;
                    continue;
                }

                $homeDomain = $issuerToDomain[$issuer] ?? null;
                if ($homeDomain === null) {
                    $failed++;
                    continue;
                }

                if (!isset($tomlByDomain[$homeDomain])) {
                    $tomlByDomain[$homeDomain] = $this->loadAndParseStellarToml($homeDomain);
                }
                $parsedToml = $tomlByDomain[$homeDomain];
                if (!is_array($parsedToml)) {
                    $failed++;
                    continue;
                }

                $code = is_string($row['code'] ?? null) ? trim((string) $row['code']) : '';
                $currency = $this->findCurrencyEntry($parsedToml, $code, $issuer);
                if (!is_array($currency)) {
                    $failed++;
                    continue;
                }

                $mergedToml = $this->buildTomlInfo(
                    $assetKey,
                    $existingToml,
                    $currency,
                    $parsedToml['documentation'] ?? null,
                    $homeDomain
                );
                $mergedToml = $checkLogos
                    ? $this->applyImageFallback($mergedToml, $imageReachabilityCache)
                    : $this->ensureImageFallbackField($mergedToml);
                if ($onlyMissing && $mergedToml === $existingToml) {
                    $skipped++;
                    continue;
                }

                $encodedToml = $this->encodeTomlJson($mergedToml);
                if ($encodedToml === null) {
                    $failed++;
                    continue;
                }

                if (!$dryRun) {
                    $this->connection->executeStatement(
                        'UPDATE asset SET toml_info = :toml_info, updated_at = :updated_at WHERE id = :id',
                        [
                            'toml_info' => $encodedToml,
                            'updated_at' => $now->format('Y-m-d H:i:s'),
                            'id' => (int) $row['id'],
                        ],
                        ['id' => ParameterType::INTEGER]
                    );
                }
                $updated++;
            } catch (\Throwable) {
                $failed++;
            }
        }
    }

    /**
     * @param list<string> $issuers
     * @return array<string,string>
     */
    private function loadIssuerHomeDomains(Connection $horizonConnection, array $issuers): array
    {
        if ($issuers === []) {
            return [];
        }

        $rows = $horizonConnection->fetchAllAssociative(
            <<<SQL
SELECT account_id, home_domain
FROM accounts
WHERE account_id IN (:issuers)
  AND home_domain IS NOT NULL
  AND home_domain <> ''
SQL,
            ['issuers' => $issuers],
            ['issuers' => ArrayParameterType::STRING]
        );

        $result = [];
        foreach ($rows as $row) {
            $accountId = is_string($row['account_id'] ?? null) ? trim((string) $row['account_id']) : '';
            $homeDomain = is_string($row['home_domain'] ?? null) ? trim((string) $row['home_domain']) : '';
            if ($accountId !== '' && $homeDomain !== '') {
                $result[$accountId] = $homeDomain;
            }
        }

        return $result;
    }

    /**
     * @return array{currencies:list<array<string,mixed>>,documentation:array<string,mixed>}|null
     */
    private function loadAndParseStellarToml(string $homeDomain): ?array
    {
        $url = sprintf('https://%s/.well-known/stellar.toml', $homeDomain);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'text/plain'],
                'timeout' => self::TOML_FETCH_TIMEOUT_SECONDS,
                'max_duration' => self::TOML_FETCH_TIMEOUT_SECONDS,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }
            $content = $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        return $this->parseToml($content);
    }

    /**
     * @return array{currencies:list<array<string,mixed>>,documentation:array<string,mixed>}
     */
    private function parseToml(string $raw): array
    {
        $documentation = [];
        $currencies = [];
        $currentSection = null;
        $currentCurrency = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($line === '[DOCUMENTATION]') {
                if (is_array($currentCurrency)) {
                    $currencies[] = $currentCurrency;
                    $currentCurrency = null;
                }
                $currentSection = 'documentation';
                continue;
            }

            if ($line === '[[CURRENCIES]]') {
                if (is_array($currentCurrency)) {
                    $currencies[] = $currentCurrency;
                }
                $currentCurrency = [];
                $currentSection = 'currencies';
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$rawKey, $rawValue] = array_map('trim', explode('=', $line, 2));
            if ($rawKey === '') {
                continue;
            }
            $value = $this->parseTomlValue($rawValue);

            if ($currentSection === 'documentation') {
                $documentation[$rawKey] = $value;
                continue;
            }

            if ($currentSection === 'currencies' && is_array($currentCurrency)) {
                $currentCurrency[$rawKey] = $value;
            }
        }

        if (is_array($currentCurrency)) {
            $currencies[] = $currentCurrency;
        }

        return [
            'currencies' => $currencies,
            'documentation' => $documentation,
        ];
    }

    private function parseTomlValue(string $raw): mixed
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return trim($value, "\"'");
        }

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }
            $parts = array_map('trim', explode(',', $inner));

            return array_values(array_filter(array_map(
                fn (string $part): mixed => $this->parseTomlValue($part),
                $parts
            ), static fn (mixed $v): bool => $v !== null && $v !== ''));
        }

        if (is_numeric($value)) {
            if (str_contains($value, '.')) {
                return (float) $value;
            }

            return (int) $value;
        }

        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $toml
     * @param array<string,bool> $cache
     * @return array<string,mixed>
     */
    private function applyImageFallback(array $toml, array &$cache): array
    {
        $imageUrl = $this->pickString($toml, ['image', 'logo', 'icon', 'orgLogo', 'ORG_LOGO']);
        if ($imageUrl === null || !$this->isImageUrlReachable($imageUrl, $cache)) {
            $toml['image'] = self::DEFAULT_LOGO_URL;
            $toml['image_fallback_used'] = true;
            $toml['image_original'] = $imageUrl;
        }

        return $toml;
    }

    /**
     * @param array<string,mixed> $toml
     * @return array<string,mixed>
     */
    private function ensureImageFallbackField(array $toml): array
    {
        $imageUrl = $this->pickString($toml, ['image', 'logo', 'icon', 'orgLogo', 'ORG_LOGO']);
        if ($imageUrl === null) {
            $toml['image'] = self::DEFAULT_LOGO_URL;
            $toml['image_fallback_used'] = true;
            $toml['image_original'] = null;
        }

        return $toml;
    }

    /**
     * @param array<string,bool> $cache
     */
    private function isImageUrlReachable(string $url, array &$cache): bool
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return false;
        }
        if ($normalized === self::DEFAULT_LOGO_URL) {
            return true;
        }
        if (isset($cache[$normalized])) {
            return $cache[$normalized];
        }

        try {
            $response = $this->httpClient->request('GET', $normalized, [
                'timeout' => self::IMAGE_FETCH_TIMEOUT_SECONDS,
                'max_duration' => self::IMAGE_FETCH_TIMEOUT_SECONDS,
                'headers' => [
                    'Range' => 'bytes=0-0',
                    'Accept' => 'image/*,*/*;q=0.8',
                ],
            ]);
            $status = $response->getStatusCode();
            $contentType = strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            $ok = $status >= 200 && $status < 300 && str_starts_with($contentType, 'image/');

            return $cache[$normalized] = $ok;
        } catch (\Throwable) {
            return $cache[$normalized] = false;
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encodeTomlJson(array $value): ?string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array{currencies:list<array<string,mixed>>,documentation:array<string,mixed>} $toml
     * @return array<string,mixed>|null
     */
    private function findCurrencyEntry(array $toml, string $code, string $issuer): ?array
    {
        foreach ($toml['currencies'] as $entry) {
            $entryCode = is_string($entry['code'] ?? null) ? trim((string) $entry['code']) : null;
            $entryIssuer = is_string($entry['issuer'] ?? null) ? trim((string) $entry['issuer']) : null;
            if ($entryCode === $code && $entryIssuer === $issuer) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>|null $documentation
     * @param array<string,mixed> $currency
     * @return array<string,mixed>
     */
    private function buildTomlInfo(
        string $assetKey,
        ?array $existing,
        array $currency,
        ?array $documentation,
        string $homeDomain
    ): array {
        $result = is_array($existing) ? $existing : [];
        foreach ($currency as $key => $value) {
            $result[$key] = $value;
        }

        if (is_array($documentation) && $documentation !== []) {
            $result['documentation'] = $documentation;
            $orgUrl = $this->pickString($documentation, ['ORG_URL', 'org_url']);
            if ($orgUrl !== null) {
                $result['home_url'] = $orgUrl;
                $result['url'] = $orgUrl;
            }
        }

        [$code, $issuer] = $this->parseAssetCodeIssuer($assetKey);
        if (!isset($result['code']) || !is_string($result['code']) || trim($result['code']) === '') {
            $result['code'] = $code;
        }
        if (!isset($result['issuer']) || !is_string($result['issuer']) || trim($result['issuer']) === '') {
            $result['issuer'] = $issuer;
        }

        $image = $this->pickString($result, ['image', 'logo', 'icon', 'orgLogo', 'ORG_LOGO']);
        if ($image !== null) {
            $result['image'] = $image;
        }

        $result['home_domain'] = $homeDomain;
        $result['toml_url'] = sprintf('https://%s/.well-known/stellar.toml', $homeDomain);

        return $result;
    }

    /**
     * @param array<string,mixed>|null $tomlInfo
     */
    private function hasTomlCoreFields(?array $tomlInfo): bool
    {
        if ($tomlInfo === null) {
            return false;
        }

        $hasImage = $this->pickString($tomlInfo, ['image', 'logo', 'icon']) !== null;
        $hasHome = $this->pickString($tomlInfo, ['home_url', 'url', 'home_domain', 'homeDomain']) !== null;

        return $hasImage && $hasHome;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeToml(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private function pickString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function parseAssetCodeIssuer(string $asset): array
    {
        if ($asset === 'XLM-native') {
            return ['XLM', null];
        }

        if (preg_match('/^(.*)-(G[A-Z2-7]{55})(?:-\d+)?$/', $asset, $matches) === 1) {
            $code = trim((string) ($matches[1] ?? ''));
            $issuer = trim((string) ($matches[2] ?? ''));
            if ($code !== '' && $issuer !== '') {
                return [$code, $issuer];
            }
        }

        $parts = explode('-', $asset, 2);
        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1]) !== '' ? trim($parts[1]) : null];
        }

        return [trim($asset), null];
    }

    private function resolveHorizonConnection(string $network): Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        return $this->doctrine->getConnection($connectionName);
    }
}
