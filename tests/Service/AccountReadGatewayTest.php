<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AccountRead\AccountReadGateway;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class AccountReadGatewayTest extends TestCase
{
    private const ADDRESS = 'GB44QL5XZTAXZDPDGAPDMGZ3NG4ZVPJXNTFWE22I7RZKKT25ATBJJWXI';

    public function testCurrentStateUsesOrionAndPreservesBalances(): void
    {
        $response = $this->response($this->accountRecord());
        $gateway = $this->gateway(new MockHttpClient([$response]));

        $result = $gateway->account(self::ADDRESS, 'mainnet');

        self::assertSame('orion', $result['source']);
        self::assertSame('12.3400000', $result['record']['balances'][0]['balance']);
        self::assertStringStartsWith('https://orion.example/accounts/', $response->getRequestUrl());
    }

    public function testOtherNetworksNeverUseMainnetOrion(): void
    {
        $response = $this->response($this->accountRecord());
        $gateway = $this->gateway(new MockHttpClient([$response]));

        self::assertSame('horizon', $gateway->account(self::ADDRESS, 'testnet')['source']);
        self::assertStringStartsWith('https://horizon-testnet.stellar.org/', $response->getRequestUrl());
    }

    public function testMissingOrionStateFallsBackToCurrentHorizonState(): void
    {
        $gateway = $this->gateway(new MockHttpClient([new MockResponse('{}', ['http_code' => 404]), $this->response($this->accountRecord())]));

        self::assertSame('horizon', $gateway->account(self::ADDRESS, 'mainnet')['source']);
    }

    public function testHistoryIsReadableWithoutCurrentAccountState(): void
    {
        $gateway = $this->gateway(new MockHttpClient([
            new MockResponse('{}', ['http_code' => 404]), new MockResponse('{}', ['http_code' => 404]),
            $this->response(['_embedded' => ['records' => [['paging_token' => '100', 'hash' => str_repeat('a', 64)]]]]),
        ]));

        self::assertNull($gateway->account(self::ADDRESS, 'mainnet'));
        $history = $gateway->history(self::ADDRESS, 'mainnet', 'transactions', 25, 'desc', null, null);

        self::assertCount(1, $history['records']);
        self::assertSame('orion', $history['meta']['source']);
        self::assertFalse($history['meta']['hasMore']);
    }

    public function testFailedHistoryReadIsNotAnEmptyHistory(): void
    {
        $gateway = $this->gateway(new MockHttpClient([new MockResponse('{}', ['http_code' => 503])]));

        $this->expectException(ServiceUnavailableHttpException::class);
        $gateway->history(self::ADDRESS, 'mainnet', 'transactions', 25, 'desc', null, null);
    }

    public function testWrongCurrentAccountIdentityIsNeverReturned(): void
    {
        $record = $this->accountRecord();
        $record['account_id'] = 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF';
        $gateway = $this->gateway(new MockHttpClient([$this->response($record), new MockResponse('{}', ['http_code' => 404])]));

        self::assertNull($gateway->account(self::ADDRESS, 'mainnet'));
    }

    public function testCursorAndFailureFlagAreForwardedAsQueryParameters(): void
    {
        $response = $this->response(['_embedded' => ['records' => [['paging_token' => '99']]]]);
        $gateway = $this->gateway(new MockHttpClient([$response]));

        $page = $gateway->history(self::ADDRESS, 'mainnet', 'operations', 1, 'desc', '100', true);

        self::assertSame('99', $page['meta']['nextCursor']);
        self::assertStringContainsString('cursor=100', $response->getRequestUrl());
        self::assertStringContainsString('include_failed=true', $response->getRequestUrl());
    }

    public function testInvalidAddressDoesNotReachTheNetwork(): void
    {
        $http = new MockHttpClient([]);
        $gateway = $this->gateway($http);
        try {
            $gateway->account(str_repeat('G', 56), 'mainnet');
            self::fail('Invalid account must be rejected.');
        } catch (BadRequestHttpException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    private function accountRecord(): array
    {
        return ['account_id' => self::ADDRESS, 'sequence' => '123', 'subentry_count' => 0,
            'balances' => [['asset_type' => 'native', 'balance' => '12.3400000']],
            'signers' => [], 'thresholds' => ['low_threshold' => 1, 'med_threshold' => 1, 'high_threshold' => 1], 'flags' => []];
    }

    private function response(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function gateway(MockHttpClient $http): AccountReadGateway
    {
        return new AccountReadGateway($http, new StellarNetworkResolver(), new NullLogger(), 'https://orion.example', true);
    }
}
