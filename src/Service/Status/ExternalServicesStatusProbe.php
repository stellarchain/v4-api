<?php

declare(strict_types=1);

namespace App\Service\Status;

use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExternalServicesStatusProbe
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    /**
     * @return array{
     *   overall: 'ok'|'degraded',
     *   network: 'mainnet'|'testnet'|'futurenet',
     *   checkedAt: string,
     *   services: array{
     *      horizon: array<string,mixed>,
     *      sorobanRpc: array<string,mixed>
     *   }
     * }
     */
    public function probe(?string $networkInput, bool $preferBackupMainnetRpc = false): array
    {
        $network = $this->stellarNetworkResolver->normalizeNetwork($networkInput, 'mainnet');
        $horizonUrl = $this->stellarNetworkResolver->resolveHorizonUrl($network);

        $horizon = $this->probeHorizon($horizonUrl);
        $rpc = $this->probeSorobanRpc($network, $preferBackupMainnetRpc);

        $overall = ($horizon['ok'] ?? false) && ($rpc['ok'] ?? false) ? 'ok' : 'degraded';

        return [
            'overall' => $overall,
            'network' => $network,
            'checkedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'services' => [
                'horizon' => $horizon,
                'sorobanRpc' => $rpc,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function probeHorizon(string $horizonUrl): array
    {
        $started = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $horizonUrl, [
                'timeout' => 3.0,
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            return [
                'ok' => $statusCode >= 200 && $statusCode < 400,
                'status' => ($statusCode >= 200 && $statusCode < 400) ? 'up' : 'down',
                'url' => $horizonUrl,
                'httpStatus' => $statusCode,
                'latencyMs' => $latencyMs,
                'details' => [
                    'coreLatestLedger' => $payload['core_latest_ledger'] ?? null,
                    'historyLatestLedger' => $payload['history_latest_ledger'] ?? null,
                    'networkPassphrase' => $payload['network_passphrase'] ?? null,
                ],
            ];
        } catch (TransportExceptionInterface|\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'down',
                'url' => $horizonUrl,
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function probeSorobanRpc(string $network, bool $preferBackupMainnetRpc): array
    {
        $started = microtime(true);
        $usingBackupMainnetRpc = false;

        try {
            $rpcUrl = $this->resolveRpcUrl($network, $preferBackupMainnetRpc, $usingBackupMainnetRpc);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'down',
                'url' => null,
                'latencyMs' => 0,
                'error' => $exception->getMessage(),
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $rpcUrl, [
                'timeout' => 3.0,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'getHealth',
                ],
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
            $result = $payload['result'] ?? null;
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            $isHealthy = false;
            if (is_string($result)) {
                $isHealthy = strtolower($result) === 'healthy';
            } elseif (is_array($result)) {
                $statusValue = $result['status'] ?? null;
                $isHealthy = is_string($statusValue) && strtolower($statusValue) === 'healthy';
            }

            return [
                'ok' => $statusCode >= 200 && $statusCode < 400 && $isHealthy,
                'status' => ($statusCode >= 200 && $statusCode < 400 && $isHealthy) ? 'up' : 'down',
                'url' => $rpcUrl,
                'httpStatus' => $statusCode,
                'latencyMs' => $latencyMs,
                'details' => [
                    'usingBackupMainnetRpc' => $usingBackupMainnetRpc,
                    'health' => $result,
                ],
            ];
        } catch (TransportExceptionInterface|\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'down',
                'url' => $rpcUrl,
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function resolveRpcUrl(string $network, bool $preferBackupMainnetRpc, bool &$usingBackupMainnetRpc): string
    {
        $usingBackupMainnetRpc = false;
        if ($preferBackupMainnetRpc && $network === 'mainnet') {
            $backup = $this->readNonEmptyEnv('SOROBAN_RPC_BACKUP_MAINNET_URL');
            if ($backup !== null) {
                $usingBackupMainnetRpc = true;
                return $backup;
            }
        }

        return $this->stellarNetworkResolver->resolveSorobanRpcUrl($network);
    }

    private function readNonEmptyEnv(string $name): ?string
    {
        $candidates = [
            getenv($name),
            $_ENV[$name] ?? null,
            $_SERVER[$name] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
