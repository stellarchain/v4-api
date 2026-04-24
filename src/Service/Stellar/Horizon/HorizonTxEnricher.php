<?php

namespace App\Service\Stellar\Horizon;

use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\StellarNetworkResolver;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Effects\EffectResponse;
use Soneso\StellarSDK\Responses\Operations\OperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\Responses\Transaction\TransactionResponse;
use Soneso\StellarSDK\StellarSDK;

final class HorizonTxEnricher
{
    private const DEFAULT_MAX_RETRIES = 4;
    private const DEFAULT_MIN_REQUEST_DELAY_MS = 100;
    private const DEFAULT_TRANSIENT_RETRY_WAIT_SECONDS = 1;
    private const MAX_TRANSIENT_RETRY_WAIT_SECONDS = 8;

    public function __construct(
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly InvokeContractCallExtractor $invokeContractCallExtractor,
    ) {
    }

    /**
     * @param array<int,string> $txHashes
     * @return array<string,mixed>
     */
    public function fetchByHashes(
        ?string $network,
        array $txHashes,
        ?callable $onProgress = null,
    ): array {
        if ($txHashes === []) {
            return [
                'ok' => true,
                'count' => 0,
                'items' => [],
            ];
        }

        $uniqueHashes = array_values(array_unique($txHashes));
        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));
        $items = [];
        $rateLimitRetries = 0;
        $rateLimitWaitSeconds = 0;
        $requestErrors = 0;
        $requestErrorSamples = [];
        $rateLimitEvents = [];
        foreach ($uniqueHashes as $txHash) {
            $rateLimitLogger = function (array $event) use (&$rateLimitEvents, $onProgress): void {
                $rateLimitEvents[] = $event;
                if (is_callable($onProgress)) {
                    $onProgress($event);
                }
            };
            $tx = $this->fetchTransactionViaSdk($sdk, $txHash, $rateLimitRetries, $rateLimitWaitSeconds, $rateLimitLogger);
            $effects = $this->fetchEffectsViaSdk($sdk, $txHash, $rateLimitRetries, $rateLimitWaitSeconds, $rateLimitLogger);
            $operations = $this->fetchOperationsViaSdk($sdk, $txHash, $rateLimitRetries, $rateLimitWaitSeconds, $rateLimitLogger);
            if (($tx['ok'] ?? false) !== true) {
                $requestErrors++;
                if (count($requestErrorSamples) < 10) {
                    $requestErrorSamples[] = $this->buildRequestErrorSample($txHash, 'transaction', $tx);
                }
            }
            if (($effects['ok'] ?? false) !== true) {
                $requestErrors++;
                if (count($requestErrorSamples) < 10) {
                    $requestErrorSamples[] = $this->buildRequestErrorSample($txHash, 'effects', $effects);
                }
            }
            if (($operations['ok'] ?? false) !== true) {
                $requestErrors++;
                if (count($requestErrorSamples) < 10) {
                    $requestErrorSamples[] = $this->buildRequestErrorSample($txHash, 'operations', $operations);
                }
            }

            $items[] = [
                'txHash' => $txHash,
                'transaction' => $tx,
                'effects' => $effects,
                'operations' => $operations,
            ];
        }

        return [
            'ok' => true,
            'count' => count($items),
            'requested' => count($uniqueHashes),
            'rateLimitRetries' => $rateLimitRetries,
            'rateLimitWaitSeconds' => $rateLimitWaitSeconds,
            'requestErrors' => $requestErrors,
            'requestErrorSamples' => $requestErrorSamples,
            'rateLimitEvents' => $rateLimitEvents,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   transactions:array<int,array<string,mixed>>,
     *   storageLedgerKeys:array<int,string>,
     *   transactionsCollected:int,
     *   ledgersScanned:int,
     *   ledgerErrors:int,
     *   rateLimitRetries:int,
     *   rateLimitWaitSeconds:int
     * }
     */
    public function collectInvokeTransactionsForContract(
        ?string $network,
        string $contractId,
        int $startLedger,
        int $endLedger,
        ?callable $onProgress = null,
        ?callable $onTransactionsChunk = null,
    ): array {
        $from = min($startLedger, $endLedger);
        $to = max($startLedger, $endLedger);
        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));
        $collectRows = !is_callable($onTransactionsChunk);
        $txByHash = [];
        $transactionsCollected = 0;
        $rateLimitRetries = 0;
        $rateLimitWaitSeconds = 0;
        $ledgersScanned = 0;
        $ledgerErrors = 0;
        $storageLedgerKeys = [];

        for ($ledger = $to; $ledger >= $from; $ledger--) {
            $ledgersScanned++;
            try {
                $page = $this->runWithRateLimitRetry(
                    fn () => $sdk->transactions()
                        ->forLedger((string) $ledger)
                        ->includeFailed(true)
                        ->limit(200)
                        ->order('asc')
                        ->execute(),
                    $rateLimitRetries,
                    $rateLimitWaitSeconds,
                    $onProgress,
                    ['scope' => 'ledger_transactions', 'ledger' => $ledger],
                );
            } catch (\Throwable $e) {
                $ledgerErrors++;
                if (is_callable($onProgress)) {
                    $onProgress([
                        'phase' => 'horizon_ledger_error',
                        'ledger' => $ledger,
                        'ledgersScanned' => $ledgersScanned,
                        'totalLedgers' => $to - $from + 1,
                        'error' => $e->getMessage(),
                    ]);
                }
                continue;
            }
            $pageIndex = 0;
            $seenPageSignatures = [];
            while ($page !== null) {
                $pageIndex++;
                $records = $page->getTransactions()->toArray();
                if ($records === []) {
                    break;
                }

                $signature = $this->buildPageSignature($records);
                if ($signature !== '' && isset($seenPageSignatures[$signature])) {
                    break;
                }
                if ($signature !== '') {
                    $seenPageSignatures[$signature] = true;
                }

                $ledgerRows = [];
                foreach ($records as $tx) {
                    if (!$tx instanceof TransactionResponse) {
                        continue;
                    }
                    $calls = $this->invokeContractCallExtractor
                        ->fromEnvelopeXdrForContract($tx->getEnvelopeXdrBase64(), $contractId);
                    if ($calls === []) {
                        continue;
                    }
                    foreach ($this->invokeContractCallExtractor->extractContractStorageLedgerKeysFromEnvelopeXdr($tx->getEnvelopeXdrBase64(), $contractId) as $ledgerKey) {
                        $storageLedgerKeys[$ledgerKey] = true;
                    }

                    $txHash = $tx->getHash();
                    if (!is_string($txHash) || $txHash === '') {
                        continue;
                    }

                    $hostFunctions = [
                        'operationTypes' => ['invoke_host_function'],
                        'operationsCount' => (int) $tx->getOperationCount(),
                        'effectsCount' => 0,
                        'invokeContracts' => $calls,
                    ];
                    $hostFunctionsJson = json_encode($hostFunctions, JSON_UNESCAPED_SLASHES);
                    $row = [
                        'txHash' => $txHash,
                        'sourceAccount' => $tx->getSourceAccount(),
                        'hostFunctions' => is_string($hostFunctionsJson) ? $hostFunctionsJson : null,
                        'feeCharged' => (int) $tx->getFeeCharged(),
                        'maxFee' => (int) $tx->getMaxFee(),
                        'ledger' => is_numeric((string) $tx->getLedger()) ? (int) $tx->getLedger() : $ledger,
                        'totalOperations' => (int) $tx->getOperationCount(),
                        'createdAt' => $tx->getCreatedAt(),
                    ];

                    if ($collectRows) {
                        $txByHash[$txHash] = $row;
                    } else {
                        $ledgerRows[] = $row;
                    }

                    $transactionsCollected++;
                }

                if (!$collectRows && $ledgerRows !== [] && is_callable($onTransactionsChunk)) {
                    $onTransactionsChunk($ledgerRows);
                }

                if (count($records) < 200) {
                    break;
                }

                try {
                    $nextPage = $this->runWithRateLimitRetry(
                        fn () => $page->getNextPage(),
                        $rateLimitRetries,
                        $rateLimitWaitSeconds,
                        $onProgress,
                        ['scope' => 'ledger_transactions_next', 'ledger' => $ledger],
                    );
                } catch (\Throwable) {
                    break;
                }

                if ($nextPage === null) {
                    break;
                }

                $page = $nextPage;
            }

            if (is_callable($onProgress)) {
                $onProgress([
                    'phase' => 'horizon_ledger',
                    'ledger' => $ledger,
                    'ledgersScanned' => $ledgersScanned,
                    'totalLedgers' => $to - $from + 1,
                    'transactionsCollected' => $collectRows ? count($txByHash) : $transactionsCollected,
                ]);
            }
        }

        return [
            'ok' => true,
            'transactions' => $collectRows ? array_values($txByHash) : [],
            'storageLedgerKeys' => array_keys($storageLedgerKeys),
            'transactionsCollected' => $collectRows ? count($txByHash) : $transactionsCollected,
            'ledgersScanned' => $ledgersScanned,
            'ledgerErrors' => $ledgerErrors,
            'rateLimitRetries' => $rateLimitRetries,
            'rateLimitWaitSeconds' => $rateLimitWaitSeconds,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchTransactionViaSdk(
        StellarSDK $sdk,
        string $txHash,
        int &$rateLimitRetries,
        int &$rateLimitWaitSeconds,
        ?callable $onProgress = null,
    ): array
    {
        try {
            $tx = $this->runWithRateLimitRetry(
                fn () => $sdk->requestTransaction($txHash),
                $rateLimitRetries,
                $rateLimitWaitSeconds,
                $onProgress,
                ['scope' => 'transaction', 'txHash' => $txHash],
            );
            return [
                'ok' => true,
                'result' => $this->serializeTransaction($tx),
            ];
        } catch (\Throwable $e) {
            return $this->buildSdkErrorResult($e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchEffectsViaSdk(
        StellarSDK $sdk,
        string $txHash,
        int &$rateLimitRetries,
        int &$rateLimitWaitSeconds,
        ?callable $onProgress = null,
    ): array
    {
        try {
            $page = $this->runWithRateLimitRetry(
                fn () => $sdk->effects()
                    ->forTransaction($txHash)
                    ->limit(200)
                    ->order('asc')
                    ->execute(),
                $rateLimitRetries,
                $rateLimitWaitSeconds,
                $onProgress,
                ['scope' => 'effects', 'txHash' => $txHash],
            );

            $records = [];
            foreach ($page->getEffects()->toArray() as $effect) {
                if ($effect instanceof EffectResponse) {
                    $records[] = $this->serializeEffect($effect);
                }
            }
            return [
                'ok' => true,
                'result' => [
                    '_embedded' => ['records' => $records],
                    '_links' => [
                        'self' => ['href' => $page->getLinks()->getSelf()?->getHref()],
                        'next' => ['href' => $page->getLinks()->getNext()?->getHref()],
                        'prev' => ['href' => $page->getLinks()->getPrev()?->getHref()],
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return $this->buildSdkErrorResult($e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchOperationsViaSdk(
        StellarSDK $sdk,
        string $txHash,
        int &$rateLimitRetries,
        int &$rateLimitWaitSeconds,
        ?callable $onProgress = null,
    ): array
    {
        try {
            $page = $this->runWithRateLimitRetry(
                fn () => $sdk->operations()
                    ->forTransaction($txHash)
                    ->limit(200)
                    ->order('asc')
                    ->execute(),
                $rateLimitRetries,
                $rateLimitWaitSeconds,
                $onProgress,
                ['scope' => 'operations', 'txHash' => $txHash],
            );

            $records = [];
            foreach ($page->getOperations()->toArray() as $operation) {
                if ($operation instanceof OperationResponse) {
                    $records[] = $this->serializeOperation($operation);
                }
            }
            return [
                'ok' => true,
                'result' => [
                    '_embedded' => ['records' => $records],
                    '_links' => [
                        'self' => ['href' => $page->getLinks()->getSelf()?->getHref()],
                        'next' => ['href' => $page->getLinks()->getNext()?->getHref()],
                        'prev' => ['href' => $page->getLinks()->getPrev()?->getHref()],
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return $this->buildSdkErrorResult($e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSdkErrorResult(\Throwable $e): array
    {
        $result = [
            'ok' => false,
            'error' => ['message' => $e->getMessage()],
        ];

        if ($e instanceof HorizonRequestException) {
            $result['httpCode'] = $e->getStatusCode();
            $result['retryAfter'] = $e->getRetryAfter();
        }

        return $result;
    }

    private function runWithRateLimitRetry(
        callable $callback,
        int &$rateLimitRetries,
        int &$rateLimitWaitSeconds,
        ?callable $onProgress = null,
        array $context = [],
    ): mixed
    {
        $noThrottleMode = $this->isNoThrottleMode();
        $maxRetries = (int) (getenv('HORIZON_RETRY_MAX') ?: self::DEFAULT_MAX_RETRIES);
        if ($noThrottleMode) {
            $maxRetries = 0;
        }
        $maxRetries = max(0, $maxRetries);
        $attempt = 0;

        while (true) {
            try {
                $result = $callback();
                if (!$noThrottleMode) {
                    $this->sleepMs($this->getMinDelayMs());
                }
                return $result;
            } catch (\Throwable $e) {
                $retryAfterSeconds = $this->extractRetryAfterSeconds($e, $attempt);
                if ($retryAfterSeconds === null || $attempt >= $maxRetries) {
                    throw $e;
                }

                $isRateLimited = $this->isRateLimitException($e);
                if ($isRateLimited) {
                    $rateLimitRetries++;
                    $rateLimitWaitSeconds += $retryAfterSeconds;
                }
                $event = $this->buildRateLimitEvent($e, $retryAfterSeconds, $attempt + 1, $maxRetries, $context);
                if (is_callable($onProgress)) {
                    $onProgress($event + ['phase' => $isRateLimited ? 'rate_limited' : 'horizon_retry']);
                }
                if (!$noThrottleMode) {
                    $this->sleepMs($retryAfterSeconds * 1000);
                }
                if (is_callable($onProgress)) {
                    $onProgress($event + ['phase' => $isRateLimited ? 'rate_limit_resumed' : 'horizon_retry_resumed']);
                }
                $attempt++;
            }
        }
    }

    /**
     * @param array<int,mixed> $records
     */
    private function buildPageSignature(array $records): string
    {
        $hashes = [];
        foreach ($records as $record) {
            if ($record instanceof TransactionResponse) {
                $hash = $record->getHash();
                if (is_string($hash) && $hash !== '') {
                    $hashes[] = $hash;
                }
            }
        }

        if ($hashes === []) {
            return '';
        }

        return implode('|', $hashes);
    }

    private function extractRetryAfterSeconds(\Throwable $e, int $attempt): ?int
    {
        if ($this->isRateLimitException($e)) {
            $wait = $this->extractRateLimitWaitSeconds($e);
            return $wait ?? self::DEFAULT_TRANSIENT_RETRY_WAIT_SECONDS;
        }

        if ($this->isRetryableHttpStatus($e) || $this->isTransientTransportException($e)) {
            $wait = self::DEFAULT_TRANSIENT_RETRY_WAIT_SECONDS * (2 ** max(0, $attempt));
            return min(self::MAX_TRANSIENT_RETRY_WAIT_SECONDS, max(1, $wait));
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildRateLimitEvent(
        \Throwable $exception,
        int $waitSeconds,
        int $attempt,
        int $maxRetries,
        array $context,
    ): array {
        $event = [
            'waitSeconds' => $waitSeconds,
            'attempt' => $attempt,
            'maxRetries' => $maxRetries,
            'scope' => (string) ($context['scope'] ?? 'unknown'),
            'txHash' => (string) ($context['txHash'] ?? ''),
        ];

        if (!$exception instanceof HorizonRequestException) {
            return $event;
        }

        $event['httpCode'] = $exception->getStatusCode();
        $httpResponse = $exception->getHttpResponse();
        if ($httpResponse === null) {
            return $event;
        }

        $header = function (string $name) use ($httpResponse): ?string {
            $values = $httpResponse->getHeader($name);
            if (!is_array($values) || !isset($values[0]) || !is_string($values[0])) {
                return null;
            }

            $trimmed = trim($values[0]);
            return $trimmed !== '' ? $trimmed : null;
        };

        $event['rateLimitLimit'] = $header('X-RateLimit-Limit');
        $event['rateLimitRemaining'] = $header('X-RateLimit-Remaining');
        $event['rateLimitReset'] = $header('X-RateLimit-Reset');

        return $event;
    }

    private function isRateLimitException(\Throwable $e): bool
    {
        return $e instanceof HorizonRequestException && $e->getStatusCode() === 429;
    }

    private function extractRateLimitWaitSeconds(\Throwable $e): ?int
    {
        if (!$e instanceof HorizonRequestException) {
            return null;
        }

        $retryAfter = $e->getRetryAfter();
        if (is_string($retryAfter) && preg_match('/^[0-9]+$/', trim($retryAfter)) === 1) {
            return max(1, (int) trim($retryAfter));
        }

        $httpResponse = $e->getHttpResponse();
        if ($httpResponse !== null) {
            $resetHeader = $httpResponse->getHeader('X-RateLimit-Reset');
            if (is_array($resetHeader) && isset($resetHeader[0]) && is_string($resetHeader[0])) {
                $value = trim($resetHeader[0]);
                if (preg_match('/^[0-9]+$/', $value) === 1) {
                    $resetValue = (int) $value;
                    $now = time();
                    if ($resetValue > $now) {
                        return max(1, $resetValue - $now);
                    }

                    return max(1, $resetValue);
                }
            }
        }

        return null;
    }

    private function isRetryableHttpStatus(\Throwable $e): bool
    {
        if (!$e instanceof HorizonRequestException) {
            return false;
        }

        $statusCode = $e->getStatusCode();
        return in_array($statusCode, [408, 425, 500, 502, 503, 504], true);
    }

    private function isTransientTransportException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'curl error 56')
            || str_contains($message, 'curl error 52')
            || str_contains($message, 'curl error 35')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'ssl_read')
            || str_contains($message, 'unexpected eof')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'timed out');
    }

    private function getMinDelayMs(): int
    {
        if ($this->isNoThrottleMode()) {
            return 0;
        }

        $configured = (int) (getenv('HORIZON_MIN_REQUEST_DELAY_MS') ?: self::DEFAULT_MIN_REQUEST_DELAY_MS);
        return max(0, $configured);
    }

    private function isNoThrottleMode(): bool
    {
        $raw = trim((string) (getenv('HORIZON_NO_THROTTLE') ?: ''));
        if ($raw === '') {
            return false;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeTransaction(TransactionResponse $tx): array
    {
        return [
            'id' => $tx->getId(),
            'hash' => $tx->getHash(),
            'ledger' => $tx->getLedger(),
            'created_at' => $tx->getCreatedAt(),
            'source_account' => $tx->getSourceAccount(),
            'fee_account' => $tx->getFeeAccount(),
            'fee_charged' => $tx->getFeeCharged(),
            'max_fee' => $tx->getMaxFee(),
            'operation_count' => $tx->getOperationCount(),
            'successful' => $tx->isSuccessful(),
            'paging_token' => $tx->getPagingToken(),
            'envelope_xdr' => $tx->getEnvelopeXdrBase64(),
            'result_xdr' => $tx->getResultXdrBase64(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeEffect(EffectResponse $effect): array
    {
        return [
            'id' => $effect->getEffectId(),
            'paging_token' => $effect->getPagingToken(),
            'account' => $effect->getAccount(),
            'type' => $effect->getHumanReadableEffectType(),
            'type_i' => $effect->getEffectType(),
            'created_at' => $effect->getCreatedAt(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeOperation(OperationResponse $operation): array
    {
        $payload = [
            'id' => $operation->getOperationId(),
            'paging_token' => $operation->getPagingToken(),
            'source_account' => $operation->getSourceAccount(),
            'type' => $operation->getHumanReadableOperationType(),
            'type_i' => $operation->getOperationType(),
            'created_at' => $operation->getCreatedAt(),
            'transaction_hash' => $operation->getTransactionHash(),
            'transaction_successful' => $operation->isTransactionSuccessful(),
        ];

        if ($operation instanceof PaymentOperationResponse) {
            $asset = $this->serializeAssetSafe(fn () => $operation->getAsset());
            $from = $this->safeOperationValue(fn () => $operation->getFrom());
            $to = $this->safeOperationValue(fn () => $operation->getTo());
            $amount = $this->safeOperationValue(fn () => $operation->getAmount());
            if (is_string($from) && $from !== '') {
                $payload['from'] = $from;
            }
            if (is_string($to) && $to !== '') {
                $payload['to'] = $to;
            }
            if (is_string($amount) && $amount !== '') {
                $payload['amount'] = $amount;
            }
            $payload['asset_type'] = $asset['type'];
            if (isset($asset['code'])) {
                $payload['asset_code'] = $asset['code'];
            }
            if (isset($asset['issuer'])) {
                $payload['asset_issuer'] = $asset['issuer'];
            }
        }

        if ($operation instanceof PathPaymentOperationResponse) {
            $asset = $this->serializeAssetSafe(fn () => $operation->getAsset());
            $sourceAsset = $this->serializeAssetSafe(fn () => $operation->getSourceAsset());
            $from = $this->safeOperationValue(fn () => $operation->getFrom());
            $to = $this->safeOperationValue(fn () => $operation->getTo());
            $amount = $this->safeOperationValue(fn () => $operation->getAmount());
            $sourceAmount = $this->safeOperationValue(fn () => $operation->getSourceAmount());
            if (is_string($from) && $from !== '') {
                $payload['from'] = $from;
            }
            if (is_string($to) && $to !== '') {
                $payload['to'] = $to;
            }
            if (is_string($amount) && $amount !== '') {
                $payload['amount'] = $amount;
            }
            if (is_string($sourceAmount) && $sourceAmount !== '') {
                $payload['source_amount'] = $sourceAmount;
            }
            $payload['asset_type'] = $asset['type'];
            if (isset($asset['code'])) {
                $payload['asset_code'] = $asset['code'];
            }
            if (isset($asset['issuer'])) {
                $payload['asset_issuer'] = $asset['issuer'];
            }
            $payload['source_asset_type'] = $sourceAsset['type'];
            if (isset($sourceAsset['code'])) {
                $payload['source_asset_code'] = $sourceAsset['code'];
            }
            if (isset($sourceAsset['issuer'])) {
                $payload['source_asset_issuer'] = $sourceAsset['issuer'];
            }
        }

        return $payload;
    }

    /**
     * @return array{type:string,code?:string,issuer?:string}
     */
    private function serializeAsset(Asset $asset): array
    {
        $payload = ['type' => $asset->getType()];
        if ($asset instanceof AssetTypeCreditAlphanum) {
            $payload['code'] = $asset->getCode();
            $payload['issuer'] = $asset->getIssuer();
        }

        return $payload;
    }

    /**
     * @return array{type:string,code?:string,issuer?:string}
     */
    private function serializeAssetSafe(callable $loader): array
    {
        try {
            $asset = $loader();
        } catch (\Throwable) {
            return ['type' => 'unknown'];
        }

        if (!$asset instanceof Asset) {
            return ['type' => 'unknown'];
        }

        return $this->serializeAsset($asset);
    }

    private function safeOperationValue(callable $loader): mixed
    {
        try {
            return $loader();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array{txHash:string,scope:string,message:string,httpCode:int|null}
     */
    private function buildRequestErrorSample(string $txHash, string $scope, array $result): array
    {
        $message = is_string($result['error']['message'] ?? null)
            ? trim((string) $result['error']['message'])
            : 'unknown remote error';

        return [
            'txHash' => $txHash,
            'scope' => $scope,
            'message' => $message !== '' ? $message : 'unknown remote error',
            'httpCode' => isset($result['httpCode']) ? (int) $result['httpCode'] : null,
        ];
    }

}
