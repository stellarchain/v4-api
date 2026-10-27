<?php

declare(strict_types=1);

namespace App\Tests\Service\ContractRead;

use App\Service\ContractRead\ContractReadGateway;
use App\Service\ContractRead\ContractReadMapper;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ContractReadGatewayTest extends TestCase
{
    public const CONTRACT_ID = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';
    public const WASM_HASH = '8ae1550db6f33311e473c1e853d192dd72623cc02c993608a86382469473fa91';

    public function testDetailsAreMappedWithoutInventingDeploymentMetadata(): void
    {
        $gateway = $this->gateway([self::response(self::contractRecord())]);
        $result = $gateway->findContract(self::CONTRACT_ID, 'mainnet');

        self::assertSame(self::WASM_HASH, $result['wasm_id']);
        self::assertSame('9337e6b5c48e32cd35d6cd7b88061a100d1842dfa7beafd6607959edb1e47cd8', $result['contract_id_hex']);
        self::assertSame(5, $result['total_storage_entries']);
        self::assertArrayNotHasKey('deployed_at', $result);
        self::assertArrayNotHasKey('deploy_source_account', $result);
    }

    public function testObservationMetadataDoesNotBecomeDeploymentMetadata(): void
    {
        $record = self::contractRecord();
        $record['first_seen_at'] = '2025-11-14T10:20:14Z';
        $record['last_seen_at'] = '2025-12-23T15:04:00Z';
        $record['wasm_size_bytes'] = 9865;
        $record['archived'] = false;
        $result = $this->gateway([self::response($record)])->findContract(self::CONTRACT_ID, 'mainnet');
        self::assertSame('2025-11-14T10:20:14Z', $result['orion_first_seen_at']);
        self::assertSame('2025-12-23T15:04:00Z', $result['orion_last_seen_at']);
        self::assertSame(9865, $result['orion_wasm_size_bytes']);
        self::assertFalse($result['orion_archived']);
        self::assertArrayNotHasKey('created_at', $result);
        self::assertArrayNotHasKey('deployed_at', $result);
    }

    public function testNonMainnetAndDisabledReadsNeverRequestOrion(): void
    {
        $http = new MockHttpClient([]);
        $gateway = $this->gateway([], $http);
        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'testnet'));
        self::assertNull($gateway->collection(self::CONTRACT_ID, 'futurenet', 'events', []));
        self::assertNull($this->gateway([], $http, false)->findContract(self::CONTRACT_ID, 'mainnet'));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testFailuresAndMissingContractsSelectLocalFallback(): void
    {
        $gateway = $this->gateway([
            new MockResponse('{}', ['http_code' => 404]),
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('invalid-json'),
        ]);
        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'mainnet'));
        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'mainnet'));
        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'mainnet'));
    }

    public function testDifferentWasmCannotInheritLocalSourceVerification(): void
    {
        $gateway = $this->gateway([self::response(self::contractRecord())]);
        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'mainnet', str_repeat('a', 64)));
    }

    public function testDifferentContractIdentityIsRejected(): void
    {
        $record = self::contractRecord();
        $record['contract_id'] = 'CDDS7IQJGQ2ZMO66E3MUYXZ56H2OO7RBTTAGZLZKOEA4EXCGZX65JGA7';
        self::assertNull($this->gateway([self::response($record)])->findContract(self::CONTRACT_ID, 'mainnet'));
    }

    public function testEventsRetainXdrAndFailedDiagnosticStatus(): void
    {
        $record = self::eventRecord('event-1');
        $record['record_kind'] = 'diagnostic';
        $result = $this->gateway([self::response(['records' => [$record], 'latest_ledger' => 65164030])])
            ->collection(self::CONTRACT_ID, 'mainnet', 'events', []);

        self::assertSame('transfer', $result['items'][0]['eventType']);
        self::assertSame($record['topic_xdr'], $result['items'][0]['topicDecoded']);
        self::assertSame($record['value_xdr'], $result['items'][0]['valueDecoded']);
        self::assertFalse($result['items'][0]['successful']);
        self::assertTrue($result['items'][0]['isDiagnostic']);
        self::assertNull($result['items'][0]['id']);
        self::assertSame(65164030, $result['meta']['latestLedger']);
    }

    public function testCursorAndSequentialNumericPagesUseSameContinuation(): void
    {
        $first = self::response(['records' => [self::eventRecord('first')], 'next_cursor' => 'next-1']);
        $second = self::response(['records' => [self::eventRecord('second')]]);
        $third = self::response(['records' => []]);
        $http = new MockHttpClient([$first, $second, $third]);
        $gateway = $this->gateway([], $http);
        $page1 = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1]);
        $page2 = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1, 'page' => 2]);
        $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1, 'cursor' => $page1['meta']['nextCursor']]);

        self::assertTrue($page1['meta']['hasMore']);
        self::assertSame('second', $page2['items'][0]['upstreamId']);
        self::assertStringContainsString('cursor=next-1', $second->getRequestUrl());
        self::assertStringContainsString('cursor=next-1', $third->getRequestUrl());
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testCursorCannotBeReusedForAnotherPageSize(): void
    {
        $gateway = $this->gateway([self::response(['records' => [self::eventRecord('first')], 'next_cursor' => 'next'])]);
        $page = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1]);

        $this->expectException(BadRequestHttpException::class);
        $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 2, 'cursor' => $page['meta']['nextCursor']]);
    }

    public function testLocalFiltersStayLocal(): void
    {
        $http = new MockHttpClient([]);
        $gateway = $this->gateway([], $http);
        self::assertNull($gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['ledgerStart' => 123]));
        self::assertNull($gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['beforeId' => 23]));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testUnpaginatedStorageIsSlicedWithoutFabricatedLedgerEntryXdr(): void
    {
        $records = [self::storageRecord('first'), self::storageRecord('second'), self::storageRecord('third')];
        $response = ['_embedded' => ['records' => $records]];
        $gateway = $this->gateway([self::response($response), self::response($response)]);
        $page1 = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'storage', ['itemsPerPage' => 2]);
        $page2 = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'storage', ['itemsPerPage' => 2, 'page' => 2]);

        self::assertCount(2, $page1['items']);
        self::assertTrue($page1['meta']['hasMore']);
        self::assertSame(3, $page1['meta']['totalItems']);
        self::assertSame('third', $page2['items'][0]['upstreamId']);
        self::assertFalse($page2['meta']['hasMore']);
        self::assertNull($page2['items'][0]['entryXdr']);
        self::assertSame('instance', $page2['items'][0]['entryDecoded']['durability']);
    }

    public function testUnrelatedDexTransactionIsRejectedInsteadOfRelabelled(): void
    {
        $record = $this->fixture('unrelated-dex-transaction');
        $gateway = $this->gateway([self::response(['_embedded' => ['records' => [$record]]])]);

        self::assertNull($gateway->collection(self::CONTRACT_ID, 'mainnet', 'transactions', []));
    }

    public function testDirectInvocationIsAcceptedAndPreservesFailure(): void
    {
        $record = $this->fixture('direct-invocation');
        $gateway = $this->gateway([self::response(['_embedded' => ['records' => [$record]]])]);
        $page = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'transactions', ['invocationsOnly' => true]);

        self::assertSame('activate', $page['items'][0]['hostFunctions']['invokeContracts'][0]['functionName']);
        self::assertSame($record['hash'], $page['items'][0]['txHash']);
        self::assertFalse($page['items'][0]['successful']);
    }

    public function testTransactionHashMustMatchItsEnvelope(): void
    {
        $record = $this->fixture('direct-invocation');
        $record['hash'] = str_repeat('b', 64);
        $gateway = $this->gateway([self::response(['records' => [$record]])]);

        self::assertNull($gateway->collection(self::CONTRACT_ID, 'mainnet', 'transactions', ['invocationsOnly' => true]));
    }

    public function testOversizedResponseIsRejectedBeforeMapping(): void
    {
        $record = self::contractRecord();
        $record['padding'] = str_repeat('x', 4 * 1024 * 1024);
        $gateway = $this->gateway([self::response($record)]);

        self::assertNull($gateway->findContract(self::CONTRACT_ID, 'mainnet'));
    }

    public function testContinuationFailureDoesNotSwitchToLocalHistory(): void
    {
        $gateway = $this->gateway([self::response(['records' => [self::eventRecord('first')], 'next_cursor' => 'next']), new MockResponse('{}', ['http_code' => 404])]);
        $page = $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1]);

        $this->expectException(ServiceUnavailableHttpException::class);
        $gateway->collection(self::CONTRACT_ID, 'mainnet', 'events', ['itemsPerPage' => 1, 'cursor' => $page['meta']['nextCursor']]);
    }

    public static function contractRecord(): array
    {
        return ['contract_id' => self::CONTRACT_ID, 'executable_type' => 'wasm', 'wasm_hash' => self::WASM_HASH,
            'storage_entries_count' => 5, 'functions' => [['name' => 'activate', 'args' => [], 'return_type' => 'void']]];
    }

    public static function eventRecord(string $id): array
    {
        return ['contract_id' => self::CONTRACT_ID, 'id' => $id, 'ledger_sequence' => 65164030,
            'transaction_hash' => str_repeat('a', 64), 'topic_xdr' => [XdrSCVal::forSymbol('transfer')->toBase64Xdr()],
            'value_xdr' => XdrSCVal::forI128BigInt('10000000')->toBase64Xdr(), 'successful' => false];
    }

    public static function storageRecord(string $id): array
    {
        return ['contract_id' => self::CONTRACT_ID, 'id' => $id, 'durability' => 'instance',
            'key_xdr' => XdrSCVal::forSymbol($id)->toBase64Xdr(), 'val_xdr' => XdrSCVal::forTrue()->toBase64Xdr(),
            'last_modified_ledger' => 59843291];
    }

    public static function response(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function gateway(array $responses, ?MockHttpClient $http = null, bool $enabled = true): ContractReadGateway
    {
        $scValMapper = new SorobanScValMapper();

        return new ContractReadGateway($http ?? new MockHttpClient($responses),
            new ContractReadMapper(new InvokeContractCallExtractor($scValMapper), $scValMapper),
            new NullLogger(), new ArrayAdapter(), 'https://orion.example', $enabled);
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
