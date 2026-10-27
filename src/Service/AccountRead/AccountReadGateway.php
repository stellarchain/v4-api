<?php

declare(strict_types=1);

namespace App\Service\AccountRead;

use App\Service\Stellar\StellarNetworkResolver;
use Psr\Log\LoggerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AccountReadGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StellarNetworkResolver $networkResolver,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:app.orion_base_url:ORION_BASE_URL)%')]
        private readonly string $orionUrl,
        #[Autowire('%env(bool:default:app.true_string:ORION_ACCOUNT_READS_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    /** @return array{record:array,source:string}|null */
    public function account(string $address, string $network): ?array
    {
        $this->validateAddress($address);
        $network = $this->networkResolver->normalizeNetwork($network);
        if ($network === 'mainnet' && $this->enabled) {
            try {
                $record = $this->get($this->orionUrl, '/accounts/'.$address);
                if ($record !== null) {
                    $this->validateAccount($address, $record);

                    return ['record' => $record, 'source' => 'orion'];
                }
            } catch (ServiceUnavailableHttpException $exception) {
                $this->logger->warning('Orion account state unavailable; checking Horizon.', ['address' => $address]);
            }
        }
        $record = $this->get($this->networkResolver->resolveHorizonUrl($network), '/accounts/'.$address);
        if ($record === null) {
            return null;
        }
        $this->validateAccount($address, $record);

        return ['record' => $record, 'source' => 'horizon'];
    }

    /** Account history is independent of current account state and local indexing. */
    public function history(string $address, string $network, string $kind, int $limit, string $order, ?string $cursor, ?bool $includeFailed): array
    {
        $this->validateAddress($address);
        if (!in_array($kind, ['transactions', 'operations', 'effects'], true)
            || $limit < 1 || $limit > 200 || !in_array($order, ['asc', 'desc'], true)
            || ($cursor !== null && (strlen($cursor) > 2048 || str_contains($cursor, "\0")))) {
            throw new BadRequestHttpException('Invalid account history query.');
        }
        $network = $this->networkResolver->normalizeNetwork($network);
        $orion = $network === 'mainnet' && $this->enabled;
        $base = $orion ? $this->orionUrl : $this->networkResolver->resolveHorizonUrl($network);
        $query = ['limit' => $limit, 'order' => $order];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        if ($includeFailed !== null && $kind !== 'effects') {
            $query['include_failed'] = $includeFailed ? 'true' : 'false';
        }
        $payload = $this->get($base, '/accounts/'.$address.'/'.$kind, $query);
        if ($payload === null) {
            throw new ServiceUnavailableHttpException(null, 'Account history is unavailable from the selected provider.');
        }
        $records = $payload['_embedded']['records'] ?? null;
        if (!is_array($records) || !array_is_list($records) || count($records) > $limit) {
            throw new ServiceUnavailableHttpException(null, 'Invalid account history response.');
        }
        foreach ($records as $record) {
            if (!is_array($record) || !is_string($record['paging_token'] ?? null)) {
                throw new ServiceUnavailableHttpException(null, 'Invalid account history record.');
            }
        }
        $next = $this->nextCursor($payload, $records, $limit);
        if ($next !== null && $next === $cursor) {
            throw new ServiceUnavailableHttpException(null, 'Account history cursor did not advance.');
        }

        return ['records' => $records, 'meta' => [
            'source' => $orion ? 'orion' : 'horizon', 'network' => $network,
            'limit' => $limit, 'order' => $order, 'cursor' => $cursor,
            'hasMore' => $next !== null, 'nextCursor' => $next,
        ]];
    }

    private function nextCursor(array $payload, array $records, int $limit): ?string
    {
        if ($records === []) {
            return null;
        }
        $href = $payload['_links']['next']['href'] ?? null;
        if (is_string($href) && $href !== '') {
            $query = parse_url($href, PHP_URL_QUERY);
            if (!is_string($query)) {
                throw new ServiceUnavailableHttpException(null, 'Invalid account history continuation.');
            }
            parse_str($query, $parameters);
            $cursor = $parameters['cursor'] ?? null;
            if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || str_contains($cursor, "\0")) {
                throw new ServiceUnavailableHttpException(null, 'Invalid account history continuation.');
            }

            // Extract only the cursor; never follow an upstream URL supplied in HAL.
            return $cursor;
        }

        return count($records) === $limit ? $records[array_key_last($records)]['paging_token'] : null;
    }

    public function checkResponseSize(int $downloaded, int $downloadSize, array $info): void
    {
        if (max($downloaded, $downloadSize) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Account response exceeds the size limit.');
        }
    }

    private function get(string $base, string $path, array $query = []): ?array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($base, '/').$path, [
                'headers' => ['Accept' => 'application/json'], 'query' => $query,
                'timeout' => 2, 'max_duration' => 5, 'max_redirects' => 0,
                'on_progress' => [$this, 'checkResponseSize'],
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if ($status !== 200) {
                $this->logger->warning('Account upstream request failed.', ['path' => $path, 'status' => $status]);
                throw new \RuntimeException('Account upstream returned HTTP '.$status);
            }

            return $response->toArray();
        } catch (\Throwable $exception) {
            throw new ServiceUnavailableHttpException(null, 'Account data is temporarily unavailable. Please retry.', $exception);
        }
    }

    private function validateAddress(string $address): void
    {
        if (!StrKey::isValidAccountId($address)) {
            throw new BadRequestHttpException('Invalid Stellar account address.');
        }
    }

    private function validateAccount(string $address, array $record): void
    {
        if (($record['account_id'] ?? null) !== $address
            || !is_string($record['sequence'] ?? null)
            || !is_array($record['balances'] ?? null)
            || !is_array($record['signers'] ?? null)
            || !is_array($record['thresholds'] ?? null)
            || !is_array($record['flags'] ?? null)) {
            throw new ServiceUnavailableHttpException(null, 'Account state response has an invalid identity or schema.');
        }
    }
}
