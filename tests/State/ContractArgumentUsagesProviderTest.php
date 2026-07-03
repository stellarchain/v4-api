<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Operation;
use App\Repository\ContractArgumentUsageReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractArgumentUsagesProvider;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractArgumentUsagesProviderTest extends TestCase
{
    public function testProvideReturnsNestedArgumentMatchesWithMeta(): void
    {
        $targetContractId = 'CDYYTZZ7J2ADE6UVYZ4P37PJY25LVIG3EUKEMI4JNJSM4QVEYSITUHIU';

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE contracts (
            id INTEGER PRIMARY KEY,
            contract_id VARCHAR(64) NOT NULL,
            network INTEGER NOT NULL,
            deployed_ledger INTEGER DEFAULT NULL,
            deployed_at VARCHAR(32) DEFAULT NULL,
            wasm_id VARCHAR(128) DEFAULT NULL,
            executable_type INTEGER DEFAULT NULL,
            total_invokes INTEGER DEFAULT 0,
            total_storage_entries INTEGER DEFAULT 0
        )');
        $connection->executeStatement('CREATE TABLE contract_transactions (
            id INTEGER PRIMARY KEY,
            contract_id INTEGER NOT NULL,
            host_functions TEXT DEFAULT NULL
        )');
        $connection->executeStatement('CREATE TABLE contract_argument_usages (
            id INTEGER PRIMARY KEY,
            tx_hash VARCHAR(128) NOT NULL,
            source_account VARCHAR(64) DEFAULT NULL,
            ledger INTEGER DEFAULT NULL,
            created_at VARCHAR(32) DEFAULT NULL,
            function_name VARCHAR(128) DEFAULT NULL,
            matched_paths TEXT DEFAULT NULL,
            matches_count INTEGER DEFAULT 0,
            target_contract_address VARCHAR(64) NOT NULL,
            referenced_contract_id VARCHAR(64) NOT NULL,
            network INTEGER NOT NULL
        )');
        $connection->insert('contracts', [
            'id' => 1,
            'contract_id' => $targetContractId,
            'network' => 1,
            'deployed_ledger' => 61426367,
        ]);
        $connection->insert('contract_argument_usages', [
            'id' => 901,
            'tx_hash' => '46c4edd05d612d1ef7a093afae401bec2ecc67e0aa6522fade124163d72b6376',
            'source_account' => 'GBGVK3U6E7UWVLUDZWVICWZ6L5IWJ7YSDHAE5SRW6UXFSOACS7OU4YJS',
            'function_name' => 'distribute',
            'matched_paths' => json_encode(['$.args[2][0].address'], JSON_UNESCAPED_SLASHES),
            'matches_count' => 1,
            'ledger' => 61426367,
            'created_at' => '2026-02-27 18:45:00',
            'target_contract_address' => 'CCW67TSZV3SSS2HXMBQ5JFGCKJNXKZM7UQUWUZPUTHXSTZLEO7SJMI75',
            'referenced_contract_id' => $targetContractId,
            'network' => 1,
        ]);
        $readRepository = new ContractArgumentUsageReadRepository($connection);

        $request = new Request([
            'network' => 'mainnet',
            'itemsPerPage' => '30',
        ]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $provider = new ContractArgumentUsagesProvider(
            $readRepository,
            new StellarNetworkResolver(),
            $requestStack
        );

        $result = $provider->provide(
            $this->createMock(Operation::class),
            ['contractId' => $targetContractId]
        );

        self::assertCount(1, $result);
        self::assertSame(901, $result[0]->getId());
        self::assertSame('distribute', $result[0]->getMatches()[0]['functionName']);
        self::assertSame(1, $result[0]->getMatchesCount());
        self::assertSame('$.args[2][0].address', $result[0]->getMatches()[0]['matchedPaths'][0]);

        $meta = $request->attributes->get('_cursor_meta');
        self::assertIsArray($meta);
        self::assertFalse($meta['hasMore']);
        self::assertSame(30, $meta['itemsPerPage']);
    }
}
