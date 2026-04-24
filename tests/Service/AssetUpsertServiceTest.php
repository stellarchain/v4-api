<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\AssetUpsertService;
use App\Entity\AssetStatistic;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AssetUpsertServiceTest extends TestCase
{
    public function testCreatesAssetWhenMissing(): void
    {
        $payload = [
            'asset' => 'yUSDC-abc-2',
            'created' => 1615892795,
            'toml_info' => [
                'code' => 'yUSDC',
                'issuer' => 'GISSUER',
            ],
        ];

        $repository = $this->createMock(AssetRepository::class);
        $repository->expects(self::once())->method('findOneByAssetKey')->with('yUSDC-abc-2')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->withConsecutive(
                [self::callback(fn (Asset $asset) => $asset instanceof Asset && $asset->getAssetKey() === 'yUSDC-abc-2')],
                [self::isInstanceOf(\App\Entity\AssetStatistic::class)]
            );
        $entityManager->expects(self::once())->method('flush');

        $service = new AssetUpsertService($entityManager, $repository);
        $asset = $service->upsert($payload);

        self::assertSame('yUSDC-abc-2', $asset->getAssetKey());
        self::assertSame('yUSDC', $asset->getCode());
        self::assertSame('GISSUER', $asset->getIssuer());
    }

    public function testUpdatesExistingAsset(): void
    {
        $asset = (new Asset())
            ->setAssetKey('yUSDC-abc-2')
            ->setCode('yUSDC')
            ->setIssuer('GOLD')
            ->setIsNative(false)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $payload = [
            'asset' => 'yUSDC-abc-2',
            'toml_info' => [
                'code' => 'yUSDC',
                'issuer' => 'NEWISSUER',
            ],
        ];

        $repository = $this->createMock(AssetRepository::class);
        $repository->expects(self::once())->method('findOneByAssetKey')->with('yUSDC-abc-2')->willReturn($asset);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(\App\Entity\AssetStatistic::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new AssetUpsertService($entityManager, $repository);
        $result = $service->upsert($payload);

        self::assertSame($asset, $result);
        self::assertSame('NEWISSUER', $asset->getIssuer());
    }
}
