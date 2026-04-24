<?php

namespace App\Repository;

use App\Entity\ContractSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContractSource>
 */
class ContractSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractSource::class);
    }

    public function findOneByWasmId(string $wasmId): ?ContractSource
    {
        return $this->findOneBy(['wasmId' => $wasmId]);
    }
}
