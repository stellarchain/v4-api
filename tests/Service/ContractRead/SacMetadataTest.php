<?php

declare(strict_types=1);

namespace App\Tests\Service\ContractRead;

use App\Service\ContractRead\ContractReadMapper;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use PHPUnit\Framework\TestCase;

final class SacMetadataTest extends TestCase
{
    private const ID = 'CCUMHHN5DS67QN5LMNK2HSAOMBTQDCXRJEGWYNCVAS67E4C7OC36JDL5';
    private const ISSUER = 'GAHZXTMVT5MOOJ6YJG4YMQSU4PGXRRZNJJVB4FUSOLAI3EVQCHZCWRSA';

    public function testMapsSacAssetAndDecodesMetadataFromXdr(): void
    {
        $row = $this->mapper()->contract(self::ID, $this->record());
        self::assertTrue($row['is_sac']);
        self::assertNull($row['wasm_id']);
        self::assertSame('SONESO', $row['asset_code']);
        self::assertSame(self::ISSUER, $row['asset_issuer']);
        self::assertSame(7, $row['orion_sac_metadata']['decimals']);
        self::assertSame('SONESO', $row['orion_sac_metadata']['symbol']);
        self::assertSame(self::ISSUER, $row['orion_sac_metadata']['admin']);
    }

    public function testDoesNotUsePotentiallyConflictingJsonMetadata(): void
    {
        $record = $this->record();
        $record['instance_storage'][0]['val'] = ['decimal' => 18, 'symbol' => 'WRONG'];
        $row = $this->mapper()->contract(self::ID, $record);
        self::assertSame(7, $row['orion_sac_metadata']['decimals']);
        self::assertSame('SONESO', $row['orion_sac_metadata']['symbol']);
    }

    public function testRejectsAnAssetWhoseDerivedSacAddressDoesNotMatch(): void
    {
        $record = $this->record();
        $record['asset_code'] = 'OTHER';
        $this->expectException(\UnexpectedValueException::class);
        $this->mapper()->contract(self::ID, $record);
    }

    private function mapper(): ContractReadMapper
    {
        $mapper = new SorobanScValMapper();

        return new ContractReadMapper(new InvokeContractCallExtractor($mapper), $mapper);
    }

    private function record(): array
    {
        return [
            'contract_id' => self::ID, 'executable_type' => 'stellar_asset',
            'asset_code' => 'SONESO', 'asset_issuer' => self::ISSUER, 'asset_type' => 'credit_alphanum12',
            'instance_storage' => [
                ['key_xdr' => 'AAAADwAAAAhNRVRBREFUQQ==', 'val_xdr' => 'AAAAEQAAAAEAAAADAAAADwAAAAdkZWNpbWFsAAAAAAMAAAAHAAAADwAAAARuYW1lAAAADgAAAD9TT05FU086R0FIWlhUTVZUNU1PT0o2WUpHNFlNUVNVNFBHWFJSWk5KSlZCNEZVU09MQUkzRVZRQ0haQ1dSU0EAAAAADwAAAAZzeW1ib2wAAAAAAA4AAAAGU09ORVNPAAA='],
                ['key_xdr' => 'AAAAEAAAAAEAAAABAAAADwAAAAVBZG1pbgAAAA==', 'val_xdr' => 'AAAAEgAAAAAAAAAAD5vNlZ9Y5yfYSbmGQlTjzXjHLUpqHhaScsCNkrAR8is='],
            ],
        ];
    }
}
