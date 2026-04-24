<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    public function findOneByAssetKey(string $assetKey, int $network): ?Asset
    {
        return $this->findOneBy([
            'assetKey' => $assetKey,
            'network' => $network,
        ]);
    }

    public function findFirstByBaseKey(string $baseKey, int $network): ?Asset
    {
        $qb = $this->createQueryBuilder('a');
        $qb
            ->where($qb->expr()->eq('a.assetKey', ':base'))
            ->orWhere($qb->expr()->like('a.assetKey', ':like'))
            ->andWhere('a.network = :network')
            ->setParameter('base', $baseKey)
            ->setParameter('like', $baseKey . '-%')
            ->setParameter('network', $network)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function removeByAssetKeys(array $assetKeys, int $network): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.assetKey IN (:keys)')
            ->andWhere('a.network = :network')
            ->setParameter('keys', $assetKeys)
            ->setParameter('network', $network)
            ->getQuery()
            ->execute();
    }

    public function findXlmNative(int $network = 1): ?Asset
    {
        return $this->findOneBy([
            'assetKey' => 'XLM-native',
            'network' => $network,
        ]);
    }
}
