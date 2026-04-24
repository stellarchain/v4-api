<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findActiveByAccountAddress(string $accountAddress, int $networkCode): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.accountAddress = :account')
            ->andWhere('o.network = :network')
            ->andWhere('o.expiresAt > :now')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('account', $accountAddress)
            ->setParameter('network', $networkCode)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('statuses', [Order::STATUS_PENDING, Order::STATUS_AWAITING_VERIFICATION])
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findVisibleByUuid(string $uuid): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findVisibleByUuidAndNetwork(string $uuid, int $networkCode): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.uuid = :uuid')
            ->andWhere('o.network = :network')
            ->setParameter('uuid', $uuid)
            ->setParameter('network', $networkCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
