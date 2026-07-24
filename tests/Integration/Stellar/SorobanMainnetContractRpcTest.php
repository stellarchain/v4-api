<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stellar;

use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Soroban\Responses\GetHealthResponse;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Symfony\Component\Dotenv\Dotenv;

/**
 * @group integration
 */
final class SorobanMainnetContractRpcTest extends TestCase
{
    private const CONTRACT_ID = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';

    private SorobanServer $server;
    private SorobanContractInspector $contractInspector;

    protected function setUp(): void
    {
        parent::setUp();

        (new Dotenv())->loadEnv(dirname(__DIR__, 3).'/.env');

        if (getenv('RUN_STELLAR_RPC_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_STELLAR_RPC_INTEGRATION_TESTS=1 to run live Stellar RPC integration tests.'
            );
        }

        $serverFactory = new SorobanServerFactory(new StellarNetworkResolver());
        $this->server = $serverFactory->create('mainnet');
        $this->contractInspector = new SorobanContractInspector($serverFactory);
    }

    public function testFindsAndInspectsConfiguredMainnetContract(): void
    {
        $health = $this->server->getHealth();
        self::assertNull($health->getError());
        self::assertSame(GetHealthResponse::HEALTHY, $health->getStatus());

        $network = $this->server->getNetwork();
        self::assertNull($network->getError());
        self::assertSame(Network::public()->getNetworkPassphrase(), $network->getPassphrase());

        $latestLedger = $this->server->getLatestLedger();
        self::assertNull($latestLedger->getError());
        self::assertGreaterThan(0, $latestLedger->getSequence());

        self::assertSame(self::CONTRACT_ID, $this->contractInspector->normalizeContractId(self::CONTRACT_ID));

        $executable = $this->contractInspector->loadContractExecutableMetaForContractId(
            $this->server,
            self::CONTRACT_ID,
        );
        self::assertSame(0, $executable['executableType'], 'Expected a WASM contract executable.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $executable['wasmId'] ?? '');

        $contractCode = $this->server->loadContractCodeForContractId(self::CONTRACT_ID);
        self::assertNotNull($contractCode);
        self::assertSame($executable['wasmId'], bin2hex($contractCode->getCHash()));
        self::assertNotSame('', $contractCode->getCode()->value);

        $contractInfo = $this->server->loadContractInfoForContractId(self::CONTRACT_ID);
        self::assertNotNull($contractInfo);

        $functionNames = array_map(
            static fn ($function): string => $function->getName(),
            $contractInfo->funcs,
        );
        self::assertContains('sink_carbon', $functionNames);
        self::assertContains('is_active', $functionNames);
        self::assertContains('get_minimum_sink_amount', $functionNames);
    }
}
