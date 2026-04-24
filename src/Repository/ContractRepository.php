<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\ContractEvent;
use App\Entity\ContractTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    public function findOneByContractId(string $contractId): ?Contract
    {
        return $this->findOneBy(['contractId' => $contractId]);
    }

    public function findOneByContractIdAndNetwork(string $contractId, int $networkCode): ?Contract
    {
        return $this->findOneBy([
            'contractId' => $contractId,
            'network' => $networkCode,
        ]);
    }

    /**
     * @return list<Contract>
     */
    public function findByAssetIssuerAndNetwork(string $assetIssuer, int $networkCode, int $limit = 300): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.assetIssuer = :assetIssuer')
            ->andWhere('c.network = :network')
            ->setParameter('assetIssuer', $assetIssuer)
            ->setParameter('network', $networkCode)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function countEventsByContractId(int $contractDbId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(ce.id)')
            ->from(ContractEvent::class, 'ce')
            ->where('ce.contract = :contractId')
            ->setParameter('contractId', $contractDbId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctEventTransactionsByContractId(int $contractDbId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT ce.txHash)')
            ->from(ContractEvent::class, 'ce')
            ->where('ce.contract = :contractId')
            ->andWhere('ce.txHash IS NOT NULL')
            ->andWhere("ce.txHash <> ''")
            ->setParameter('contractId', $contractDbId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    public function findHostFunctionsByContractId(int $contractDbId): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('ct.hostFunctions')
            ->from(ContractTransaction::class, 'ct')
            ->where('ct.contract = :contractId')
            ->andWhere('ct.hostFunctions IS NOT NULL')
            ->setParameter('contractId', $contractDbId)
            ->getQuery()
            ->getArrayResult();

        $payloads = [];
        foreach ($rows as $row) {
            $value = is_array($row) ? ($row['hostFunctions'] ?? null) : null;
            if (is_string($value) && trim($value) !== '') {
                $payloads[] = $value;
            }
        }

        return $payloads;
    }

//    /**
//     * @return Contract[] Returns an array of Contract objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('c.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Contract
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
