<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ContractDecompileController;
use App\Service\ContractRead\ContractDecompilationService;
use App\Service\ContractRead\ContractReadGateway;
use App\Service\ContractRead\ContractReadMapper;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\Soroban\SorobanWasmDecompiler;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ContractDecompileControllerTest extends TestCase
{
    private const ID = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';
    private const WASM = "\0asm\x01\0\0\0";
    private const HASH = '93a44bbb96c751218e4c00d479e4c14358122a389acca16205b1e4d0dc5f9476';
    private string $directory;
    private string|false $previousBinary;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sc-decompile-api-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->previousBinary = getenv('SOROBAN_AUDITOR_BINARY');
        file_put_contents($this->directory.'/auditor', "#!/usr/bin/env php\n<?php file_put_contents(\$argv[2], 'pub fn reconstructed() {}');\n");
        chmod($this->directory.'/auditor', 0700);
        putenv('SOROBAN_AUDITOR_BINARY='.$this->directory.'/auditor');
    }

    protected function tearDown(): void
    {
        putenv($this->previousBinary === false ? 'SOROBAN_AUDITOR_BINARY' : 'SOROBAN_AUDITOR_BINARY='.$this->previousBinary);
        foreach (glob($this->directory.'/*') as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public function testDecompilesAndCachesByVerifiedExecutableHash(): void
    {
        $controller = $this->controller();
        $first = json_decode($controller(self::ID, $this->request())->getContent(), true);
        self::assertSame('pub fn reconstructed() {}', $first['sourceCode']);
        self::assertSame('decompiled', $first['type']);
        self::assertFalse($first['cached']);
        // A cache hit must not run the auditor again.
        putenv('SOROBAN_AUDITOR_BINARY='.$this->directory.'/missing');
        $response = $controller(self::ID, $this->request());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertTrue(json_decode($response->getContent(), true)['cached']);
    }

    public function testRejectsStalePageHashBeforeRunningDecompiler(): void
    {
        $request = Request::create('/', 'POST', content: json_encode(['wasmHash' => str_repeat('a', 64)]));
        $this->expectException(ConflictHttpException::class);
        $this->controller()(self::ID, $request);
    }

    public function testRequiresAnExpectedHash(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->controller()(self::ID, Request::create('/', 'POST', content: '{}'));
    }

    public function testSacHasNoWasmToDecompile(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller(true)(self::ID, $this->request());
    }

    public function testConcurrentExecutionIsBounded(): void
    {
        $lock = fopen($this->directory.'/soroban-decompilation.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(TooManyRequestsHttpException::class);
            $this->controller()(self::ID, $this->request());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testFailedExecutionIsNegativelyCached(): void
    {
        $controller = $this->controller();
        putenv('SOROBAN_AUDITOR_BINARY='.$this->directory.'/missing');
        try {
            $controller(self::ID, $this->request());
            self::fail('A missing binary must fail.');
        } catch (ServiceUnavailableHttpException) {
            putenv('SOROBAN_AUDITOR_BINARY='.$this->directory.'/auditor');
            $this->expectException(ServiceUnavailableHttpException::class);
            $this->expectExceptionMessage('recently failed');
            $controller(self::ID, $this->request());
        }
    }

    public function testTestnetNeverUsesMainnetWasm(): void
    {
        $request = Request::create('/?network=testnet', 'POST', content: json_encode(['wasmHash' => self::HASH]));
        $this->expectException(NotFoundHttpException::class);
        $this->controller()(self::ID, $request);
    }

    private function request(): Request
    {
        return Request::create('/', 'POST', content: json_encode(['wasmHash' => self::HASH]));
    }

    private function controller(bool $sac = false): ContractDecompileController
    {
        $descriptor = ['contract_id' => self::ID, 'executable_type' => $sac ? 'stellar_asset' : 'wasm', 'wasm_hash' => self::HASH];
        $code = $descriptor + ['wasm_base64' => base64_encode(self::WASM), 'wasm_size_bytes' => 8];
        $responses = [];
        for ($i = 0; $i < 2; ++$i) {
            $responses[] = new MockResponse(json_encode($descriptor));
            if (!$sac) { $responses[] = new MockResponse(json_encode($code)); }
        }
        $mapper = new SorobanScValMapper();
        $cache = new ArrayAdapter();
        $gateway = new ContractReadGateway(new MockHttpClient($responses), new ContractReadMapper(new InvokeContractCallExtractor($mapper), $mapper), new NullLogger(), $cache, 'https://orion.example', true);
        $service = new ContractDecompilationService($gateway, new SorobanWasmDecompiler($this->directory), $cache, new NullLogger(), $this->directory);

        return new ContractDecompileController($service, new StellarNetworkResolver());
    }
}
