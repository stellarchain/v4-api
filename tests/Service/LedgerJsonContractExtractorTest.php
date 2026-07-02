<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Stellar\Soroban\LedgerJsonContractExtractor;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;

final class LedgerJsonContractExtractorTest extends TestCase
{
    public function testExtractsInvokeEventsAndStorageFromLedgerJson(): void
    {
        $contractId = 'CDLZH2XWR46NF6EGV2JBEARYBOJQ5II2RZLNFBP63LCXBL524Y7ZNMOC';
        $source = 'GCLWKHHHGBOYXMTSFBJNGCFEWIQ4NZWAGZR6GPB4NLMSLBYW4UP3N4SQ';
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
                                                                    'invoke_contract' => [
                                                                        'contract_address' => ['contract' => $contractId],
                                                                        'function_name' => 'set_price',
                                                                        'args' => [
                                                                            ['address' => $source],
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
                                                                'wasm' => 'c1ad7ecc090b527f5d25198569f6065288e2f8fb35ae9bc511cc8d01d93993be',
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
        self::assertSame('set_price', $tx['invokeCalls'][0]['functionName']);
        self::assertSame(7844, $tx['resourceFeeCharged']);

        self::assertCount(1, $tx['eventsByContract'][$contractId]);
        self::assertSame('transfer', $tx['eventsByContract'][$contractId][0]['eventType']);
        self::assertSame('2001500', $tx['eventsByContract'][$contractId][0]['amountRaw']);

        self::assertCount(1, $tx['storageByContract'][$contractId]);
        self::assertSame('c1ad7ecc090b527f5d25198569f6065288e2f8fb35ae9bc511cc8d01d93993be', $tx['contractMetaByContract'][$contractId]['wasmId']);
        self::assertTrue($tx['contractMetaByContract'][$contractId]['deployed']);
        self::assertSame('contract_instance_created', $tx['contractMetaByContract'][$contractId]['deploymentKind']);
    }
}
