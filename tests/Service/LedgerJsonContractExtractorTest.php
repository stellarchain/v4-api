<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Stellar\Soroban\LedgerJsonContractExtractor;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Util\Hash;
use Soneso\StellarSDK\Xdr\XdrContractIDPreimage;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimage;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimageContractID;

final class LedgerJsonContractExtractorTest extends TestCase
{
    public function testExtractsInvokeEventsAndStorageFromLedgerJson(): void
    {
        $contractId = 'CDLZH2XWR46NF6EGV2JBEARYBOJQ5II2RZLNFBP63LCXBL524Y7ZNMOC';
        $referencedOnlyContractId = 'CBZJ5GIDUCLFDST467SHWBHF7CSPKVRT6NXRMXRM6FIIDMFCUIFOOQ4D';
        $source = 'GCLWKHHHGBOYXMTSFBJNGCFEWIQ4NZWAGZR6GPB4NLMSLBYW4UP3N4SQ';
        $issuer = 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN';
        $txHash = '83f148a802f659b2dcc122a395ed8fe50845d4648c979bd0fe6b504a7975813a';

        $extractor = new LedgerJsonContractExtractor(
            new SorobanContractInspector(new SorobanServerFactory(new StellarNetworkResolver()))
        );

        $ledger = [
            'sequence' => 3382589,
            'ledgerCloseTime' => '1782927703',
            'metadataJson' => [
                'v1' => [
                    'tx_set' => [
                        'v1' => [
                            'phases' => [[
                                'v1' => [
                                    'execution_stages' => [[[
                                        [
                                            'tx' => [
                                                'tx' => [
                                                    'source_account' => $source,
                                                    'fee' => 35014,
                                                    'operations' => [[
                                                        'body' => [
                                                            'invoke_host_function' => [
                                                                'host_function' => [
                                                                    'create_contract' => [
                                                                        'contract_id_preimage' => [
                                                                            'from_asset' => [
                                                                                'credit_alphanum4' => [
                                                                                    'asset_code' => 'USDC',
                                                                                    'issuer' => $issuer,
                                                                                ],
                                                                            ],
                                                                        ],
                                                                        'executable' => 'stellar_asset',
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ], [
                                                        'body' => [
                                                            'invoke_host_function' => [
                                                                'host_function' => [
                                                                    'invoke_contract' => [
                                                                        'contract_address' => ['contract' => $contractId],
                                                                        'function_name' => 'set_price',
                                                                        'args' => [
                                                                            ['address' => $source],
                                                                            ['address' => ['contract' => $referencedOnlyContractId]],
                                                                            ['symbol' => 'XLMUSD'],
                                                                            ['i128' => '2001500'],
                                                                        ],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ]],
                                                ],
                                                'signatures' => [],
                                            ],
                                        ],
                                    ]]],
                                ],
                            ]],
                        ],
                    ],
                    'tx_processing' => [[
                        'result' => [
                            'transaction_hash' => $txHash,
                            'result' => [
                                'fee_charged' => '7944',
                                'result' => ['tx_success' => []],
                            ],
                        ],
                        'tx_apply_processing' => [
                            'v4' => [
                                'operations' => [[
                                    'changes' => [[
                                        'created' => [
                                            'last_modified_ledger_seq' => 3382589,
                                            'data' => [
                                                'contract_data' => [
                                                    'contract' => ['contract' => $contractId],
                                                    'key' => ['ledger_key_contract_instance' => []],
                                                    'durability' => 'persistent',
                                                    'val' => [
                                                        'contract_instance' => [
                                                            'executable' => [
                                                                'stellar_asset' => [],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                    'events' => [[
                                        'ext' => 'v0',
                                        'contract_id' => ['contract' => $contractId],
                                        'type_' => 'contract',
                                        'body' => [
                                            'v0' => [
                                                'topics' => [['symbol' => 'transfer'], ['address' => $source]],
                                                'data' => ['i128' => '2001500'],
                                            ],
                                        ],
                                    ]],
                                ]],
                                'soroban_meta' => [
                                    'ext' => [
                                        'v1' => [
                                            'total_non_refundable_resource_fee_charged' => '7824',
                                            'total_refundable_resource_fee_charged' => '20',
                                        ],
                                    ],
                                    'return_value' => 'void',
                                ],
                                'diagnostic_events' => [[
                                    'event' => [
                                        'ext' => 'v0',
                                        'contract_id' => ['contract' => $referencedOnlyContractId],
                                        'type_' => 'diagnostic',
                                        'body' => [
                                            'v0' => [
                                                'topics' => [['symbol' => 'fn_call']],
                                                'data' => ['void' => []],
                                            ],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $result = $extractor->extract($ledger);

        self::assertSame(3382589, $result['sequence']);
        self::assertSame('2026-07-01 17:41:43', $result['closedAt']);
        self::assertCount(1, $result['transactions']);

        $tx = $result['transactions'][0];
        self::assertSame($txHash, $tx['txHash']);
        self::assertSame($source, $tx['sourceAccount']);
        self::assertSame([$contractId], $tx['contractIds']);
        self::assertSame(['contract' => $referencedOnlyContractId], $tx['invokeCalls'][1]['args'][1]);
        self::assertSame('create_contract', $tx['invokeCalls'][0]['functionName']);
        self::assertSame('set_price', $tx['invokeCalls'][1]['functionName']);
        self::assertSame(7844, $tx['resourceFeeCharged']);

        self::assertCount(1, $tx['eventsByContract'][$contractId]);
        self::assertSame('transfer', $tx['eventsByContract'][$contractId][0]['eventType']);
        self::assertSame('2001500', $tx['eventsByContract'][$contractId][0]['amountRaw']);

        self::assertCount(1, $tx['storageByContract'][$contractId]);
        self::assertTrue($tx['contractMetaByContract'][$contractId]['deployed']);
        self::assertSame('sac_contract_created', $tx['contractMetaByContract'][$contractId]['deploymentKind']);
        self::assertTrue($tx['contractMetaByContract'][$contractId]['isSac']);
        self::assertSame(1, $tx['contractMetaByContract'][$contractId]['executableType']);
        self::assertSame('USDC', $tx['contractMetaByContract'][$contractId]['assetCode']);
        self::assertSame($issuer, $tx['contractMetaByContract'][$contractId]['assetIssuer']);
        self::assertSame($contractId, $tx['contractMetaByContract'][$contractId]['assetAddress']);
    }

    public function testSkipsClassicAssetContractEventsFromContractIndex(): void
    {
        $issuer = 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN';
        $source = 'GCLWKHHHGBOYXMTSFBJNGCFEWIQ4NZWAGZR6GPB4NLMSLBYW4UP3N4SQ';
        $txHash = '31071a94bb153284b453521d9f1e238b7bc62fcb8a1f517f7e9b70615fd85360';
        $contractId = self::deriveSacContractId('USDC', $issuer);

        $extractor = new LedgerJsonContractExtractor(
            new SorobanContractInspector(new SorobanServerFactory(new StellarNetworkResolver()))
        );

        $ledger = [
            'sequence' => 50457446,
            'ledgerCloseTime' => '1708448537',
            'metadataJson' => [
                'v2' => [
                    'tx_set' => [
                        'v1' => [
                            'phases' => [[
                                'v1' => [
                                    'execution_stages' => [[[
                                        [
                                            'tx' => [
                                                'tx' => [
                                                    'source_account' => $source,
                                                    'fee' => 100,
                                                    'operations' => [[
                                                        'body' => [
                                                            'manage_sell_offer' => [
                                                                'selling' => [
                                                                    'alpha_num4' => [
                                                                        'asset_code' => 'USDC',
                                                                        'issuer' => $issuer,
                                                                    ],
                                                                ],
                                                                'buying' => ['native' => []],
                                                                'amount' => '10',
                                                            ],
                                                        ],
                                                    ]],
                                                ],
                                                'signatures' => [],
                                            ],
                                        ],
                                    ]]],
                                ],
                            ]],
                        ],
                    ],
                    'tx_processing' => [[
                        'result' => [
                            'transaction_hash' => $txHash,
                            'result' => [
                                'fee_charged' => '100',
                                'result' => ['tx_success' => []],
                            ],
                        ],
                        'tx_apply_processing' => [
                            'v4' => [
                                'operations' => [[
                                    'events' => [[
                                        'ext' => 'v0',
                                        'contract_id' => ['contract' => $contractId],
                                        'type_' => 'contract',
                                        'body' => [
                                            'v0' => [
                                                'topics' => [['symbol' => 'transfer'], ['address' => $source]],
                                                'data' => ['i128' => '1000000'],
                                            ],
                                        ],
                                    ]],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $result = $extractor->extract($ledger);

        self::assertCount(1, $result['transactions']);
        $tx = $result['transactions'][0];
        self::assertSame([], $tx['contractIds']);
        self::assertCount(1, $tx['assetEventReferences']);
        self::assertSame($contractId, $tx['assetEventReferences'][0]['sacContractId']);
        self::assertSame('USDC', $tx['assetEventReferences'][0]['assetCode']);
        self::assertSame($issuer, $tx['assetEventReferences'][0]['assetIssuer']);
        self::assertSame('USDC:' . $issuer, $tx['assetEventReferences'][0]['assetKey']);
        self::assertSame('transfer', $tx['assetEventReferences'][0]['eventType']);
        self::assertSame('1000000', $tx['assetEventReferences'][0]['amountRaw']);
    }

    public function testSkipsClassicAssetContractEventsFromCanonicalAssetTopic(): void
    {
        $issuer = 'GCSKX37XIELFZN2BYHEGKAHEUYC2STQMBGXP5HNBQPGEYP7JRHBBUBH4';
        $source = 'GACSYCXZT7VW6KJG4BE2NLN7BCNCZZCJCZKGYAQEWPYLQ6NIVDR6HZ3A';
        $destination = 'GBPFWMB6QSZ57AB66UWLFF2P675U37EG7L726DJL4VXX6DWBNVBMJCSR';
        $txHash = 'd7a75fdcb3579fc5fdcdd0803a1848ef12022665bbd05f02d2b5dacc65d6eff9';
        $contractId = self::deriveSacContractId('NNIC', $issuer);

        $extractor = new LedgerJsonContractExtractor(
            new SorobanContractInspector(new SorobanServerFactory(new StellarNetworkResolver()))
        );

        $ledger = [
            'sequence' => 50457425,
            'ledgerCloseTime' => '1708448417',
            'metadataJson' => [
                'v2' => [
                    'tx_set' => [
                        'v1' => [
                            'phases' => [[
                                'v1' => [
                                    'execution_stages' => [[[
                                        [
                                            'tx' => [
                                                'tx' => [
                                                    'source_account' => $source,
                                                    'fee' => 2012,
                                                    'operations' => [[
                                                        'body' => [
                                                            'path_payment_strict_send' => [
                                                                'send_asset' => 'native',
                                                                'dest_asset' => [
                                                                    'credit_alphanum4' => [
                                                                        'asset_code' => 'yXLM',
                                                                        'issuer' => 'GARDNV3Q7YGT4AKSDF25LT32YSCCW4EV22Y2TV3I2PU2MMXJTEDL5T55',
                                                                    ],
                                                                ],
                                                                'path' => [[
                                                                    'credit_alphanum4' => [
                                                                        'asset_code' => 'AFR',
                                                                        'issuer' => 'GBX6YI45VU7WNAAKA3RBFDR3I3UKNFHTJPQ5F6KOOKSGYIAM4TRQN54W',
                                                                    ],
                                                                ]],
                                                            ],
                                                        ],
                                                    ]],
                                                ],
                                                'signatures' => [],
                                            ],
                                        ],
                                    ]]],
                                ],
                            ]],
                        ],
                    ],
                    'tx_processing' => [[
                        'result' => [
                            'transaction_hash' => $txHash,
                            'result' => [
                                'fee_charged' => '2012',
                                'result' => ['tx_success' => []],
                            ],
                        ],
                        'tx_apply_processing' => [
                            'v4' => [
                                'operations' => [[
                                    'events' => [[
                                        'ext' => 'v0',
                                        'contract_id' => ['contract' => $contractId],
                                        'type_' => 'contract',
                                        'body' => [
                                            'v0' => [
                                                'topics' => [
                                                    ['symbol' => 'transfer'],
                                                    ['address' => $source],
                                                    ['address' => $destination],
                                                    ['string' => 'NNIC:' . $issuer],
                                                ],
                                                'data' => ['i128' => '994999990050000'],
                                            ],
                                        ],
                                    ]],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $result = $extractor->extract($ledger);

        self::assertCount(1, $result['transactions']);
        $tx = $result['transactions'][0];
        self::assertSame([], $tx['contractIds']);
        self::assertCount(1, $tx['assetEventReferences']);
        self::assertSame($contractId, $tx['assetEventReferences'][0]['sacContractId']);
        self::assertSame('NNIC', $tx['assetEventReferences'][0]['assetCode']);
        self::assertSame($issuer, $tx['assetEventReferences'][0]['assetIssuer']);
        self::assertSame('NNIC:' . $issuer, $tx['assetEventReferences'][0]['assetKey']);
        self::assertSame('transfer', $tx['assetEventReferences'][0]['eventType']);
        self::assertSame('994999990050000', $tx['assetEventReferences'][0]['amountRaw']);
    }

    public function testSkipsNonSorobanContractEventsWhenAssetMetadataIsMissing(): void
    {
        $source = 'GACSYCXZT7VW6KJG4BE2NLN7BCNCZZCJCZKGYAQEWPYLQ6NIVDR6HZ3A';
        $destination = 'GBPFWMB6QSZ57AB66UWLFF2P675U37EG7L726DJL4VXX6DWBNVBMJCSR';
        $txHash = '8d1b607a774ac5ac58a12aa8b509fed91a757b08fd3f04476f53886a0618ea91';
        $contractId = 'CBLTA2GJSGLOKYVFTKO3RNGR6FPGIKRYHRTKAODESAYVMYAK2JME7GO4';

        $extractor = new LedgerJsonContractExtractor(
            new SorobanContractInspector(new SorobanServerFactory(new StellarNetworkResolver()))
        );

        $ledger = [
            'sequence' => 50457517,
            'ledgerCloseTime' => '1708448782',
            'metadataJson' => [
                'v2' => [
                    'tx_set' => [
                        'v1' => [
                            'phases' => [[
                                'v1' => [
                                    'execution_stages' => [[[
                                        [
                                            'tx' => [
                                                'tx' => [
                                                    'source_account' => $source,
                                                    'fee' => 100,
                                                    'operations' => [[
                                                        'body' => [
                                                            'manage_sell_offer' => [
                                                                'amount' => '10',
                                                            ],
                                                        ],
                                                    ]],
                                                ],
                                                'signatures' => [],
                                            ],
                                        ],
                                    ]]],
                                ],
                            ]],
                        ],
                    ],
                    'tx_processing' => [[
                        'result' => [
                            'transaction_hash' => $txHash,
                            'result' => [
                                'fee_charged' => '100',
                                'result' => ['tx_success' => []],
                            ],
                        ],
                        'tx_apply_processing' => [
                            'v4' => [
                                'operations' => [[
                                    'events' => [[
                                        'ext' => 'v0',
                                        'contract_id' => ['contract' => $contractId],
                                        'type_' => 'contract',
                                        'body' => [
                                            'v0' => [
                                                'topics' => [
                                                    ['symbol' => 'transfer'],
                                                    ['address' => $source],
                                                    ['address' => $destination],
                                                ],
                                                'data' => ['i128' => '143464'],
                                            ],
                                        ],
                                    ]],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $result = $extractor->extract($ledger);

        self::assertSame([], $result['transactions']);
    }

    private static function deriveSacContractId(string $code, ?string $issuer): string
    {
        $asset = $code === 'XLM' && $issuer === null
            ? Asset::native()
            : Asset::createNonNativeAsset($code, (string) $issuer);

        $contractIdPreimage = XdrContractIDPreimage::forAsset($asset->toXdr());
        $hashPreimage = new XdrHashIDPreimage(new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_CONTRACT_ID));
        $hashPreimage->contractID = new XdrHashIDPreimageContractID(
            Hash::generate('Public Global Stellar Network ; September 2015'),
            $contractIdPreimage
        );

        return StrKey::encodeContractId(Hash::generate($hashPreimage->encode()));
    }
}
