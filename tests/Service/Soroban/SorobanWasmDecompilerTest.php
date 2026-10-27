<?php

declare(strict_types=1);

namespace App\Tests\Service\Soroban;

use App\Service\Stellar\Soroban\SorobanWasmDecompiler;
use PHPUnit\Framework\TestCase;

final class SorobanWasmDecompilerTest extends TestCase
{
    private string $directory;
    private string|false $previousBinary;
    private string|false $previousTmp;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sc-decompiler-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->previousBinary = getenv('SOROBAN_AUDITOR_BINARY');
        $this->previousTmp = getenv('SOROBAN_AUDITOR_TMP_DIR');
        $binary = $this->directory.'/auditor';
        file_put_contents($binary, <<<'SCRIPT'
#!/usr/bin/env php
<?php
$input = file_get_contents($argv[1]);
if (str_contains($input, 'timeout')) { sleep(30); }
if (str_contains($input, 'failure')) { exit(1); }
file_put_contents($argv[2], str_contains($input, 'oversize') ? str_repeat('x', 2097153) : "pub fn reconstructed() {}\n");
SCRIPT);
        chmod($binary, 0700);
        putenv('SOROBAN_AUDITOR_BINARY='.$binary);
        putenv('SOROBAN_AUDITOR_TMP_DIR='.$this->directory);
    }

    protected function tearDown(): void
    {
        putenv($this->previousBinary === false ? 'SOROBAN_AUDITOR_BINARY' : 'SOROBAN_AUDITOR_BINARY='.$this->previousBinary);
        putenv($this->previousTmp === false ? 'SOROBAN_AUDITOR_TMP_DIR' : 'SOROBAN_AUDITOR_TMP_DIR='.$this->previousTmp);
        foreach (glob($this->directory.'/*') as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public function testReturnsSourceAndRemovesTemporaryFiles(): void
    {
        $source = (new SorobanWasmDecompiler($this->directory))->decompile("\0asm\x01\0\0\0");
        self::assertSame("pub fn reconstructed() {}\n", $source);
        self::assertCount(1, glob($this->directory.'/*'));
    }

    public function testNonzeroExitIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SorobanWasmDecompiler($this->directory))->decompile("\0asm\x01\0\0\0failure");
    }

    public function testOutputSizeIsBounded(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SorobanWasmDecompiler($this->directory))->decompile("\0asm\x01\0\0\0oversize");
    }

    public function testProcessHasATimeoutAndCleansUp(): void
    {
        try {
            (new SorobanWasmDecompiler($this->directory))->decompile("\0asm\x01\0\0\0timeout");
            self::fail('A hung decompiler must time out.');
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            self::assertCount(1, glob($this->directory.'/*'));
        }
    }

    public function testNonWasmInputIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SorobanWasmDecompiler($this->directory))->decompile('invalid');
    }
}
