<?php

namespace App\Service;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\AssetStatistic;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AssetUpsertService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsert(array $payload, int $networkCode = 1): Asset
    {
        $assetKey = (string) ($payload['asset'] ?? '');
        if ($assetKey === '') {
            throw new BadRequestHttpException('Asset key is missing from payload.');
        }

        $asset = $this->assetRepository->findOneByAssetKey($assetKey, $networkCode) ?? new Asset();
        $now = new \DateTimeImmutable();
        $isNew = $asset->getId() === null;

        if ($isNew) {
            $asset->setAssetKey($assetKey);
            $asset->setNetwork($networkCode);
        }

        $tomlInfo = isset($payload['toml_info']) && is_array($payload['toml_info'])
            ? $payload['toml_info']
            : null;
        $isNative = $assetKey === 'XLM-native';
        $issuer = $tomlInfo['issuer'] ?? null;
        $issuer = is_string($issuer) ? trim($issuer) : null;
        if ($issuer === '') {
            $issuer = null;
        }
        $asset->setTomlInfo($tomlInfo);
        $asset->setCode((string) ($tomlInfo['code'] ?? ''));
        $asset->setIssuer($issuer);
        $asset->setIsNative($isNative);
        $asset->setNetwork($networkCode);
        $asset->setRatingAverage($this->asString($payload['rating']['average'] ?? null));
        if ($isNew) {
            $createdAt = $this->createDateTime($payload['created'] ?? null);
            $asset->setCreatedAt($createdAt);
        }

        $asset->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($asset);
        $statistic = $this->buildStatistic($asset, $payload);
        $this->entityManager->persist($statistic);
        $asset->addStatistic($statistic);
        if ($baseKey = $this->extractBaseKey($asset->getAssetKey())) {
            $this->assetRepository->removeByAssetKeys([$baseKey], $networkCode);
        }
        $this->entityManager->flush();

        return $asset;
    }

    private function buildStatistic(Asset $asset, array $payload): AssetStatistic
    {
        $statistic = new AssetStatistic();
        $statistic->setAsset($asset);
        $statistic->setPrice($this->asString($payload['price'] ?? null));
        $statistic->setSupply($this->asString($payload['supply'] ?? null));
        $statistic->setTrades($this->asInt($payload['trades'] ?? null));
        $statistic->setTradedAmount($this->asInt($payload['traded_amount'] ?? null));
        $statistic->setPayments($this->asInt($payload['payments'] ?? null));
        $statistic->setPaymentsAmount($this->asInt($payload['payments_amount'] ?? null));
        $statistic->setTrustlinesTotal($this->asInt($payload['trustlines']['total'] ?? null));
        $statistic->setTrustlinesAuthorized($this->asInt($payload['trustlines']['authorized'] ?? null));
        $statistic->setTrustlinesFunded($this->asInt($payload['trustlines']['funded'] ?? null));
        $statistic->setRatingAverage($this->asString($payload['rating']['average'] ?? null));
        $statistic->setRecordedAt(new \DateTimeImmutable());
        return $statistic;
    }

    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }

    private function asInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    private function extractBaseKey(string $assetKey): ?string
    {
        if (preg_match('/^(.+)-\d+$/', $assetKey, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function createDateTime(mixed $timestamp): \DateTimeImmutable
    {
        if (is_numeric($timestamp)) {
            return (new \DateTimeImmutable())->setTimestamp((int) $timestamp);
        }

        return new \DateTimeImmutable();
    }
}
