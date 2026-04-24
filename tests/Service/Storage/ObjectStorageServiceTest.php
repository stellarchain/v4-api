<?php

declare(strict_types=1);

namespace App\Tests\Service\Storage;

use App\Service\Storage\ObjectStorageService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use PHPUnit\Framework\TestCase;

final class ObjectStorageServiceTest extends TestCase
{
    public function testUploadContentUsesPublicAclAndReturnsCustomPublicUrl(): void
    {
        $client = $this->createMock(S3ClientInterface::class);
        $client->expects(self::once())
            ->method('putObject')
            ->with(self::callback(static function (array $params): bool {
                self::assertSame('uploads', $params['Bucket'] ?? null);
                self::assertSame('labels/sample.txt', $params['Key'] ?? null);
                self::assertSame('hello', $params['Body'] ?? null);
                self::assertSame('text/plain', $params['ContentType'] ?? null);
                self::assertSame('public-read', $params['ACL'] ?? null);
                self::assertSame(['source' => 'test'], $params['Metadata'] ?? null);

                return true;
            }))
            ->willReturn(new Result([]));

        $service = new ObjectStorageService(
            $client,
            'uploads',
            'https://cdn.example.com',
            true
        );

        $url = $service->uploadContent('/labels/sample.txt', 'hello', 'text/plain', ['source' => 'test']);

        self::assertSame('https://cdn.example.com/labels/sample.txt', $url);
    }

    public function testDeleteObjectCallsClient(): void
    {
        $client = $this->createMock(S3ClientInterface::class);
        $client->expects(self::once())
            ->method('deleteObject')
            ->with([
                'Bucket' => 'uploads',
                'Key' => 'labels/old.txt',
            ])
            ->willReturn(new Result([]));

        $service = new ObjectStorageService(
            $client,
            'uploads',
            '',
            false
        );

        $service->deleteObject('/labels/old.txt');
    }
}
