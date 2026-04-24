<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NetworkMetricPoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NetworkMetricPoint>
 */
final class NetworkMetricPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NetworkMetricPoint::class);
    }
}
