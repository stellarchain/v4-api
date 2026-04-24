<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketAssetSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketAssetSnapshot>
 */
final class MarketAssetSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketAssetSnapshot::class);
    }
}
