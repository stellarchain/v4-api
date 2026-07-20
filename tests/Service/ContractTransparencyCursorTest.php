<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContractTransparency\ContractTransparencyCursor;
use PHPUnit\Framework\TestCase;

final class ContractTransparencyCursorTest extends TestCase
{
    public function testItRoundTripsIdCursor(): void
    {
        $codec = new ContractTransparencyCursor();
        $cursor = $codec->encodeId(12345);

        self::assertSame(12345, $codec->decodeId($cursor));
        self::assertSame(12345, $codec->decodeId('12345'));
        self::assertNull($codec->decodeId('invalid'));
    }

    public function testItRoundTripsActivityCursor(): void
    {
        $codec = new ContractTransparencyCursor();
        $cursor = $codec->encodeActivity([
            'ledger' => 987654,
            'rank' => 2,
            'id' => 42,
        ]);

        self::assertSame([
            'ledger' => 987654,
            'rank' => 2,
            'id' => 42,
        ], $codec->decodeActivity($cursor));
        self::assertNull($codec->decodeActivity('invalid'));
    }
}
