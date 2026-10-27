<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ContractWasmController;
use App\Service\ContractRead\ContractReadGateway;
use App\Service\ContractRead\ContractReadMapper;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ContractWasmControllerTest extends TestCase
{
    private const CONTRACT = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';
    private const HASH = '93a44bbb96c751218e4c00d479e4c14358122a389acca16205b1e4d0dc5f9476';
    private const WASM = "\0asm\x01\0\0\0";

    public function testReturnsVerifiedBinaryWithoutDatabaseDependency(): void
    {
        $controller = $this->controller($this->descriptor(), $this->code());
        $response = $controller(self::CONTRACT, new Request(['network' => 'mainnet']));

        self::assertSame(self::WASM, $response->getContent());
        self::assertSame('application/wasm', $response->headers->get('Content-Type'));
        self::assertSame('8', $response->headers->get('Content-Length'));
        self::assertSame(self::HASH, $response->headers->get('X-Wasm-SHA256'));
        self::assertStringContainsString(self::CONTRACT.'.wasm', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('must-revalidate', $response->headers->get('Cache-Control'));
    }

    public function testVerifiedHashSupportsConditionalDownloads(): void
    {
        $request = new Request(['network' => 'mainnet']);
        $request->headers->set('If-None-Match', '"'.self::HASH.'"');
        $response = $this->controller($this->descriptor(), $this->code())(self::CONTRACT, $request);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testTamperedBinaryIsRejected(): void
    {
        $code = $this->code();
        $code['wasm_base64'] = base64_encode(self::WASM."\0");
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->controller($this->descriptor(), $code)(self::CONTRACT, new Request());
    }

    public function testDifferentDeployedExecutableIsRejected(): void
    {
        $descriptor = $this->descriptor();
        $descriptor['wasm_hash'] = str_repeat('a', 64);
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->controller($descriptor, $this->code())(self::CONTRACT, new Request());
    }

    public function testOtherContractCodeIsRejected(): void
    {
        $code = $this->code();
        $code['contract_id'] = 'CDDS7IQJGQ2ZMO66E3MUYXZ56H2OO7RBTTAGZLZKOEA4EXCGZX65JGA7';
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->controller($this->descriptor(), $code)(self::CONTRACT, new Request());
    }

    public function testMalformedBase64IsRejected(): void
    {
        $code = $this->code();
        $code['wasm_base64'] = '%invalid';
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->controller($this->descriptor(), $code)(self::CONTRACT, new Request());
    }

    public function testDeclaredSizeMustMatchTheBinary(): void
    {
        $code = $this->code();
        $code['wasm_size_bytes'] = 100;
        $this->expectException(ServiceUnavailableHttpException::class);
        $this->controller($this->descriptor(), $code)(self::CONTRACT, new Request());
    }

    public function testStellarAssetContractDoesNotProduceAWasmFile(): void
    {
        $descriptor = $this->descriptor();
        $descriptor['executable_type'] = 'stellar_asset';
        unset($descriptor['wasm_hash']);
        $this->expectException(NotFoundHttpException::class);
        $this->controller($descriptor, $this->code())(self::CONTRACT, new Request());
    }

    public function testTestnetDoesNotQueryMainnetOrion(): void
    {
        $http = new MockHttpClient([]);
        $controller = $this->controller($this->descriptor(), $this->code(), $http);
        try {
            $controller(self::CONTRACT, new Request(['network' => 'testnet']));
            self::fail('Mainnet WASM must not be returned on testnet.');
        } catch (NotFoundHttpException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    private function descriptor(): array
    {
        return ['contract_id' => self::CONTRACT, 'executable_type' => 'wasm', 'wasm_hash' => self::HASH];
    }

    private function code(): array
    {
        return $this->descriptor() + ['wasm_base64' => base64_encode(self::WASM), 'wasm_size_bytes' => 8];
    }

    private function controller(array $descriptor, array $code, ?MockHttpClient $http = null): ContractWasmController
    {
        $responses = [new MockResponse(json_encode($descriptor, JSON_THROW_ON_ERROR)), new MockResponse(json_encode($code, JSON_THROW_ON_ERROR))];
        $mapper = new SorobanScValMapper();
        $gateway = new ContractReadGateway($http ?? new MockHttpClient($responses),
            new ContractReadMapper(new InvokeContractCallExtractor($mapper), $mapper),
            new NullLogger(), new ArrayAdapter(), 'https://orion.example', true);

        return new ContractWasmController($gateway, new StellarNetworkResolver());
    }
}
