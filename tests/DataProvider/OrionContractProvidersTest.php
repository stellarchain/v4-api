<?php

declare(strict_types=1);

namespace App\Tests\DataProvider;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\DataProvider\ContractEventsCollectionProvider;
use App\DataProvider\ContractItemDataProvider;
use App\DataProvider\ContractStorageEntriesCollectionProvider;
use App\DataProvider\ContractTransactionsCollectionProvider;
use App\Service\ContractRead\ContractReadGateway;
use App\Service\ContractRead\ContractReadMapper;
use App\Service\ContractTransparency\ContractTransparencyCursor;
use App\Service\Stellar\Soroban\ContractRpcFallbackResolverInterface;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class OrionContractProvidersTest extends TestCase
{
    private const CONTRACT_ID = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';
    private const WASM_HASH = '8ae1550db6f33311e473c1e853d192dd72623cc02c993608a86382469473fa91';

    public function testMissingLocalContractStillReturnsOrionDetails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $rpc = $this->createMock(ContractRpcFallbackResolverInterface::class);
        $rpc->expects(self::never())->method('resolve');
        $provider = new ContractItemDataProvider($connection, new StellarNetworkResolver(), $this->requests(), $rpc,
            $this->gateway($this->contractRecord()));

        $result = $provider->provide(new Get(), ['contractId' => self::CONTRACT_ID]);

        self::assertSame(self::CONTRACT_ID, $result->contractId);
        self::assertSame(self::WASM_HASH, $result->wasmId);
        self::assertSame('orion', $result->chainDataSource);
        self::assertNull($result->id);
        self::assertNull($result->createdAt);
        self::assertNull($result->verifiedMetadata);
        self::assertFalse($result->sep55Verified);
        self::assertSame(5, $result->totalStorageEntries);
        self::assertSame('activate', $result->functions[0]->name);
    }

    public function testLocalEditorialMetadataAndLargerCountsSurviveEnrichment(): void
    {
        $row = ['id' => 123, 'contract_id' => self::CONTRACT_ID, 'wasm_id' => self::WASM_HASH,
            'verified_display_name' => 'Carbon sink', 'verified_is_sep41' => null,
            'verified_decimals' => null, 'verified_metadata_is_verified' => true,
            'verified_added_at' => null, 'verified_raw_payload' => null,
            'sep55_verified' => true, 'total_events' => 17];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);
        $rpc = $this->createMock(ContractRpcFallbackResolverInterface::class);
        $rpc->expects(self::never())->method('resolve');
        $provider = new ContractItemDataProvider($connection, new StellarNetworkResolver(), $this->requests(), $rpc,
            $this->gateway($this->contractRecord()));

        $result = $provider->provide(new Get(), ['contractId' => self::CONTRACT_ID]);

        self::assertSame(123, $result->id);
        self::assertSame('Carbon sink', $result->verifiedMetadata->displayName);
        self::assertTrue($result->sep55Verified);
        self::assertSame(17, $result->totalEvents);
    }

    public function testCollectionsDoNotRequireALocalContractRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('fetchFirstColumn');
        $requests = $this->requests();
        $resolver = new StellarNetworkResolver();
        $codec = new ContractTransparencyCursor();
        $gateway = $this->gateway(['records' => []], 3);
        $providers = [
            new ContractEventsCollectionProvider($connection, $resolver, $requests, $codec, $gateway),
            new ContractTransactionsCollectionProvider($connection, $resolver, $requests, $codec, $gateway),
            new ContractStorageEntriesCollectionProvider($connection, $resolver, $requests, $codec, $gateway),
        ];
        foreach ($providers as $provider) {
            self::assertSame([], $provider->provide(new GetCollection(), ['contractId' => self::CONTRACT_ID]));
            self::assertSame('orion', $requests->getCurrentRequest()->attributes->get('_cursor_meta')['source']);
        }
    }

    public function testStorageDataIsReturnedEvenWhenNoContractWasIndexedLocally(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $payload = ['records' => [['contract_id' => self::CONTRACT_ID, 'id' => 'Admin',
            'durability' => 'instance', 'key_xdr' => 'AAAAEAAAAAEAAAABAAAADwAAAAVBZG1pbgAAAA==',
            'val_xdr' => 'AAAAEgAAAAAAAAAAviHZgrNFj6cUt2gSOW0MptGpWZFmdRste+TrrLgt9ik=',
            'last_modified_ledger' => 59843291]]];
        $requests = $this->requests();
        $provider = new ContractStorageEntriesCollectionProvider($connection, new StellarNetworkResolver(),
            $requests, new ContractTransparencyCursor(), $this->gateway($payload));

        $result = $provider->provide(new GetCollection(), ['contractId' => self::CONTRACT_ID]);

        self::assertCount(1, $result);
        self::assertSame($payload['records'][0]['val_xdr'], $result[0]['entryDecoded']['valueXdr']);
        self::assertSame(1, $requests->getCurrentRequest()->attributes->get('_cursor_meta')['totalItems']);
    }

    private function requests(): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(new Request(['network' => 'mainnet']));

        return $requests;
    }

    private function contractRecord(): array
    {
        return ['contract_id' => self::CONTRACT_ID, 'executable_type' => 'wasm', 'wasm_hash' => self::WASM_HASH,
            'storage_entries_count' => 5, 'functions' => [['name' => 'activate']]];
    }

    private function gateway(array $payload, int $copies = 1): ContractReadGateway
    {
        $responses = [];
        for ($index = 0; $index < $copies; ++$index) {
            $responses[] = new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $scValMapper = new SorobanScValMapper();

        return new ContractReadGateway(new MockHttpClient($responses),
            new ContractReadMapper(new InvokeContractCallExtractor($scValMapper), $scValMapper),
            new NullLogger(), new ArrayAdapter(), 'https://orion.example', true);
    }
}
