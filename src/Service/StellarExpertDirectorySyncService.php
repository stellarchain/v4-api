<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class StellarExpertDirectorySyncService
{
    private const DIRECTORY_URL = 'https://api.stellar.expert/explorer/directory';
    private const SCAM_LABEL = 'Scam';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   pages:int,
     *   fetched:int,
     *   created:int,
     *   scam_updates:int,
     *   existing_scams:int,
     *   skipped_existing:int,
     *   skipped_invalid:int,
     *   dry_run:bool
     * }
     */
    public function sync(string $network, int $pageSize, ?int $maxRecords, ?string $cursor, bool $dryRun): array
    {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'mainnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $pageSize = max(1, min(200, $pageSize));
        $remaining = $maxRecords !== null ? max(0, $maxRecords) : null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $stats = [
            'network' => $normalizedNetwork,
            'pages' => 0,
            'fetched' => 0,
            'created' => 0,
            'scam_updates' => 0,
            'existing_scams' => 0,
            'skipped_existing' => 0,
            'skipped_invalid' => 0,
            'dry_run' => $dryRun,
        ];

        while ($remaining === null || $remaining > 0) {
            $limit = $remaining === null ? $pageSize : min($pageSize, $remaining);
            $payload = $this->fetchDirectoryPage($limit, $cursor);
            $records = $this->extractRecords($payload);
            if ($records === []) {
                break;
            }

            $stats['pages']++;
            $stats['fetched'] += count($records);
            $this->processRecords($records, $networkCode, $now, $dryRun, $stats);

            if ($remaining !== null) {
                $remaining -= count($records);
            }

            $nextCursor = $this->extractNextCursor($payload, $records);
            if ($nextCursor === null || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchDirectoryPage(int $limit, ?string $cursor): array
    {
        $query = [
            'order' => 'asc',
            'limit' => $limit,
        ];
        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        $response = $this->httpClient->request('GET', self::DIRECTORY_URL, [
            'query' => $query,
            'max_duration' => 15.0,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Stellarchain/1.0',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Stellar Expert directory request failed with HTTP %d.', $statusCode));
        }

        $payload = $response->toArray(false);
        if (!is_array($payload)) {
            throw new \RuntimeException('Stellar Expert directory response was not a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function extractRecords(array $payload): array
    {
        $embedded = $payload['_embedded'] ?? null;
        if (!is_array($embedded)) {
            return [];
        }

        $records = $embedded['records'] ?? null;
        if (!is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, static fn (mixed $record): bool => is_array($record)));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @param array<string,int|bool|string> $stats
     */
    private function processRecords(array $records, int $networkCode, \DateTimeImmutable $now, bool $dryRun, array &$stats): void
    {
        foreach ($records as $record) {
            $address = strtoupper(trim((string) ($record['address'] ?? '')));
            if ($address === '' || !StrKey::isValidAccountId($address)) {
                $stats['skipped_invalid']++;
                continue;
            }

            $existing = $this->loadExistingAccount($address, $networkCode);
            $isScam = $this->hasTag($record, 'malicious');

            if ($existing !== null) {
                if ($isScam && strcasecmp((string) ($existing['label'] ?? ''), self::SCAM_LABEL) !== 0) {
                    if (!$dryRun) {
                        $this->connection->executeStatement(
                            'UPDATE account SET label = :label, verified = 0, updated_at = :updated_at WHERE id = :id',
                            [
                                'label' => self::SCAM_LABEL,
                                'updated_at' => $now->format('Y-m-d H:i:s'),
                                'id' => (int) $existing['id'],
                            ],
                            ['id' => ParameterType::INTEGER]
                        );
                    }
                    $stats['scam_updates']++;
                } elseif ($isScam) {
                    $stats['existing_scams']++;
                } else {
                    $stats['skipped_existing']++;
                }

                continue;
            }

            $label = $isScam ? self::SCAM_LABEL : $this->extractLabel($record);
            if (!$dryRun) {
                $account = (new Account())
                    ->setAddress($address)
                    ->setNetwork($networkCode)
                    ->setLabel($label)
                    ->setVerified(!$isScam)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $this->entityManager->persist($account);
            }
            $stats['created']++;
        }
    }

    /**
     * @return array{id:int,label:?string}|null
     */
    private function loadExistingAccount(string $address, int $networkCode): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, label FROM account WHERE address = :address AND network = :network',
            [
                'address' => $address,
                'network' => $networkCode,
            ],
            ['network' => ParameterType::INTEGER]
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => isset($row['label']) ? (string) $row['label'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    private function hasTag(array $record, string $tag): bool
    {
        $tags = $record['tags'] ?? [];
        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $value) {
            if (is_scalar($value) && strtolower(trim((string) $value)) === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function extractLabel(array $record): ?string
    {
        foreach (['name', 'domain'] as $field) {
            $value = $record[$field] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $label = trim((string) $value);
            if ($label !== '') {
                return substr($label, 0, 255);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<array<string,mixed>> $records
     */
    private function extractNextCursor(array $payload, array $records): ?string
    {
        $nextHref = $payload['_links']['next']['href'] ?? null;
        if (is_string($nextHref) && trim($nextHref) !== '') {
            $parts = parse_url($nextHref);
            if (isset($parts['query']) && is_string($parts['query'])) {
                parse_str($parts['query'], $query);
                $cursor = $query['cursor'] ?? null;
                if (is_scalar($cursor) && trim((string) $cursor) !== '') {
                    return trim((string) $cursor);
                }
            }
        }

        $lastRecord = $records[count($records) - 1] ?? null;
        $pagingToken = is_array($lastRecord) ? ($lastRecord['paging_token'] ?? null) : null;
        if (is_scalar($pagingToken) && trim((string) $pagingToken) !== '') {
            return trim((string) $pagingToken);
        }

        return null;
    }
}
