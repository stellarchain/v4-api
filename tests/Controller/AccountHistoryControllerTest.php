<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AccountHistoryController;
use App\Service\AccountRead\AccountReadGateway;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AccountHistoryControllerTest extends TestCase
{
    private const ADDRESS = 'GB44QL5XZTAXZDPDGAPDMGZ3NG4ZVPJXNTFWE22I7RZKKT25ATBJJWXI';

    public function testHistoryDoesNotRequireCurrentAccountLookup(): void
    {
        $response = new MockResponse('{"_embedded":{"records":[]}}');
        $http = new MockHttpClient([$response]);
        $controller = $this->controller($http);

        $result = $controller(self::ADDRESS, 'transactions', new Request(['network' => 'mainnet', 'limit' => '25']));
        $body = json_decode((string) $result->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame([], $body['records']);
        self::assertSame('horizon', $body['meta']['source']);
        self::assertStringStartsWith('https://horizon.stellar.org/accounts/'.self::ADDRESS.'/transactions?', $response->getRequestUrl());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testInvalidPageSizeIsRejectedBeforeCallingUpstream(): void
    {
        $http = new MockHttpClient([]);
        $controller = $this->controller($http);

        try {
            $controller(self::ADDRESS, 'operations', new Request(['limit' => '201']));
            self::fail('Oversized page must be rejected.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    private function controller(MockHttpClient $http): AccountHistoryController
    {
        $resolver = new StellarNetworkResolver();

        return new AccountHistoryController(new AccountReadGateway($http, $resolver, new NullLogger(), 'https://orion.example', true), $resolver);
    }
}
