<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Stellar\Soroban\Sep55ContractVerificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Sep55ContractVerificationServiceTest extends TestCase
{
    private Sep55ContractVerificationService $service;

    protected function setUp(): void
    {
        $this->service = new Sep55ContractVerificationService(
            $this->createMock(HttpClientInterface::class)
        );
    }

    public function testExtractsSourceRepoFromSequentialContractMetadataEntries(): void
    {
        $wasm = $this->buildWasmWithContractMeta(
            $this->buildScMetaEntry('rsver', '1.85.0')
            . $this->buildScMetaEntry('source_repo', 'github:stellar/hello-contract')
        );

        self::assertSame(
            'github:stellar/hello-contract',
            $this->service->extractSourceRepoFromWasm($wasm)
        );
    }

    public function testExtractsSourceRepoFromCountPrefixedContractMetadataEntries(): void
    {
        $wasm = $this->buildWasmWithContractMeta(
            pack('N', 1)
            . $this->buildScMetaEntry('source_repo', 'https://github.com/stellar/count-contract')
        );

        self::assertSame(
            'https://github.com/stellar/count-contract',
            $this->service->extractSourceRepoFromWasm($wasm)
        );
    }

    public function testReturnsNullWhenContractMetadataDoesNotContainSourceRepo(): void
    {
        $wasm = $this->buildWasmWithContractMeta(
            $this->buildScMetaEntry('rsver', '1.85.0')
            . $this->buildScMetaEntry('rssdkver', '25.0.1')
        );

        self::assertNull($this->service->extractSourceRepoFromWasm($wasm));
    }

    private function buildWasmWithContractMeta(string $payload): string
    {
        $sectionName = 'contractmetav0';
        $sectionPayload = $this->encodeLeb128Unsigned(strlen($sectionName)) . $sectionName . $payload;

        return "\x00asm\x01\x00\x00\x00"
            . "\x00"
            . $this->encodeLeb128Unsigned(strlen($sectionPayload))
            . $sectionPayload;
    }

    private function buildScMetaEntry(string $key, string $value): string
    {
        return pack('N', 0) . $this->encodeXdrString($key) . $this->encodeXdrString($value);
    }

    private function encodeXdrString(string $value): string
    {
        $paddingLength = (4 - (strlen($value) % 4)) % 4;

        return pack('N', strlen($value)) . $value . str_repeat("\x00", $paddingLength);
    }

    private function encodeLeb128Unsigned(int $value): string
    {
        $encoded = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($value !== 0);

        return $encoded;
    }
}
